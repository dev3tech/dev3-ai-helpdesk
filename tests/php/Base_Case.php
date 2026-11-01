<?php
/**
 * Shared set-up: clean tables and settings, a fake Gemini (pre_http_request), mail capture, helpers.
 *
 * @package Dev3\AiHelpdesk
 */

use Dev3\AiHelpdesk\Ai\Prompts_Library;
use Dev3\AiHelpdesk\Core3\Api\Ai;
use Dev3\AiHelpdesk\Core3\Core\Cache;
use Dev3\AiHelpdesk\Core3\Core\Schema;
use Dev3\AiHelpdesk\Core3\Core\Settings_Store;
use Dev3\AiHelpdesk\Core3\Data\Usage_Repository;
use Dev3\AiHelpdesk\Data\Tables;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Roles;

abstract class Dev3hd_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake replies: url substring => [ status, body ] or callable.
	 *
	 * @var array
	 */
	protected $http = array();

	/**
	 * Captured mails.
	 *
	 * @var array
	 */
	protected $mails = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3HD_DB_VERSION );
		Roles::install();
		global $wpdb;
		foreach ( Tables::NAMES as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore
		}
		foreach ( Schema::tables() as $t ) {
			$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		}
		update_option( Settings_Store::OPTION, array() );
		delete_option( 'dev3hd_mail_secret' );
		delete_option( 'dev3hd_round_robin' );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Prompts_Library::register();
		Ai::$client     = null;
		$this->requests = array();
		$this->http     = array();
		$this->mails    = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3hd_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3hd_public_rate_limit', array( $this, 'no_limit' ) );
		add_filter( 'dev3hd_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'dev3hd_allow_local_files', '__return_true' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3hd_public_rate_limit', array( $this, 'no_limit' ) );
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		remove_filter( 'dev3hd_allow_local_files', '__return_true' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function no_limit() {
		return 10000;
	}

	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Mail subjects so far.
	 *
	 * @return string[]
	 */
	protected function subjects() {
		return array_map( static fn( $m ) => (string) $m['subject'], $this->mails );
	}

	/**
	 * Mails sent to an address.
	 *
	 * @param string $to Address.
	 * @return array
	 */
	protected function mails_to( $to ) {
		return array_values( array_filter( $this->mails, static fn( $m ) => in_array( $to, (array) $m['to'], true ) ) );
	}

	protected function ai_on( array $settings = array() ) {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) + $settings );
		Settings_Store::flush();
	}

	protected function settings( array $settings ) {
		Settings_Store::update( $settings );
		Settings_Store::flush();
	}

	/**
	 * Gemini reply with structured JSON.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 500,
					'candidatesTokenCount' => 120,
					'totalTokenCount'      => 620,
				),
			),
		);
	}

	/**
	 * Fake embeddings: each text maps to a vector by keyword so similarity is predictable.
	 *
	 * @param array $topics keyword => axis index.
	 * @return callable
	 */
	protected function embeddings( array $topics ) {
		return static function ( $url, $body ) use ( $topics ) {
			$out = array();
			foreach ( (array) $body['requests'] as $req ) {
				$text = strtolower( (string) $req['content']['parts'][0]['text'] );
				$vec  = array_fill( 0, 8, 0.01 );
				foreach ( $topics as $word => $axis ) {
					if ( false !== strpos( $text, $word ) ) {
						$vec[ $axis ] += 1.0;
					}
				}
				$out[] = array( 'values' => $vec );
			}
			return array( 200, array( 'embeddings' => $out ) );
		};
	}

	public function fake_http( $pre, $args, $url ) {
		$body             = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
		$this->requests[] = array(
			'url'  => $url,
			'body' => $body,
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $body );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	protected function sent( $needle ) {
		return array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], $needle ) ) );
	}

	/**
	 * Creates a ticket.
	 *
	 * @param array $over Overrides.
	 * @return int
	 */
	protected function ticket( array $over = array() ) {
		$id = Service::create_ticket(
			array_merge(
				array(
					'subject' => 'Refund not received',
					'message' => 'I returned my order two weeks ago and still have no refund.',
					'email'   => 'grace@example.com',
					'name'    => 'Grace Hopper',
				),
				$over
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Help article.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $cat     Category name.
	 * @return int
	 */
	protected function article( $title, $content, $cat = '' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'dev3hd_article',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		if ( $cat ) {
			wp_set_object_terms( $id, $cat, 'dev3hd_kb_cat' );
		}
		return $id;
	}

	/**
	 * A small real file as an uploaded-file array.
	 *
	 * @param string $name Name.
	 * @param string $kind jpg|pdf|php.
	 * @return array
	 */
	protected function upload( $name = 'photo.jpg', $kind = 'jpg' ) {
		$path = wp_tempnam( 'dev3hd-test' ) . '.' . $kind;
		if ( 'jpg' === $kind ) {
			$img = imagecreatetruecolor( 40, 30 );
			imagefill( $img, 0, 0, imagecolorallocate( $img, 200, 30, 30 ) );
			imagejpeg( $img, $path );
			imagedestroy( $img );
		} elseif ( 'pdf' === $kind ) {
			file_put_contents( $path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF" ); // phpcs:ignore
		} else {
			file_put_contents( $path, '<?php echo 1;' ); // phpcs:ignore
		}
		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => filesize( $path ),
		);
	}

	/**
	 * REST request helper.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under dev3hd/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send nonce.
	 * @param array  $files  File params.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true, array $files = array() ) {
		$req = new WP_REST_Request( $method, '/dev3hd/v1/' . ltrim( $route, '/' ) );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} elseif ( $files ) {
			$req->set_body_params( $params );
			$req->set_file_params( $files );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return rest_do_request( $req );
	}

	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	protected function agent( array $departments = array() ) {
		$id = self::factory()->user->create( array( 'role' => 'dev3hd_agent' ) );
		if ( $departments ) {
			$depts = Dev3\AiHelpdesk\Settings::departments();
			foreach ( $depts as &$dept ) {
				if ( in_array( $dept['id'], $departments, true ) ) {
					$dept['agents'][] = $id;
				}
			}
			unset( $dept );
			$this->settings( array( 'departments' => $depts ) );
		}
		return $id;
	}
}
