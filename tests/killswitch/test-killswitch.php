<?php
/**
 * FR5: the wp-config.php kill switch.
 *
 * Covers T12. Runs under tests/killswitch/phpunit.xml.dist, whose bootstrap defines
 * UAMSWP_RUE_DISABLE before the plugin file is included, because that is when
 * the decision is made and a constant cannot be undefined afterwards.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class Test_UAMSWP_RUE_Kill_Switch extends WP_Test_REST_TestCase {

	/** @var int */
	private static $author_id;

	/** @var int */
	private static $post_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id = $factory->user->create(
			array(
				'role'          => 'author',
				'user_nicename' => 'enumtarget',
			)
		);

		self::$post_id = $factory->post->create(
			array(
				'post_author' => self::$author_id,
				'post_status' => 'publish',
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();

		// Every install in the estate uses pretty permalinks; author archives
		// (and so the author_url oEmbed leaks) only exist when they do.
		$this->set_permalink_structure( '/%postname%/' );

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( 0 );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * The premise: this process really is the disabled one, and the plugin
	 * really did load. A test that passed because the file was never included
	 * would prove nothing.
	 */
	public function test_the_plugin_is_loaded_and_disabled() {
		$this->assertTrue( defined( 'UAMSWP_RUE_VERSION' ), 'The plugin file was not loaded.' );
		$this->assertTrue( function_exists( 'uamswp_rue_bootstrap' ) );
		$this->assertTrue( uamswp_rue_disabled() );
	}

	/**
	 * T12: no hooks registered.
	 */
	public function test_no_hooks_are_registered() {
		$this->assertFalse( has_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users' ) );
		$this->assertFalse( has_action( 'template_redirect', 'uamswp_rue_block_author_query' ) );
		$this->assertFalse( has_filter( 'wp_sitemaps_add_provider', 'uamswp_rue_remove_users_sitemap' ) );
		$this->assertFalse( has_filter( 'oembed_response_data', 'uamswp_rue_scrub_oembed_author' ) );
	}

	/**
	 * T12: with the switch thrown, T1 is back to 200. This is the rollback
	 * behaving as the deployment plan says it will.
	 */
	public function test_rest_users_is_served_again() {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'enumtarget', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * T12, continued: the other three vectors are back as well, so the switch
	 * is a whole-plugin rollback and not a partial one.
	 */
	public function test_users_sitemap_provider_is_registered_again() {
		$this->assertInstanceOf(
			'WP_Sitemaps_Users',
			wp_sitemaps_get_server()->registry->get_provider( 'users' )
		);
	}

	public function test_oembed_names_the_author_again() {
		$data = get_oembed_response_data( self::$post_id, 600 );

		$this->assertStringContainsString( 'enumtarget', $data['author_url'] );
	}

	public function test_author_query_is_not_intercepted() {
		$this->go_to( home_url( '/?author=' . self::$author_id ) );

		$canonical_ran = false;
		add_filter(
			'redirect_canonical',
			static function ( $url ) use ( &$canonical_ran ) {
				$canonical_ran = true;
				return $url;
			}
		);

		UAMSWP_RUE_Redirect_Exception::intercept();

		try {
			do_action( 'template_redirect' );
			$location = null;
		} catch ( UAMSWP_RUE_Redirect_Exception $e ) {
			$location = $e->location;
		}

		$this->assertTrue( $canonical_ran );
		$this->assertStringContainsString( 'enumtarget', (string) $location );
	}
}
