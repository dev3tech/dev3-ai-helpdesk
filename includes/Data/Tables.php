<?php
/**
 * Plugin tables (tickets, replies, canned replies), added to the core dbDelta run.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + schema.
 */
class Tables {

	const NAMES = array( 'tickets', 'replies', 'canned' );

	/**
	 * Table name.
	 *
	 * @param string $name tickets|replies|canned.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3hd_' . $name;
	}

	/**
	 * Adds CREATE TABLE statements (dev3hd_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$tickets = self::name( 'tickets' );
		$replies = self::name( 'replies' );
		$canned  = self::name( 'canned' );
		$sql[]   = "CREATE TABLE {$tickets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			number bigint(20) unsigned NOT NULL DEFAULT 0,
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'new',
			priority varchar(10) NOT NULL DEFAULT 'normal',
			department varchar(30) NOT NULL DEFAULT '',
			category varchar(30) NOT NULL DEFAULT '',
			agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			channel varchar(12) NOT NULL DEFAULT 'portal',
			access_key char(32) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tags varchar(255) NOT NULL DEFAULT '',
			sentiment varchar(12) NOT NULL DEFAULT '',
			ai longtext NOT NULL,
			ai_state varchar(12) NOT NULL DEFAULT 'none',
			sla_first_due datetime NULL DEFAULT NULL,
			sla_resolve_due datetime NULL DEFAULT NULL,
			sla_paused_at datetime NULL DEFAULT NULL,
			sla_breached tinyint(3) unsigned NOT NULL DEFAULT 0,
			first_response_at datetime NULL DEFAULT NULL,
			last_reply_at datetime NULL DEFAULT NULL,
			last_reply_by varchar(12) NOT NULL DEFAULT 'customer',
			resolved_at datetime NULL DEFAULT NULL,
			closed_at datetime NULL DEFAULT NULL,
			rating tinyint(3) unsigned NOT NULL DEFAULT 0,
			rating_comment text NOT NULL,
			rated_at datetime NULL DEFAULT NULL,
			message_id varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY status_updated (status,updated_at),
			KEY agent_status (agent_id,status),
			KEY department (department),
			KEY email (email),
			KEY customer_id (customer_id),
			KEY created (created_at)
		) {$charset};";
		$sql[]   = "CREATE TABLE {$replies} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(10) NOT NULL DEFAULT 'reply',
			author varchar(10) NOT NULL DEFAULT 'customer',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			attachments text NOT NULL,
			via varchar(10) NOT NULL DEFAULT 'web',
			message_id varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id,id),
			KEY message_id (message_id(191))
		) {$charset};";
		$sql[]   = "CREATE TABLE {$canned} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(190) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			department varchar(30) NOT NULL DEFAULT '',
			uses int(10) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::NAMES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::name( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}
}
