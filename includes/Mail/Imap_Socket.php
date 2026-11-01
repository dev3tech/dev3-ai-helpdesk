<?php
/**
 * Socket transport for the IMAP client.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Imap_Socket.
 */
class Imap_Socket implements Imap_Transport {

	/**
	 * Stream.
	 *
	 * @var resource|null
	 */
	private $stream = null;

	/**
	 * Opens the connection.
	 *
	 * @param string $host    Host.
	 * @param int    $port    Port.
	 * @param bool   $ssl     Implicit TLS.
	 * @param int    $timeout Seconds.
	 * @return true|\WP_Error
	 */
	public function open( $host, $port, $ssl, $timeout ) {
		$host = preg_replace( '/[^a-z0-9.\-]/i', '', (string) $host );
		if ( '' === $host ) {
			return new \WP_Error( 'dev3hd_imap_host', __( 'The IMAP host is not valid.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$context = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
					'SNI_enabled'      => true,
					'peer_name'        => $host,
				),
			)
		);
		$errno   = 0;
		$errstr  = '';
		$target  = ( $ssl ? 'ssl://' : 'tcp://' ) . $host . ':' . (int) $port;
		$stream  = @stream_socket_client( $target, $errno, $errstr, max( 5, (int) $timeout ), STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $stream ) {
			/* translators: %s: error text from the system. */
			return new \WP_Error( 'dev3hd_imap_connect', sprintf( __( 'Could not connect to the IMAP server: %s', 'dev3-ai-helpdesk' ), $errstr ? $errstr : (string) $errno ), array( 'status' => 502 ) );
		}
		stream_set_timeout( $stream, max( 5, (int) $timeout ) );
		$this->stream = $stream;
		return true;
	}

	/**
	 * Upgrades to TLS.
	 *
	 * @return true|\WP_Error
	 */
	public function start_tls() {
		if ( ! $this->stream || ! @stream_socket_enable_crypto( $this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'dev3hd_imap_tls', __( 'STARTTLS failed.', 'dev3-ai-helpdesk' ), array( 'status' => 502 ) );
		}
		return true;
	}

	/**
	 * Writes data.
	 *
	 * @param string $data Data.
	 * @return bool
	 */
	public function write( $data ) {
		return $this->stream && false !== fwrite( $this->stream, (string) $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * Reads a line.
	 *
	 * @return string|false
	 */
	public function read_line() {
		return $this->stream ? fgets( $this->stream, 65536 ) : false;
	}

	/**
	 * Reads n bytes.
	 *
	 * @param int $length Bytes.
	 * @return string
	 */
	public function read_bytes( $length ) {
		$out  = '';
		$read = 0;
		while ( $this->stream && $read < $length && ! feof( $this->stream ) ) {
			$chunk = fread( $this->stream, min( 65536, $length - $read ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$out  .= $chunk;
			$read += strlen( $chunk );
		}
		return $out;
	}

	/**
	 * Closes.
	 */
	public function close() {
		if ( $this->stream ) {
			fclose( $this->stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->stream = null;
		}
	}
}
