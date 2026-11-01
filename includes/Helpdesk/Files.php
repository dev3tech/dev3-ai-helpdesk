<?php
/**
 * Private attachment store: a deny-all uploads folder, random names, and signed expiring URLs that are only
 * created for people allowed to see the ticket.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Helpdesk;

use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Files.
 */
class Files {

	const DIR = 'dev3hd-private';

	const EXT_MIME = array(
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'pdf'  => 'application/pdf',
		'txt'  => 'text/plain',
		'log'  => 'text/plain',
		'csv'  => 'text/csv',
		'zip'  => 'application/zip',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	/**
	 * Allowed ext pattern => mime (for wp_check_filetype_and_ext).
	 *
	 * @return array
	 */
	public static function mimes() {
		$mimes = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'pdf'          => 'application/pdf',
			'txt|log'      => 'text/plain',
			'csv'          => 'text/csv',
			'zip'          => 'application/zip',
			'docx'         => self::EXT_MIME['docx'],
			'xlsx'         => self::EXT_MIME['xlsx'],
		);
		/**
		 * Filters allowed attachment types (ext pattern => mime). Only types in Files::EXT_MIME can be served.
		 *
		 * @param array $mimes Mimes.
		 */
		return (array) apply_filters( 'dev3hd_attachment_mimes', $mimes );
	}

