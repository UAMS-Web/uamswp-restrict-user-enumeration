<?php
/**
 * V4: _embed on a posts request must not carry the author through.
 *
 * Covers T6.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class Test_UAMSWP_RUE_Embed_Param extends WP_Test_REST_TestCase {

	/** @var int */
	private static $author_id;

	/** @var int */
	private static $post_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id = $factory->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'enumtarget',
				'user_nicename' => 'enumtarget',
				'display_name'  => 'Enum Target',
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
	 * Dispatch /wp/v2/posts and run the embed pass the server would run.
	 *
	 * @return array Serialized response data, embeds resolved.
	 */
	private function embedded_posts() {
		$server   = rest_get_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		return $server->response_to_data( $response, rest_parse_embed_param( 'author' ) );
	}

	/**
	 * T6: the posts themselves still come back.
	 */
	public function test_posts_still_serve_with_embed() {
		$data = $this->embedded_posts();

		$this->assertNotEmpty( $data );
		$this->assertSame( self::$post_id, $data[0]['id'] );
	}

	/**
	 * T6: the embedded author is an error, not a user.
	 */
	public function test_embedded_author_is_an_error_not_user_data() {
		$data = $this->embedded_posts();

		$this->assertArrayHasKey( '_embedded', $data[0] );
		$this->assertArrayHasKey( 'author', $data[0]['_embedded'] );

		$author = $data[0]['_embedded']['author'][0];

		$this->assertArrayHasKey( 'code', $author, 'The embedded author was not an error object.' );
		$this->assertSame( 'rest_forbidden', $author['code'] );
		$this->assertArrayNotHasKey( 'slug', $author );
		$this->assertArrayNotHasKey( 'name', $author );
	}

	/**
	 * T6, the point of it: nothing identifying the author is anywhere in the
	 * serialized response an anonymous caller receives.
	 */
	public function test_embedded_response_contains_no_author_identity() {
		$body = wp_json_encode( $this->embedded_posts() );

		$this->assertStringNotContainsString( 'enumtarget', $body );
		$this->assertStringNotContainsString( 'Enum Target', $body );
	}

	/**
	 * T6, control: without the plugin, _embed hands over the slug. This is the
	 * vector, asserted.
	 */
	public function test_without_the_plugin_embed_leaks_the_author() {
		remove_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users', 10 );

		$body = wp_json_encode( $this->embedded_posts() );

		add_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users', 10, 3 );

		$this->assertStringContainsString( 'enumtarget', $body );
	}

	/**
	 * A logged-in editor still gets the embedded author, which is what the
	 * block editor and the REST-driven parts of wp-admin rely on.
	 */
	public function test_logged_in_embed_still_resolves_the_author() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$author = $this->embedded_posts()[0]['_embedded']['author'][0];

		$this->assertSame( self::$author_id, $author['id'] );
		$this->assertSame( 'enumtarget', $author['slug'] );
	}
}
