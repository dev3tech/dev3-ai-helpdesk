<?php
/**
 * Byte transport used by the IMAP client (a socket in production, a scripted fake in tests).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Imap_Transport.
 */
interface Imap_Transport {

	/**
	 * Opens the connection.
	 *
	 * @param string $host    Host.
	 * @param int    $port    Port.
	 * @param bool   $ssl     Implicit TLS.
	 * @param int    $timeout Seconds.
	 * @return true|\WP_Error
	 */
	public function open( $host, $port, $ssl, $timeout );

	/**
	 * Upgrades to TLS (STARTTLS).
	 *
	 * @return true|\WP_Error
	 */
	public function start_tls();

	/**
	 * Writes data.
	 *
	 * @param string $data Data.
	 * @return bool
	 */
	public function write( $data );

	/**
	 * Reads one line (with its line end), false on EOF.
	 *
	 * @return string|false
	 */
	public function read_line();

	/**
	 * Reads exactly n bytes.
	 *
	 * @param int $length Bytes.
	 * @return string
	 */
	public function read_bytes( $length );

	/**
	 * Closes.
	 */
	public function close();
}