	/**
	 * Max bytes per file.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return (int) Settings::get( 'max_file_mb', 5 ) * MB_IN_BYTES;
	}

	/**
	 * Hooks the download handler.
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_serve' ), 1 );
	}

	/**
	 * Base directory (created with deny rules on first use).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Absolute path for a key, or '' when the key is malformed.
	 *
	 * @param string $key Key ("2026/10/abc123.pdf").
	 * @return string
	 */
	public static function path( $key ) {
		$key = (string) $key;
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-zA-Z0-9]{20,40}\.(' . implode( '|', array_keys( self::EXT_MIME ) ) . ')$#', $key ) ) {
			return '';
		}
		return self::base_dir() . '/' . $key;
	}

	/**
	 * Stores an uploaded file (or a local temp file written by the mail importer).
	 *
	 * @param array $file  `$_FILES`-style array (name, tmp_name, size, error).
	 * @param bool  $local True for files the plugin wrote itself (email attachments).
	 * @return array|\WP_Error { key, name, size, mime }
	 */
	public static function store( array $file, $local = false ) {
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'dev3hd_upload', __( 'The file could not be uploaded.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$tmp = (string) $file['tmp_name'];
		if ( ! $local && ! is_uploaded_file( $tmp ) && ! apply_filters( 'dev3hd_allow_local_files', false, $tmp ) ) {
			return new \WP_Error( 'dev3hd_upload', __( 'The file could not be uploaded.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > self::max_bytes() ) {
			/* translators: %s: maximum size, e.g. "5 MB". */
			return new \WP_Error( 'dev3hd_upload_size', sprintf( __( 'Files must be smaller than %s.', 'dev3-ai-helpdesk' ), size_format( self::max_bytes() ) ), array( 'status' => 400 ) );
		}
		$name  = sanitize_file_name( (string) ( $file['name'] ?? 'file' ) );
		$check = wp_check_filetype_and_ext( $tmp, $name, self::mimes() );
		$ext   = strtolower( (string) $check['ext'] );
		$ext   = 'jpeg' === $ext || 'jpe' === $ext ? 'jpg' : $ext;
		if ( ! $check['type'] || ! isset( self::EXT_MIME[ $ext ] ) ) {
			return new \WP_Error( 'dev3hd_upload_type', __( 'This file type is not allowed.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		if ( 0 === strpos( self::EXT_MIME[ $ext ], 'image/' ) && ! @getimagesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'dev3hd_upload_type', __( 'This image could not be read.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$key  = gmdate( 'Y/m' ) . '/' . wp_generate_password( 32, false, false ) . '.' . $ext;
		$dest = self::base_dir() . '/' . $key;
		wp_mkdir_p( dirname( $dest ) );
		if ( ! copy( $tmp, $dest ) ) {
			return new \WP_Error( 'dev3hd_upload', __( 'The file could not be saved.', 'dev3-ai-helpdesk' ), array( 'status' => 500 ) );
		}
		return array(
			'key'  => $key,
			'name' => mb_substr( $name, 0, 120 ),
			'size' => $size,
			'mime' => self::EXT_MIME[ $ext ],
		);
	}

	/**
	 * Stores raw bytes (email attachments).
	 *
	 * @param string $bytes Content.
	 * @param string $name  File name.
	 * @return array|\WP_Error
	 */
	public static function store_bytes( $bytes, $name ) {
		$tmp = wp_tempnam( 'dev3hd-mail' );
		if ( ! $tmp ) {
			return new \WP_Error( 'dev3hd_upload', __( 'The file could not be saved.', 'dev3-ai-helpdesk' ), array( 'status' => 500 ) );
		}
		file_put_contents( $tmp, (string) $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$out = self::store(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
				'error'    => 0,
			),
			true
		);
		wp_delete_file( $tmp );
		return $out;
	}

	/**
	 * Stores the `files` request param(s), respecting the count limit.
	 *
	 * @param array $files Normalised list of `$_FILES`-style arrays.
	 * @return array|\WP_Error Stored attachments.
	 */
	public static function store_many( array $files ) {
		$files = array_values( array_filter( $files, static fn( $f ) => is_array( $f ) && ! empty( $f['tmp_name'] ) ) );
		if ( ! $files ) {
			return array();
		}
		if ( ! Settings::get( 'allow_attachments', true ) ) {
			return new \WP_Error( 'dev3hd_upload_off', __( 'Attachments are not accepted.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$max = (int) Settings::get( 'max_files', 3 );
		if ( count( $files ) > $max ) {
			/* translators: %d: number of files. */
			return new \WP_Error( 'dev3hd_upload_count', sprintf( _n( 'You can attach up to %d file.', 'You can attach up to %d files.', $max, 'dev3-ai-helpdesk' ), $max ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $files as $file ) {
			$stored = self::store( $file );
			if ( is_wp_error( $stored ) ) {
				foreach ( $out as $done ) {
					self::delete( $done['key'] );
				}
				return $stored;
			}
			$out[] = $stored;
		}
		return $out;
	}

	/**
	 * Normalises `$_FILES`-style input: { files: {name:[..], tmp_name:[..]} } or a list.
	 *
	 * @param array $params File params from WP_REST_Request::get_file_params().
	 * @return array
	 */
	public static function normalize( array $params ) {
		$out = array();
		foreach ( $params as $entry ) {
			if ( isset( $entry['name'] ) && is_array( $entry['name'] ) ) {
				foreach ( array_keys( $entry['name'] ) as $i ) {
					$out[] = array(
						'name'     => $entry['name'][ $i ],
						'tmp_name' => $entry['tmp_name'][ $i ] ?? '',
						'error'    => $entry['error'][ $i ] ?? 0,
						'size'     => $entry['size'][ $i ] ?? 0,
					);
				}
			} elseif ( isset( $entry['tmp_name'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $key Key.
	 */
	public static function delete( $key ) {
		$path = self::path( $key );
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Signature for key + expiry.
	 *
	 * @param string $key Key.
	 * @param int    $exp Expiry timestamp.
	 * @return string
	 */
	public static function sign( $key, $exp ) {
		return hash_hmac( 'sha256', $key . '|' . (int) $exp, wp_salt( 'auth' ) . 'dev3hd-files' );
	}

	/**
	 * Signed URL. Only call it for viewers who may see the file.
	 *
	 * @param string $key  Key.
	 * @param string $name Download name.
	 * @param int    $ttl  Seconds.
	 * @return string
	 */
	public static function url( $key, $name = '', $ttl = 6 * HOUR_IN_SECONDS ) {
		if ( '' === self::path( $key ) ) {
			return '';
		}
		$exp = time() + max( 60, (int) $ttl );
		return add_query_arg(
			array(
				'dev3hd_file' => rawurlencode( $key ),
				'n'           => rawurlencode( sanitize_file_name( (string) $name ) ),
				'exp'         => $exp,
				'sig'         => self::sign( $key, $exp ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Attachments with signed URLs for a viewer who may see them.
	 *
	 * @param array $files Stored attachments.
	 * @return array
	 */
	public static function present( array $files ) {
		$out = array();
		foreach ( $files as $file ) {
			if ( empty( $file['key'] ) ) {
				continue;
			}
			$out[] = array(
				'name'  => (string) ( $file['name'] ?? '' ),
				'size'  => (int) ( $file['size'] ?? 0 ),
				'mime'  => (string) ( $file['mime'] ?? '' ),
				'image' => 0 === strpos( (string) ( $file['mime'] ?? '' ), 'image/' ),
				'url'   => self::url( $file['key'], (string) ( $file['name'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Verifies a signed request.
	 *
	 * @param string $key Key.
	 * @param int    $exp Expiry.
	 * @param string $sig Signature.
	 * @param int    $now Now (tests).
	 * @return bool
	 */
	public static function verify( $key, $exp, $sig, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return '' !== self::path( $key ) && (int) $exp >= $now && hash_equals( self::sign( $key, (int) $exp ), (string) $sig );
	}

	/**
	 * Streams a file for a valid signed URL.
	 */
	public function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed URL (HMAC + expiry) replaces the nonce.
		if ( empty( $_GET['dev3hd_file'] ) ) {
			return;
		}
		$key  = sanitize_text_field( rawurldecode( wp_unslash( $_GET['dev3hd_file'] ) ) );
		$exp  = isset( $_GET['exp'] ) ? (int) $_GET['exp'] : 0;
		$sig  = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		$name = isset( $_GET['n'] ) ? sanitize_file_name( rawurldecode( wp_unslash( $_GET['n'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! self::verify( $key, $exp, $sig ) ) {
			status_header( 403 );
			nocache_headers();
			echo esc_html__( 'This link has expired. Open the ticket again to get a new link.', 'dev3-ai-helpdesk' );
			exit;
		}
		$path = self::path( $key );
		if ( ! file_exists( $path ) ) {
			status_header( 404 );
			exit;
		}
		$ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime   = self::EXT_MIME[ $ext ];
		$inline = in_array( $ext, array( 'jpg', 'png', 'gif', 'webp', 'pdf' ), true );
		$name   = '' === $name ? 'attachment.' . $ext : $name;
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $name ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
