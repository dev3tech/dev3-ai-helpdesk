<?php
/**
 * Small RFC 822 / MIME parser: headers (folded, encoded words), multipart trees, quoted-printable and
 * base64, charsets, attachments. Produces the normalised message array used by Inbound.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Mime_Parser.
 */
class Mime_Parser {

	const MAX_DEPTH = 6;

	/**
	 * Parses a raw message.
	 *
	 * @param string $raw Raw RFC 822 message.
	 * @return array { from_email, from_name, to, subject, text, html, message_id, in_reply_to, references[], headers{}, attachments[{name,mime,content}] }
	 */
	public static function parse( $raw ) {
		$raw                 = str_replace( "\r\n", "\n", (string) $raw );
		list( $head, $body ) = self::split( $raw );
		$headers             = self::headers( $head );
		$msg                 = array(
			'text'        => '',
			'html'        => '',
			'attachments' => array(),
		);
		self::walk( $headers, $body, $msg, 0 );
		$from = self::address( $headers['from'] ?? '' );
		return array(
			'from_email'  => $from['email'],
			'from_name'   => $from['name'],
			'to'          => self::address( $headers['to'] ?? '' )['email'],
			'subject'     => self::decode_header( $headers['subject'] ?? '' ),
			'text'        => $msg['text'],
			'html'        => $msg['html'],
			'message_id'  => self::message_id( $headers['message-id'] ?? '' ),
			'in_reply_to' => self::message_id( $headers['in-reply-to'] ?? '' ),
			'references'  => self::message_ids( $headers['references'] ?? '' ),
			'headers'     => $headers,
			'attachments' => $msg['attachments'],
		);
	}

	/**
	 * Splits head and body at the first blank line.
	 *
	 * @param string $raw Raw (LF line ends).
	 * @return array [ head, body ]
	 */
	private static function split( $raw ) {
		$pos = strpos( $raw, "\n\n" );
		if ( false === $pos ) {
			return array( $raw, '' );
		}
		return array( substr( $raw, 0, $pos ), substr( $raw, $pos + 2 ) );
	}

	/**
	 * Parses (unfolds) headers into lowercase keys. Repeated headers keep the first value.
	 *
	 * @param string $head Header block.
	 * @return array
	 */
	public static function headers( $head ) {
		$out   = array();
		$lines = preg_split( '/\n(?![ \t])/', (string) $head );
		foreach ( $lines as $line ) {
			$line = preg_replace( '/\n[ \t]+/', ' ', $line );
			$pos  = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$key = strtolower( trim( substr( $line, 0, $pos ) ) );
			if ( '' !== $key && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = trim( substr( $line, $pos + 1 ) );
			}
		}
		return $out;
	}

	/**
	 * Walks a MIME part.
	 *
	 * @param array  $headers Part headers.
	 * @param string $body    Part body.
	 * @param array  $msg     Accumulator (by reference).
	 * @param int    $depth   Depth.
	 */
	private static function walk( array $headers, $body, array &$msg, $depth ) {
		$type   = self::param_header( $headers['content-type'] ?? 'text/plain' );
		$mime   = strtolower( $type['value'] ? $type['value'] : 'text/plain' );
		$disp   = self::param_header( $headers['content-disposition'] ?? '' );
		$name   = self::decode_header( $disp['params']['filename'] ?? ( $type['params']['name'] ?? '' ) );
		$encode = strtolower( trim( $headers['content-transfer-encoding'] ?? '' ) );
		if ( 0 === strpos( $mime, 'multipart/' ) && $depth < self::MAX_DEPTH ) {
			$boundary = (string) ( $type['params']['boundary'] ?? '' );
			if ( '' === $boundary ) {
				return;
			}
			foreach ( self::parts( $body, $boundary ) as $part ) {
				list( $ph, $pb ) = self::split( $part );
				self::walk( self::headers( $ph ), $pb, $msg, $depth + 1 );
			}
			return;
		}
		$content = self::decode_body( $body, $encode );
		$is_att  = 'attachment' === strtolower( $disp['value'] ) || ( '' !== $name && ! in_array( $mime, array( 'text/plain', 'text/html' ), true ) );
		if ( 'message/rfc822' === $mime && ! $is_att ) {
			$is_att = true;
			$name   = '' === $name ? 'message.eml' : $name;
		}
		if ( $is_att ) {
			if ( count( $msg['attachments'] ) < 10 ) {
				$msg['attachments'][] = array(
					'name'    => '' === $name ? 'attachment' : $name,
					'mime'    => $mime,
					'content' => $content,
				);
			}
			return;
		}
		$charset = (string) ( $type['params']['charset'] ?? '' );
		$content = self::to_utf8( $content, $charset );
		if ( 'text/html' === $mime ) {
			if ( '' === $msg['html'] ) {
				$msg['html'] = $content;
			}
		} elseif ( 0 === strpos( $mime, 'text/' ) && '' === $msg['text'] ) {
			$msg['text'] = $content;
		}
	}

