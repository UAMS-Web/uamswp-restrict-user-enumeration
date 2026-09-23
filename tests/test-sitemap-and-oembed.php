<?php
/**
 * FR3 and FR4: the users sitemap is gone, and oEmbed names the site instead of
 * the author.
 *
 * Covers T10 and T11.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class Test_UAMSWP_RUE_Sitemap_And_Oembed extends WP_UnitTestCase {

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
				'post_title'  => 'A post with an author',
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();

		// author_url is only an author archive URL when author archives have
		// one, so this test class cannot rely on another having set it.
		$this->set_permalink_structure( '/%postname%/' );
	}

	// ---- FR3 ------------------------------------------------------------

	/**
	 * T10: the users provider is not registered.
	 */
	public function test_users_sitemap_provider_is_not_registered() {
		$registry = wp_sitemaps_get_server()->registry;

		$this->assertNull( $registry->get_provider( 'users' ) );
		$this->assertArrayNotHasKey( 'users', $registry->get_providers() );
	}

	/**
	 * T10, continued: the providers that are not users survived.
	 */
	public function test_other_sitemap_providers_are_untouched() {
		$providers = wp_sitemaps_get_server()->registry->get_providers();

		$this->assertArrayHasKey( 'posts', $providers );
		$this->assertArrayHasKey( 'taxonomies', $providers );
	}

	/**
	 * T10, continued: nothing in the sitemap index points at users.
	 */
	public function test_sitemap_index_does_not_list_users() {
		$entries = wp_sitemaps_get_server()->index->get_sitemap_list();
		$locs    = wp_list_pluck( $entries, 'loc' );

		foreach ( $locs as $loc ) {
			$this->assertStringNotContainsString( 'users', $loc );
		}
	}

	/**
	 * FR3, at the filter: the callback answers exactly one provider name.
	 */
	public function test_provider_filter_only_drops_users() {
		$provider = new WP_Sitemaps_Posts();

		$this->assertFalse( uamswp_rue_remove_users_sitemap( $provider, 'users' ) );
		$this->assertSame( $provider, uamswp_rue_remove_users_sitemap( $provider, 'posts' ) );
		$this->assertSame( $provider, uamswp_rue_remove_users_sitemap( $provider, 'taxonomies' ) );
	}

	// ---- FR4 ------------------------------------------------------------

	/**
	 * T11: oEmbed names the site, not the author.
	 */
	public function test_oembed_author_fields_name_the_site() {
		$data = get_oembed_response_data( self::$post_id, 600 );

		$this->assertSame( get_bloginfo( 'name' ), $data['author_name'] );
		$this->assertSame( home_url( '/' ), $data['author_url'] );
	}

	/**
	 * T11, continued: neither the display name nor the slug survives anywhere
	 * in the response, including the author archive URL.
	 */
	public function test_oembed_response_contains_no_author_identity() {
		$body = wp_json_encode( get_oembed_response_data( self::$post_id, 600 ) );

		$this->assertStringNotContainsString( 'Enum Target', $body );
		$this->assertStringNotContainsString( 'enumtarget', $body );
	}

	/**
	 * T11, control: core really does put the author in there.
	 */
	public function test_without_the_plugin_oembed_leaks_the_author() {
		remove_filter( 'oembed_response_data', 'uamswp_rue_scrub_oembed_author', 10 );

		$data = get_oembed_response_data( self::$post_id, 600 );

		add_filter( 'oembed_response_data', 'uamswp_rue_scrub_oembed_author', 10 );

		$this->assertSame( 'Enum Target', $data['author_name'] );
		$this->assertStringContainsString( 'enumtarget', $data['author_url'] );
	}

	/**
	 * FR4 leaves the rest of the oEmbed response alone; it is a real response
	 * that consumers still have to be able to use.
	 */
	public function test_oembed_response_is_otherwise_intact() {
		$data = get_oembed_response_data( self::$post_id, 600 );

		$this->assertSame( '1.0', $data['version'] );
		$this->assertSame( 'A post with an author', $data['title'] );
		$this->assertSame( get_bloginfo( 'name' ), $data['provider_name'] );
		$this->assertArrayHasKey( 'html', $data );
	}

	/**
	 * FR4 over the wire, which is where an anonymous caller actually reads it.
	 */
	public function test_oembed_rest_endpoint_names_the_site() {
		$request = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$request->set_param( 'url', get_permalink( self::$post_id ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( get_bloginfo( 'name' ), $data['author_name'] );
		$this->assertSame( home_url( '/' ), $data['author_url'] );
	}

	/**
	 * The filter tolerates another plugin having already replaced the data.
	 */
	public function test_scrubber_passes_non_array_data_through() {
		$this->assertNull( uamswp_rue_scrub_oembed_author( null ) );
		$this->assertSame( 'unchanged', uamswp_rue_scrub_oembed_author( 'unchanged' ) );
	}

	/**
	 * Fields another filter removed are not put back.
	 */
	public function test_scrubber_does_not_add_missing_fields() {
		$data = uamswp_rue_scrub_oembed_author( array( 'version' => '1.0' ) );

		$this->assertArrayNotHasKey( 'author_name', $data );
		$this->assertArrayNotHasKey( 'author_url', $data );
	}
}
