<?php
/**
 * Minimal IMAP4rev1 client over a socket (no ext-imap, which PHP 8.4 removed): LOGIN, SELECT,
 * UID SEARCH UNSEEN, UID FETCH BODY.PEEK[], UID STORE +FLAGS (\Seen), LOGOUT. SSL, STARTTLS or plain.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Imap_Client.
 */
class Imap_Client {

	/**
	 * Transport.
	 *
	 * @var Imap_Transport
	 */
	private $io;

	/**
	 * Tag counter.
	 *
	 * @var int
	 */
	private $tag = 0;

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $opts;

	/**
	 * Constructor.
	 *
	 * @param array               $opts host, port, encryption (ssl|tls|none), user, password, folder, timeout.
	 * @param Imap_Transport|null $io   Transport (tests inject a fake).
	 */
	public function __construct( array $opts, $io = null ) {
		$this->opts = array_merge(
			array(
				'host'       => '',
				'port'       => 993,
				'encryption' => 'ssl',
				'user'       => '',
				'password'   => '',
				'folder'     => 'INBOX',
				'timeout'    => 20,
			),
			$opts
		);
		$this->io   = $io ? $io : new Imap_Socket();
	}

	/**
	 * Connects, logs in and selects the folder.
	 *
	 * @return true|\WP_Error
	 */
	public function connect() {
		$o = $this->opts;
		if ( '' === $o['host'] || '' === $o['user'] ) {
			return new \WP_Error( 'dev3hd_imap_config', __( 'Add the IMAP host and user name first.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$opened = $this->io->open( (string) $o['host'], (int) $o['port'], 'ssl' === $o['encryption'], (int) $o['timeout'] );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}
		$greeting = $this->io->read_line();
		if ( 0 !== strpos( (string) $greeting, '* OK' ) && 0 !== strpos( (string) $greeting, '* PREAUTH' ) ) {
			return $this->fail( __( 'The server did not greet us as an IMAP server.', 'dev3-ai-helpdesk' ) );
		}
		if ( 'tls' === $o['encryption'] ) {
			$res = $this->command( 'STARTTLS' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$tls = $this->io->start_tls();
			if ( is_wp_error( $tls ) ) {
				return $tls;
			}
		}
		$res = $this->command( 'LOGIN ' . self::quote( $o['user'] ) . ' ' . self::quote( $o['password'] ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'dev3hd_imap_login', __( 'The IMAP server refused the user name or password.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$res = $this->command( 'SELECT ' . self::quote( $o['folder'] ) );
		if ( is_wp_error( $res ) ) {
			/* translators: %s: folder name. */
			return new \WP_Error( 'dev3hd_imap_folder', sprintf( __( 'The folder "%s" could not be opened.', 'dev3-ai-helpdesk' ), $o['folder'] ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Unseen message UIDs (oldest first).
	 *
	 * @param int $limit Max.
	 * @return int[]|\WP_Error
	 */
	public function unseen( $limit = 20 ) {
		$res = $this->command( 'UID SEARCH UNSEEN' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$uids = array();
		foreach ( $res['lines'] as $line ) {
			if ( 0 === strpos( $line, '* SEARCH' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $line, 8 ) ) ) as $uid ) {
					if ( ctype_digit( $uid ) ) {
						$uids[] = (int) $uid;
					}
				}
			}
		}
		sort( $uids );
		return array_slice( $uids, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Raw message by UID (does not set \Seen).
	 *
	 * @param int $uid UID.
	 * @return string|\WP_Error
	 */
	public function fetch( $uid ) {
		$res = $this->command( 'UID FETCH ' . (int) $uid . ' BODY.PEEK[]' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res['literals'] ? (string) $res['literals'][0] : '';
	}

	/**
	 * Marks a message seen.
	 *
	 * @param int $uid UID.
	 * @return true|\WP_Error
	 */
	public function mark_seen( $uid ) {
		$res = $this->command( 'UID STORE ' . (int) $uid . ' +FLAGS (\\Seen)' );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Logs out and closes.
	 */
	public function close() {
		$this->command( 'LOGOUT' );
		$this->io->close();
	}

	/**
	 * Sends a tagged command and reads until its completion.
	 *
	 * @param string $command Command.
	 * @return array|\WP_Error { lines, literals }
	 */
	private function command( $command ) {
		++$this->tag;
		$tag = sprintf( 'A%03d', $this->tag );
		if ( false === $this->io->write( $tag . ' ' . $command . "\r\n" ) ) {
			return $this->fail( __( 'Lost the connection to the mail server.', 'dev3-ai-helpdesk' ) );
		}
		$lines    = array();
		$literals = array();
		for ( $guard = 0; $guard < 100000; $guard++ ) {
			$line = $this->io->read_line();
			if ( false === $line || null === $line ) {
				return $this->fail( __( 'The mail server stopped responding.', 'dev3-ai-helpdesk' ) );
			}
			$line = rtrim( (string) $line, "\r\n" );
			if ( preg_match( '/\{(\d+)\}$/', $line, $m ) ) {
				$literals[] = $this->io->read_bytes( (int) $m[1] );
				$lines[]    = $line;
				continue;
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				$status = strtoupper( (string) strtok( substr( $line, strlen( $tag ) + 1 ), ' ' ) );
				if ( 'OK' !== $status ) {
					return new \WP_Error( 'dev3hd_imap_command', trim( substr( $line, strlen( $tag ) + 1 ) ), array( 'status' => 502 ) );
				}
				return array(
					'lines'    => $lines,
					'literals' => $literals,
				);
			}
			$lines[] = $line;
		}
		return $this->fail( __( 'The mail server sent too much data.', 'dev3-ai-helpdesk' ) );
	}

	/**
	 * Error helper.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private function fail( $message ) {
		return new \WP_Error( 'dev3hd_imap', $message, array( 'status' => 502 ) );
	}

	/**
	 * IMAP quoted string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function quote( $value ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
	}
}
