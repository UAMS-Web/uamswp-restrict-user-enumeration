<?php
/**
 * FR2: numeric author queries do not reveal slugs.
 *
 * Covers T9, including the ordering regression the draft code had.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class Test_UAMSWP_RUE_Author_Query extends WP_UnitTestCase {

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

		// Author archives have to resolve for redirect_canonical to have
		// anything to leak, which is the situation this guards.
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
	}

	/**
	 * Run template_redirect for the current query and report what happened.
	 *
	 * @return array{location: string|null, status: int|null, canonical_ran: bool}
	 */
	private function run_template_redirect() {
		$canonical_ran = false;

		add_filter(
			'redirect_canonical',
			static function ( $redirect_url ) use ( &$canonical_ran ) {
				$canonical_ran = true;
				return $redirect_url;
			}
		);

		UAMSWP_RUE_Redirect_Exception::intercept();

		try {
			do_action( 'template_redirect' );
		} catch ( UAMSWP_RUE_Redirect_Exception $e ) {
			return array(
				'location'      => $e->location,
				'status'        => $e->status,
				'canonical_ran' => $canonical_ran,
			);
		}

		return array(
			'location'      => null,
			'status'        => null,
			'canonical_ran' => $canonical_ran,
		);
	}

	/**
	 * FR2: the hook is registered ahead of redirect_canonical.
	 *
	 * The draft code used priority 10, which is core's own, and a callback
	 * added later at the same priority runs after it. This asserts the
	 * registration; the tests below assert the behaviour it produces.
	 */
	public function test_hook_runs_before_redirect_canonical() {
		$ours = has_action( 'template_redirect', 'uamswp_rue_block_author_query' );
		$core = has_action( 'template_redirect', 'redirect_canonical' );

		$this->assertSame( 1, $ours );
		$this->assertSame( 10, $core );
		$this->assertLessThan( $core, $ours );
	}

	/**
	 * T9: anonymous ?author=N is sent home, and redirect_canonical never runs.
	 */
	public function test_anonymous_author_query_redirects_home() {
		$this->go_to( home_url( '/?author=' . self::$author_id ) );

		$result = $this->run_template_redirect();

		$this->assertSame( home_url( '/' ), $result['location'] );
		$this->assertSame( 301, $result['status'] );
		$this->assertFalse( $result['canonical_ran'], 'redirect_canonical ran, so the slug was still exposed.' );
	}

	/**
	 * T9, control: the vector is real, and this plugin is what closes it.
	 *
	 * With the hook removed, core redirects to /author/enumtarget/ and hands
	 * an anonymous caller the nicename in the Location header.
	 */
	public function test_without_the_plugin_core_leaks_the_author_slug() {
		remove_action( 'template_redirect', 'uamswp_rue_block_author_query', 1 );

		$this->go_to( home_url( '/?author=' . self::$author_id ) );
		$result = $this->run_template_redirect();

		add_action( 'template_redirect', 'uamswp_rue_block_author_query', 1 );

		$this->assertTrue( $result['canonical_ran'] );
		$this->assertStringContainsString( 'enumtarget', (string) $result['location'] );
	}

	/**
	 * The redirect target never carries the slug.
	 */
	public function test_redirect_target_contains_no_slug() {
		$this->go_to( home_url( '/?author=' . self::$author_id ) );

		$result = $this->run_template_redirect();

		$this->assertStringNotContainsString( 'enumtarget', (string) $result['location'] );
		$this->assertStringNotContainsString( 'author', (string) $result['location'] );
	}

	/**
	 * Every author id is treated the same, including ones that do not exist:
	 * a 404 for one id and a redirect for another is itself an oracle.
	 *
	 * @dataProvider data_author_values
	 *
	 * @param string $value Raw ?author= value.
	 */
	public function test_any_author_parameter_is_redirected( $value ) {
		$this->go_to( home_url( '/?author=' . $value ) );

		$result = $this->run_template_redirect();

		$this->assertSame( home_url( '/' ), $result['location'], "?author={$value} was not redirected." );
		$this->assertSame( 301, $result['status'] );
	}

	public function data_author_values() {
		return array(
			'first user'  => array( '1' ),
			'no such id'  => array( '99999' ),
			'zero'        => array( '0' ),
			'empty'       => array( '' ),
			'not numeric' => array( 'enumtarget' ),
			'negative'    => array( '-1' ),
		);
	}

	/**
	 * A feed request is a front-end request too.
	 */
	public function test_author_feed_is_redirected() {
		$this->go_to( home_url( '/?author=' . self::$author_id . '&feed=rss2' ) );

		$result = $this->run_template_redirect();

		$this->assertSame( home_url( '/' ), $result['location'] );
	}

	/**
	 * FR2 is scoped to anonymous callers: a logged-in request is left to core,
	 * which canonicalizes it to the author archive exactly as it always did.
	 *
	 * The slug is not a secret from someone who is already authenticated, and
	 * leaving core's behaviour alone is what keeps wp-admin previews and
	 * author-archive editing working.
	 */
	public function test_logged_in_author_query_is_left_to_core() {
		wp_set_current_user( self::$author_id );

		$this->go_to( home_url( '/?author=' . self::$author_id ) );
		$result = $this->run_template_redirect();

		$this->assertTrue( $result['canonical_ran'], 'Core canonical handling did not run for a logged-in request.' );
		$this->assertSame( get_author_posts_url( self::$author_id ), $result['location'] );
	}

	/**
	 * No ?author= parameter, no interference.
	 */
	public function test_ordinary_front_end_request_is_not_redirected() {
		$this->go_to( home_url( '/' ) );

		$result = $this->run_template_redirect();

		$this->assertNull( $result['location'] );
	}

	/**
	 * Out of scope by decision, and asserted so the decision is visible: the
	 * /author/<slug>/ archive itself still works. The slug is public by design
	 * where author archives are in use.
	 */
	public function test_author_archive_page_still_works() {
		$this->go_to( get_author_posts_url( self::$author_id ) );

		$result = $this->run_template_redirect();

		$this->assertTrue( is_author() );
		$this->assertNull( $result['location'] );
	}

	/**
	 * FR7: the observability hook fires with the author_query vector.
	 */
	public function test_blocked_action_fires_for_the_author_vector() {
		$seen = array();

		add_action(
			'uamswp_rue_blocked',
			static function ( $vector, $request ) use ( &$seen ) {
				$seen[] = array( $vector, $request );
			},
			10,
			2
		);

		$this->go_to( home_url( '/?author=' . self::$author_id ) );
		$this->run_template_redirect();

		$this->assertCount( 1, $seen );
		$this->assertSame( 'author_query', $seen[0][0] );
		$this->assertNull( $seen[0][1] );
	}
}
