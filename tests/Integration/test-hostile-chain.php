<?php
/**
 * FR1 survives a badly-behaved plugin on the same hook.
 *
 * Regression tests for what was found in production: Advanced Custom Fields
 * 6.4.2 registers ACF_Rest_Api::initialize() on rest_pre_dispatch at priority
 * 10 and never returns its input, so it discarded the 401 this plugin had just
 * returned and /wp/v2/users answered 200 with the whole staff list.
 *
 * The fixture below is that callback's shape, not ACF itself: a filter
 * callback at priority 10 that returns null whatever it is handed.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class Test_UAMSWP_RUE_Hostile_Chain extends WP_Test_REST_TestCase {

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
	 * ACF's shape: hooked at 10, returns null no matter what it was given.
	 *
	 * @return void
	 */
	private function add_result_discarding_callback() {
		add_filter(
			'rest_pre_dispatch',
			static function () {
				return null;
			},
			10,
			3
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch( $route, $method = 'GET' ) {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, $route ) );
	}

	/**
	 * The premise: this plugin runs after anything registered normally.
	 */
	public function test_rest_pre_dispatch_runs_last() {
		$this->assertSame(
			PHP_INT_MAX,
			has_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users' ),
			'A callback registered at a higher priority could discard the refusal.'
		);
	}

	/**
	 * Both gates are registered.
	 */
	public function test_both_gates_are_registered() {
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users' ) );
		$this->assertNotFalse( has_filter( 'rest_endpoints', 'uamswp_rue_guard_endpoints' ) );
	}

	/**
	 * The production failure, reproduced: a discarding callback at priority 10.
	 *
	 * Before the fix this returned 200 and the full user list.
	 */
	public function test_users_still_refused_when_a_callback_discards_the_result() {
		$this->add_result_discarding_callback();

		$response = $this->dispatch( '/wp/v2/users' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
		$this->assertStringNotContainsString( 'enumtarget', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * The same, for a single user and for the case variants.
	 *
	 * @dataProvider data_routes
	 *
	 * @param string $route Route to request.
	 */
	public function test_every_users_route_survives_a_discarding_callback( $route ) {
		$this->add_result_discarding_callback();

		$response = $this->dispatch( $route );

		$this->assertSame( 401, $response->get_status(), "{$route} was served." );
	}

	public function data_routes() {
		return array(
			'collection'     => array( '/wp/v2/users' ),
			'single'         => array( '/wp/v2/users/1' ),
			'me'             => array( '/wp/v2/users/me' ),
			'upper'          => array( '/wp/v2/USERS' ),
			'mixed'          => array( '/wp/v2/Users/1' ),
		);
	}

	/**
	 * The second gate on its own, with the filter chain removed entirely.
	 *
	 * This is the case the rest_endpoints gate exists for: it does not live in
	 * a chain, so there is nothing to discard.
	 */
	public function test_endpoint_gate_refuses_without_any_rest_pre_dispatch() {
		remove_all_filters( 'rest_pre_dispatch' );
		$this->assertFalse( has_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users' ) );

		$response = $this->dispatch( '/wp/v2/users' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * Whether this WordPress lets the users routes appear in a batch at all.
	 *
	 * WP_REST_Users_Controller gained allow_batch after 6.4: it is absent in
	 * 6.4.12 and present in 6.8.10 and 7.0.6. Where it is absent the batch
	 * envelope is not a route to the users data in the first place, and core
	 * answers rest_batch_not_allowed.
	 *
	 * It only shows up in this test because the gate under test is the endpoint
	 * one; with rest_pre_dispatch in place the refusal short-circuits ahead of
	 * core's allow_batch check, which is why the other batch tests pass on every
	 * version.
	 *
	 * @return bool
	 */
	private function users_route_allows_batch() {
		// Route-level, not per-handler: register_rest_route() takes allow_batch
		// as a sibling of the method handlers, and get_route_options() is what
		// reads those siblings back.
		$options = rest_get_server()->get_route_options( '/wp/v2/users' );

		return ! empty( $options['allow_batch']['v1'] );
	}

	/**
	 * The second gate covers the batch envelope too, since batch sub-requests
	 * run respond_to_request() and therefore the permission callback.
	 */
	public function test_endpoint_gate_covers_batch_without_rest_pre_dispatch() {
		remove_all_filters( 'rest_pre_dispatch' );

		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'POST',
						'path'   => '/wp/v2/users',
						// A full body on purpose: core validates sub-request
						// params before it asks the permission callback, so a
						// bodyless POST would 400 at validation and never reach
						// the gate this test is about.
						'body'   => array(
							'username' => 'batchling',
							'email'    => 'batchling@example.org',
							'password' => 'irrelevant',
						),
					),
				),
			)
		);

		$data = rest_get_server()->dispatch( $request )->get_data();

		if ( ! $this->users_route_allows_batch() ) {
			$this->assertSame( 'rest_batch_not_allowed', $data['responses'][0]['body']['code'] );
			$this->assertSame( 400, $data['responses'][0]['status'] );
			return;
		}

		$this->assertSame( 401, $data['responses'][0]['status'] );
		$this->assertSame( 'rest_forbidden', $data['responses'][0]['body']['code'] );
	}

	/**
	 * The second gate covers _embed too, for the same reason.
	 */
	public function test_endpoint_gate_covers_embed_without_rest_pre_dispatch() {
		remove_all_filters( 'rest_pre_dispatch' );

		$server   = rest_get_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$body     = wp_json_encode( $server->response_to_data( $response, rest_parse_embed_param( 'author' ) ) );

		$this->assertStringNotContainsString( 'enumtarget', $body );
		$this->assertStringNotContainsString( 'Enum Target', $body );
	}

	/**
	 * The second gate leaves logged-in access exactly as core decided it.
	 *
	 * The wrapper hands a logged-in request to the callback the route was
	 * registered with, so an Editor keeps the block editor's Author dropdown.
	 */
	public function test_endpoint_gate_does_not_touch_logged_in_access() {
		remove_all_filters( 'rest_pre_dispatch' );
		$this->setExpectedDeprecated( 'WP_User_Query' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_param( 'who', 'authors' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
	}

	/**
	 * The second gate still defers to core for things core refuses.
	 *
	 * An anonymous POST is refused by this plugin; a logged-in Author without
	 * create_users must still be refused by core's own check, not waved through
	 * by the wrapper.
	 */
	public function test_endpoint_gate_preserves_core_refusals_for_logged_in_users() {
		remove_all_filters( 'rest_pre_dispatch' );

		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/users' );
		$request->set_body_params(
			array(
				'username' => 'someone',
				'email'    => 'someone@example.org',
				'password' => 'irrelevant',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cannot_create_user', $response->get_data()['code'] );
	}

	/**
	 * The second gate touches only the users routes.
	 */
	public function test_endpoint_gate_leaves_other_routes_alone() {
		remove_all_filters( 'rest_pre_dispatch' );

		foreach ( array( '/wp/v2/posts', '/wp/v2/pages', '/wp/v2/categories', '/wp/v2/types' ) as $route ) {
			$this->assertSame( 200, $this->dispatch( $route )->get_status(), "{$route} was blocked." );
		}
	}

	/**
	 * FR6 still works through the second gate.
	 */
	public function test_filter_can_allow_an_anonymous_request_through_the_endpoint_gate() {
		remove_all_filters( 'rest_pre_dispatch' );
		add_filter( 'uamswp_rue_block_rest_request', '__return_false' );

		$this->assertSame( 200, $this->dispatch( '/wp/v2/users' )->get_status() );
	}

	/**
	 * FR7 still fires through the second gate.
	 */
	public function test_blocked_action_fires_from_the_endpoint_gate() {
		remove_all_filters( 'rest_pre_dispatch' );

		$seen = array();
		add_action(
			'uamswp_rue_blocked',
			static function ( $vector ) use ( &$seen ) {
				$seen[] = $vector;
			},
			10,
			2
		);

		$this->dispatch( '/wp/v2/users' );

		$this->assertContains( 'rest', $seen );
	}

	/**
	 * The guard tolerates the shapes another plugin's rest_endpoints filter
	 * might have left behind.
	 */
	public function test_guard_tolerates_unexpected_endpoint_shapes() {
		$this->assertSame( 'not an array', uamswp_rue_guard_endpoints( 'not an array' ) );

		$untouched = array( '/wp/v2/posts' => array( array( 'permission_callback' => '__return_true' ) ) );
		$this->assertSame( $untouched, uamswp_rue_guard_endpoints( $untouched ) );

		$no_callback = array( '/wp/v2/users' => array( array( 'methods' => 'GET' ) ) );
		$this->assertSame( $no_callback, uamswp_rue_guard_endpoints( $no_callback ) );
	}

	/**
	 * A route whose permission callback is missing or unusable stays public for
	 * logged-in callers, which is what core does with it.
	 */
	public function test_wrapper_defaults_to_core_behaviour_for_logged_in_users() {
		wp_set_current_user( self::$author_id );

		$wrapped = uamswp_rue_wrap_permission_callback( null );

		$this->assertTrue( $wrapped( new WP_REST_Request( 'GET', '/wp/v2/users' ) ) );
	}
}
