<?php
/**
 * Plugin settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * Defaults are plain English data (departments, categories): they are read before `init`, so they are not
 * passed through __(); admins rename them in Settings.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk;

use Dev3\AiHelpdesk\Core3\Core\Encryption;
use Dev3\AiHelpdesk\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3hd_settings_defaults / dev3hd_settings_sanitize.
 */
class Settings {

	const SECRET_OPTION = 'dev3hd_mail_secret';

	const PRIORITIES = array( 'low', 'normal', 'high', 'urgent' );

	const NOTIFY_KEYS = array( 'customer_new', 'customer_reply', 'customer_resolved', 'agent_new', 'agent_reply', 'agent_assigned', 'sla_breach' );

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				// General.
				'number_start'         => 1001,
				'guest_tickets'        => true,
				'require_login'        => false,
				'customer_priority'    => false,
				'allow_attachments'    => true,
				'max_files'            => 3,
				'max_file_mb'          => 5,
				'block_list'           => '',
				'agents_see_all'       => false,
				'auto_assign'          => 'none',
				'auto_close_days'      => 7,
				'reopen_on_reply'      => true,
				'accent_color'         => '#2563eb',
				'portal_intro'         => '',
				'kb_slug'              => 'help',
				'departments'          => array(
					array(
						'id'     => 'support',
						'name'   => 'Customer support',
						'email'  => '',
						'agents' => array(),
					),
					array(
						'id'     => 'billing',
						'name'   => 'Billing',
						'email'  => '',
						'agents' => array(),
					),
					array(
						'id'     => 'technical',
						'name'   => 'Technical',
						'email'  => '',
						'agents' => array(),
					),
				),
				'categories'           => array(
					array(
						'id'         => 'general',
						'name'       => 'General question',
						'department' => 'support',
					),
					array(
						'id'         => 'orders',
						'name'       => 'Orders & shipping',
						'department' => 'support',
					),
					array(
						'id'         => 'billing',
						'name'       => 'Billing & refunds',
						'department' => 'billing',
					),
					array(
						'id'         => 'technical',
						'name'       => 'Technical problem',
						'department' => 'technical',
					),
					array(
						'id'         => 'account',
						'name'       => 'Account & login',
						'department' => 'technical',
					),
					array(
						'id'         => 'feedback',
						'name'       => 'Feedback & ideas',
						'department' => 'support',
					),
				),
				// SLA.
				'sla_enabled'          => true,
				'sla'                  => array(
					'low'    => array(
						'first'   => 48,
						'resolve' => 120,
					),
					'normal' => array(
						'first'   => 24,
						'resolve' => 72,
					),
					'high'   => array(
						'first'   => 8,
						'resolve' => 24,
					),
					'urgent' => array(
						'first'   => 2,
						'resolve' => 8,
					),
				),
				'business_hours'       => false,
				'bh_days'              => array( 1, 2, 3, 4, 5 ),
				'bh_start'             => 9,
				'bh_end'               => 17,
				// Emails.
				'from_name'            => '',
				'from_email'           => '',
				'notify'               => array_fill_keys( self::NOTIFY_KEYS, true ),
				'csat_enabled'         => true,
				// Email piping.
				'imap_enabled'         => false,
				'imap_host'            => '',
				'imap_port'            => 993,
				'imap_encryption'      => 'ssl',
				'imap_user'            => '',
				'imap_folder'          => 'INBOX',
				'imap_interval'        => 5,
				'imap_max'             => 20,
				'imap_password_set'    => false,
				'webhook_enabled'      => false,
				'inbound_token'        => '',
				// WooCommerce.
				'woo_panel'            => true,
				'woo_order_field'      => true,
				// DEV3 AI automation.
				'ai_auto_triage'       => true,
				'ai_auto_apply_triage' => false,
				'ai_auto_draft'        => false,
				'ai_auto_send'         => false,
				'ai_send_threshold'    => 0.85,
				'ai_summary_min'       => 6,
				'ai_public_search'     => true,
				'ai_agent_name'        => 'Support assistant',
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$ints  = array(
			'number_start'    => array( 1, 99999999 ),
			'max_files'       => array( 0, 10 ),
			'max_file_mb'     => array( 1, 50 ),
			'auto_close_days' => array( 0, 365 ),
			'bh_start'        => array( 0, 23 ),
			'bh_end'          => array( 1, 24 ),
			'imap_port'       => array( 1, 65535 ),
			'imap_interval'   => array( 1, 60 ),
			'imap_max'        => array( 1, 100 ),
			'ai_summary_min'  => array( 2, 100 ),
		);
		$bools = array( 'guest_tickets', 'require_login', 'customer_priority', 'allow_attachments', 'agents_see_all', 'reopen_on_reply', 'sla_enabled', 'business_hours', 'csat_enabled', 'imap_enabled', 'webhook_enabled', 'woo_panel', 'woo_order_field', 'ai_auto_triage', 'ai_auto_apply_triage', 'ai_auto_draft', 'ai_auto_send', 'ai_public_search' );
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		foreach ( $bools as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		foreach ( array( 'from_name', 'imap_host', 'imap_user', 'imap_folder', 'ai_agent_name' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( wp_unslash( (string) $input[ $key ] ) ), 0, 190 );
			}
		}
		foreach ( array( 'block_list', 'portal_intro' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input[ $key ] ) ), 0, 4000 );
			}
		}
		if ( isset( $input['from_email'] ) ) {
			$email             = sanitize_email( (string) $input['from_email'] );
			$out['from_email'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $input['kb_slug'] ) ) {
			$slug           = sanitize_title( (string) $input['kb_slug'] );
			$out['kb_slug'] = '' === $slug ? 'help' : mb_substr( $slug, 0, 40 );
		}
		if ( isset( $input['auto_assign'] ) && in_array( $input['auto_assign'], array( 'none', 'round_robin' ), true ) ) {
			$out['auto_assign'] = $input['auto_assign'];
		}
		if ( isset( $input['imap_encryption'] ) && in_array( $input['imap_encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
			$out['imap_encryption'] = $input['imap_encryption'];
		}
		if ( isset( $input['accent_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['accent_color'] );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}
		if ( isset( $input['ai_send_threshold'] ) ) {
			$out['ai_send_threshold'] = round( min( 1.0, max( 0.5, (float) $input['ai_send_threshold'] ) ), 2 );
		}
		if ( isset( $input['bh_days'] ) && is_array( $input['bh_days'] ) ) {
			$days           = array_values( array_unique( array_filter( array_map( 'intval', $input['bh_days'] ), static fn( $d ) => $d >= 0 && $d <= 6 ) ) );
			$out['bh_days'] = $days ? $days : array( 1, 2, 3, 4, 5 );
		}
		if ( isset( $input['notify'] ) && is_array( $input['notify'] ) ) {
			foreach ( self::NOTIFY_KEYS as $key ) {
				if ( isset( $input['notify'][ $key ] ) ) {
					$out['notify'][ $key ] = (bool) $input['notify'][ $key ];
				}
			}
		}
		if ( isset( $input['sla'] ) && is_array( $input['sla'] ) ) {
			foreach ( self::PRIORITIES as $p ) {
				foreach ( array( 'first', 'resolve' ) as $k ) {
					if ( isset( $input['sla'][ $p ][ $k ] ) ) {
						$out['sla'][ $p ][ $k ] = round( min( 8760, max( 0, (float) $input['sla'][ $p ][ $k ] ) ), 2 );
					}
				}
			}
		}
		if ( isset( $input['departments'] ) && is_array( $input['departments'] ) ) {
			$out['departments'] = self::clean_departments( $input['departments'] );
		}
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$out['categories'] = self::clean_categories( $input['categories'], $out['departments'] ?? array() );
		}
		if ( array_key_exists( 'imap_password', $input ) ) {
			$pass = (string) $input['imap_password']; // Not unslashed: passwords may contain backslashes.
			if ( false === strpos( $pass, '•' ) ) {
				if ( '' === $pass ) {
					delete_option( self::SECRET_OPTION );
				} else {
					update_option( self::SECRET_OPTION, Encryption::encrypt( $pass ), false );
				}
			}
		}
		unset( $out['imap_password'] );
		$out['imap_password_set'] = '' !== self::imap_password();
		if ( ! empty( $input['regenerate_token'] ) || empty( $out['inbound_token'] ) ) {
			$out['inbound_token'] = wp_generate_password( 32, false, false );
		}
		return $out;
	}

	/**
	 * Cleans the departments list.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function clean_departments( array $rows ) {
		$out  = array();
		$seen = array();
		foreach ( array_slice( $rows, 0, 30 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 80 );
			if ( '' === $name ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$id = '' === $id ? sanitize_key( sanitize_title( $name ) ) : $id;
			$id = substr( '' === $id ? 'dept' : $id, 0, 30 );
			if ( isset( $seen[ $id ] ) ) {
				$id = substr( $id, 0, 26 ) . '-' . count( $out );
			}
			$seen[ $id ] = true;
			$email       = sanitize_email( (string) ( $row['email'] ?? '' ) );
			$out[]       = array(
				'id'     => $id,
				'name'   => $name,
				'email'  => is_email( $email ) ? $email : '',
				'agents' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $row['agents'] ?? array() ) ) ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Cleans the categories list.
	 *
	 * @param array $rows        Rows.
	 * @param array $departments Departments (for valid ids).
	 * @return array
	 */
	public static function clean_categories( array $rows, array $departments ) {
		$dept_ids = wp_list_pluck( $departments ? $departments : (array) self::get( 'departments', array() ), 'id' );
		$out      = array();
		$seen     = array();
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 80 );
			if ( '' === $name ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$id = '' === $id ? sanitize_key( sanitize_title( $name ) ) : $id;
			$id = substr( '' === $id ? 'cat' : $id, 0, 30 );
			if ( isset( $seen[ $id ] ) ) {
				$id = substr( $id, 0, 26 ) . '-' . count( $out );
			}
			$seen[ $id ] = true;
			$dept        = sanitize_key( (string) ( $row['department'] ?? '' ) );
			$out[]       = array(
				'id'         => $id,
				'name'       => $name,
				'department' => in_array( $dept, $dept_ids, true ) ? $dept : '',
			);
		}
		return $out;
	}

	/**
	 * Departments.
	 *
	 * @return array
	 */
	public static function departments() {
		return (array) self::get( 'departments', array() );
	}

	/**
	 * Categories.
	 *
	 * @return array
	 */
	public static function categories() {
		return (array) self::get( 'categories', array() );
	}

	/**
	 * Category ids.
	 *
	 * @return string[]
	 */
	public static function category_ids() {
		return array_values( array_map( 'strval', wp_list_pluck( self::categories(), 'id' ) ) );
	}

	/**
	 * Department ids.
	 *
	 * @return string[]
	 */
	public static function department_ids() {
		return array_values( array_map( 'strval', wp_list_pluck( self::departments(), 'id' ) ) );
	}

	/**
	 * Category label.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function category_name( $id ) {
		foreach ( self::categories() as $cat ) {
			if ( (string) $cat['id'] === (string) $id ) {
				return (string) $cat['name'];
			}
		}
		return '';
	}

	/**
	 * Department label.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function department_name( $id ) {
		$dept = self::department( $id );
		return $dept ? (string) $dept['name'] : '';
	}

	/**
	 * Department row.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function department( $id ) {
		foreach ( self::departments() as $dept ) {
			if ( (string) $dept['id'] === (string) $id ) {
				return $dept;
			}
		}
		return null;
	}

	/**
	 * Default department of a category.
	 *
	 * @param string $category Category id.
	 * @return string
	 */
	public static function category_department( $category ) {
		foreach ( self::categories() as $cat ) {
			if ( (string) $cat['id'] === (string) $category && ! empty( $cat['department'] ) ) {
				return (string) $cat['department'];
			}
		}
		$ids = self::department_ids();
		return $ids ? $ids[0] : '';
	}

	/**
	 * Decrypted IMAP password.
	 *
	 * @return string
	 */
	public static function imap_password() {
		if ( defined( 'DEV3HD_IMAP_PASSWORD' ) && constant( 'DEV3HD_IMAP_PASSWORD' ) ) {
			return (string) constant( 'DEV3HD_IMAP_PASSWORD' );
		}
		$stored = (string) get_option( self::SECRET_OPTION, '' );
		return '' === $stored ? '' : (string) Encryption::decrypt( $stored );
	}

	/**
	 * Inbound webhook secret (created on first use).
	 *
	 * @return string
	 */
	public static function inbound_token() {
		$token = (string) self::get( 'inbound_token', '' );
		if ( '' === $token ) {
			$all   = Settings_Store::update( array() );
			$token = (string) ( $all['inbound_token'] ?? '' );
		}
		return $token;
	}

	/**
	 * Notification switch.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function notify( $key ) {
		$notify = (array) self::get( 'notify', array() );
		return ! isset( $notify[ $key ] ) || (bool) $notify[ $key ];
	}

	/**
	 * Blocked sender check (exact emails or @domain lines).
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public static function is_blocked( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return false;
		}
		$domain = substr( strrchr( $email, '@' ), 1 );
		foreach ( preg_split( '/[\r\n,]+/', strtolower( (string) self::get( 'block_list', '' ) ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( $line === $email || ( '@' === $line[0] && substr( $line, 1 ) === $domain ) || ( false === strpos( $line, '@' ) && $line === $domain ) ) {
				return true;
			}
		}
		return false;
	}
}