	/**
	 * Splits a multipart body.
	 *
	 * @param string $body     Body.
	 * @param string $boundary Boundary.
	 * @return string[]
	 */
	private static function parts( $body, $boundary ) {
		$chunks = explode( "\n--" . $boundary, "\n" . $body );
		array_shift( $chunks ); // Preamble.
		$out = array();
		foreach ( $chunks as $chunk ) {
			if ( 0 === strpos( $chunk, '--' ) ) {
				break; // Closing delimiter.
			}
			$out[] = ltrim( preg_replace( '/^[ \t]*\n/', '', $chunk, 1 ) );
		}
		return $out;
	}

	/**
	 * Header value with parameters: `text/plain; charset="utf-8"`.
	 *
	 * @param string $value Value.
	 * @return array { value, params }
	 */
	public static function param_header( $value ) {
		$parts  = preg_split( '/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', (string) $value );
		$main   = trim( (string) array_shift( $parts ) );
		$params = array();
		foreach ( $parts as $part ) {
			$pos = strpos( $part, '=' );
			if ( false === $pos ) {
				continue;
			}
			$key = strtolower( trim( substr( $part, 0, $pos ) ) );
			$val = trim( trim( substr( $part, $pos + 1 ) ), '"' );
			if ( '*' === substr( $key, -1 ) ) { // RFC 2231: filename*=utf-8''na%20me.pdf.
				$key = rtrim( $key, '*' );
				if ( preg_match( "/^([^']*)'[^']*'(.*)$/", $val, $m ) ) {
					$val = self::to_utf8( rawurldecode( $m[2] ), $m[1] );
				}
			}
			$params[ $key ] = $val;
		}
		return array(
			'value'  => $main,
			'params' => $params,
		);
	}

	/**
	 * Decodes a transfer encoding.
	 *
	 * @param string $body     Body.
	 * @param string $encoding Encoding.
	 * @return string
	 */
	private static function decode_body( $body, $encoding ) {
		if ( 'base64' === $encoding ) {
			return (string) base64_decode( preg_replace( '/\s+/', '', $body ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 'quoted-printable' === $encoding ) {
			return quoted_printable_decode( str_replace( "\n", "\r\n", $body ) );
		}
		return $body;
	}

	/**
	 * Converts to UTF-8.
	 *
	 * @param string $text    Text.
	 * @param string $charset Charset.
	 * @return string
	 */
	public static function to_utf8( $text, $charset ) {
		$charset = strtoupper( trim( (string) $charset ) );
		$text    = str_replace( "\r\n", "\n", (string) $text );
		if ( '' === $charset || 'UTF-8' === $charset || 'US-ASCII' === $charset ) {
			return wp_check_invalid_utf8( $text, true );
		}
		if ( function_exists( 'mb_convert_encoding' ) && in_array( $charset, array_map( 'strtoupper', mb_list_encodings() ), true ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', $charset );
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( $charset, 'UTF-8//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $out ) {
				return $out;
			}
		}
		return wp_check_invalid_utf8( $text, true );
	}

	/**
	 * Decodes RFC 2047 encoded words.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	public static function decode_header( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '=?' ) ) {
			return trim( $value );
		}
		$value = preg_replace( '/\?=\s+=\?/', '?==?', $value );
		$out   = preg_replace_callback(
			'/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
			static function ( $m ) {
				$text = 'B' === strtoupper( $m[2] )
					? base64_decode( $m[3] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					: quoted_printable_decode( str_replace( '_', ' ', $m[3] ) );
				return self::to_utf8( (string) $text, $m[1] );
			},
			$value
		);
		return trim( (string) $out );
	}

	/**
	 * First address in a header: `"Ada L" <ada@example.com>`.
	 *
	 * @param string $value Header.
	 * @return array { name, email }
	 */
	public static function address( $value ) {
		$value = self::decode_header( (string) $value );
		$name  = '';
		$email = '';
		if ( preg_match( '/^(.*?)<([^>]+)>/', $value, $m ) ) {
			$name  = trim( trim( $m[1] ), '"\' ' );
			$email = trim( $m[2] );
		} elseif ( preg_match( '/[^\s<>,;"]+@[^\s<>,;"]+/', $value, $m ) ) {
			$email = $m[0];
		}
		$email = sanitize_email( $email );
		return array(
			'name'  => sanitize_text_field( $name ),
			'email' => is_email( $email ) ? strtolower( $email ) : '',
		);
	}

	/**
	 * Single Message-ID without brackets.
	 *
	 * @param string $value Header.
	 * @return string
	 */
	public static function message_id( $value ) {
		$ids = self::message_ids( $value );
		return $ids ? $ids[0] : '';
	}

	/**
	 * Message-IDs in a header.
	 *
	 * @param string $value Header.
	 * @return string[]
	 */
	public static function message_ids( $value ) {
		preg_match_all( '/<([^<>\s]+)>/', (string) $value, $m );
		$ids = $m[1];
		if ( ! $ids && '' !== trim( (string) $value ) && false === strpos( (string) $value, ' ' ) ) {
			$ids = array( trim( (string) $value, '<> ' ) );
		}
		return array_values( array_map( static fn( $id ) => mb_substr( $id, 0, 250 ), $ids ) );
	}

	/**
	 * HTML to readable text (for HTML-only mail).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', '', (string) $html );
		$html = preg_replace( '#<blockquote[^>]*>.*?</blockquote>#is', '', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|li|tr|h[1-6])>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		return trim( preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text ) );
	}

	/**
	 * Keeps the new part of a reply: cuts at our marker, "On … wrote:", Outlook headers and quoted lines.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_quotes( $text ) {
		$text = str_replace( "\r\n", "\n", (string) $text );
		$cuts = array(
			'/^.*##- .{0,80} -##.*$/m',
			'/^\s*On .{3,200}wrote:\s*$/mi',
			'/^\s*On .{3,120}\n.{0,120}wrote:\s*$/mi',
			'/^\s*Le .{3,200}a écrit\s*:\s*$/mi',
			'/^\s*Am .{3,200}schrieb .{0,120}:\s*$/mi',
			'/^\s*-{2,}\s*Original Message\s*-{2,}\s*$/mi',
			'/^\s*_{10,}\s*$/m',
			'/^\s*From: .+\n\s*(Sent|Date): .+$/mi',
		);
		foreach ( $cuts as $re ) {
			if ( preg_match( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$text = substr( $text, 0, $m[0][1] );
			}
		}
		$sig = strrpos( $text, "\n-- \n" );
		if ( false !== $sig ) {
			$text = substr( $text, 0, $sig ); // Drop a trailing "-- " signature block.
		}
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( 0 === strpos( ltrim( $line ), '>' ) ) {
				continue;
			}
			$lines[] = rtrim( $line );
		}
		return trim( implode( "\n", $lines ) );
	}
}
