<?php
/**
 * FR1: the REST users routes require authentication.
 *
 * Covers T1 through T5, T7, T8 and T13.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class Test_UAMSWP_RUE_Rest_Users extends WP_Test_REST_TestCase {

	/** @var int */
	private static $author_id;

	/** @var int */
	private static $editor_id;

	/** @var int */
	private static $post_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id = $factory->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'enumtarget',
				'user_nicename' => 'enumtarget',
				'display_name' => 'Enum Target',
			)
		);

		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );

		self::$post_id = $factory->post->create(
			array(
				'post_author' => self::$author_id,
				'post_status' => 'publish',
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		self::delete_user( self::$editor_id );
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
	 * @param string $route  Route to request.
	 * @param string $method HTTP method.
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch( $route, $method = 'GET' ) {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, $route ) );
	}

	/**
	 * The plugin registered itself where it says it does.
	 */
	public function test_hooks_are_registered() {
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', 'uamswp_rue_block_rest_users' ) );
	}

	/**
	 * T1: anonymous GET /wp/v2/users.
	 */
	public function test_anonymous_users_collection_is_refused() {
		$response = $this->dispatch( '/wp/v2/users' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * T1, continued: the response body carries no user data at all.
	 *
	 * Without the plugin this route answers 200 with the author's nicename,
	 * which is the login on most installs. This is the leak itself, asserted.
	 */
	public function test_refused_response_contains_no_user_data() {
		$body = wp_json_encode( $this->dispatch( '/wp/v2/users' )->get_data() );

		$this->assertStringNotContainsString( 'enumtarget', $body );
		$this->assertStringNotContainsString( 'Enum Target', $body );
	}

	/**
	 * T2: anonymous GET /wp/v2/users/{id}.
	 */
	public function test_anonymous_single_user_is_refused() {
		$response = $this->dispatch( '/wp/v2/users/' . self::$author_id );

		$this->assertSame( 401, $response->get_status() );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * T3: the case bypass. Core matches routes with a case-insensitive regex,
	 * so /wp/v2/Users reaches the same handler as /wp/v2/users.
	 *
	 * @dataProvider data_case_variants
	 *
	 * @param string $route Route spelled in mixed or upper case.
	 */
	public function test_case_variants_are_refused( $route ) {
		$response = $this->dispatch( $route );

		$this->assertSame( 401, $response->get_status(), "{$route} was not refused." );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	public function data_case_variants() {
		return array(
			'upper collection'    => array( '/wp/v2/USERS' ),
			'title collection'    => array( '/wp/v2/Users' ),
			'title single'        => array( '/wp/v2/Users/1' ),
			'mixed single'        => array( '/wp/v2/uSeRs/1' ),
			'upper namespace'     => array( '/WP/V2/USERS' ),
			'upper me'            => array( '/wp/v2/Users/Me' ),
		);
	}

	/**
	 * T3, control: core really would have served the mixed-case route.
	 *
	 * If this ever fails, core stopped matching case-insensitively and the
	 * dedicated case handling above has become belt and braces.
	 */
	public function test_core_matches_the_users_route_case_insensitively() {
		$server  = rest_get_server();
		$matcher = new ReflectionMethod( $server, 'match_request_to_handler' );
		$matcher->setAccessible( true );

		$match = $matcher->invoke( $server, new WP_REST_Request( 'GET', '/wp/v2/Users' ) );

		$this->assertNotWPError( $match, 'Core no longer routes /wp/v2/Users to the users controller.' );
		$this->assertSame( '/wp/v2/users', $match[0] );
	}

	/**
	 * T4: trailing slash.
	 */
	public function test_trailing_slash_is_refused() {
		$response = $this->dispatch( '/wp/v2/users/' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * Repeated slashes, which normalization collapses.
	 */
	public function test_repeated_slashes_are_refused() {
		$response = $this->dispatch( '//wp//v2//users' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * T5: an Editor gets the block editor's Author dropdown.
	 *
	 * Editors do not have list_users, which is why the plugin gates on
	 * is_user_logged_in() instead.
	 */
	public function test_editor_may_list_authors() {
		// who=authors is deprecated in WP_User_Query as of 5.9 but is still
		// what the block editor sends for a user without list_users, because
		// the capabilities[] replacement requires list_users to use.
		$this->setExpectedDeprecated( 'WP_User_Query' );

		wp_set_current_user( self::$editor_id );
		$this->assertFalse( current_user_can( 'list_users' ), 'Editors are not expected to have list_users.' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_param( 'who', 'authors' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
		$this->assertContains( 'enumtarget', wp_list_pluck( $response->get_data(), 'slug' ) );
	}

	/**
	 * T5, continued: the plain collection an Editor reads is untouched too.
	 */
	public function test_editor_may_list_users_without_the_who_parameter() {
		wp_set_current_user( self::$editor_id );

		$response = $this->dispatch( '/wp/v2/users' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
	}

	/**
	 * T5, continued: a logged-in request for a single user is untouched.
	 */
	public function test_editor_may_read_a_single_user() {
		wp_set_current_user( self::$editor_id );

		$response = $this->dispatch( '/wp/v2/users/' . self::$author_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'enumtarget', $response->get_data()['slug'] );
	}

	/**
	 * T7: a users request inside a /batch/v1 envelope is refused too.
	 *
	 * Batch v1 only accepts POST, PUT, PATCH and DELETE (the method parameter
	 * is an enum on the /batch/v1 route), so the spec's "users GET in a batch"
	 * cannot be built; see test_batch_v1_does_not_accept_get() below. A POST to
	 * /wp/v2/users is the closest real sub-request, and it proves what T7 is
	 * for: rest_pre_dispatch fires for batch sub-requests, so one route check
	 * covers the batch vector.
	 */
	public function test_users_request_inside_a_batch_is_refused() {
		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'POST',
						'path'   => '/wp/v2/users',
						'body'   => array(
							'username' => 'batchling',
							'email'    => 'batchling@example.org',
							'password' => 'irrelevant',
						),
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'responses', $data );
		$this->assertSame( 401, $data['responses'][0]['status'] );
		$this->assertSame( 'rest_forbidden', $data['responses'][0]['body']['code'] );
		$this->assertSame( 'Authentication required.', $data['responses'][0]['body']['message'] );
	}

	/**
	 * T7, as an anonymous prober actually sends it: no sub-request body.
	 *
	 * The batch handler validates sub-requests before it dispatches them, so a
	 * bodyless POST to /wp/v2/users has a pending rest_missing_callback_param
	 * error waiting for it. rest_pre_dispatch still runs first and still wins,
	 * and the difference is diagnostic: 401 means the block is on the dispatch
	 * of each sub-request, 400 means whatever is blocking only ever saw the
	 * outer /batch/v1 request and the users route went through untouched.
	 */
	public function test_bodyless_users_request_inside_a_batch_is_refused() {
		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'POST',
						'path'   => '/wp/v2/users',
					),
				),
			)
		);

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 401, $data['responses'][0]['status'] );
		$this->assertSame( 'rest_forbidden', $data['responses'][0]['body']['code'] );
		$this->assertNotSame(
			'rest_missing_callback_param',
			$data['responses'][0]['body']['code'],
			'The sub-request reached parameter validation, so it was never blocked.'
		);
	}

	/**
	 * T7, basis: why the test above is a POST.
	 *
	 * Documents the constraint rather than assuming it. If core ever widens the
	 * enum, this fails and the GET case becomes testable.
	 */
	public function test_batch_v1_does_not_accept_get() {
		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'GET',
						'path'   => '/wp/v2/users',
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * T8: no collateral blocking.
	 *
	 * @dataProvider data_untouched_routes
	 *
	 * @param string $route Route that must keep working for anonymous callers.
	 */
	public function test_other_routes_are_untouched( $route ) {
		$response = $this->dispatch( $route );

		$this->assertSame( 200, $response->get_status(), "{$route} was blocked and should not have been." );
	}

	public function data_untouched_routes() {
		return array(
			'posts'           => array( '/wp/v2/posts' ),
			'pages'           => array( '/wp/v2/pages' ),
			'categories'      => array( '/wp/v2/categories' ),
			'types'           => array( '/wp/v2/types' ),
			'root'            => array( '/' ),
			'namespace index' => array( '/wp/v2' ),
		);
	}

	/**
	 * A route whose name merely starts with the blocked one is not blocked.
	 */
	public function test_lookalike_route_is_not_blocked() {
		register_rest_route(
			'wp/v2',
			'/users-online',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return array( 'ok' => true );
				},
				'permission_callback' => '__return_true',
			)
		);

		$response = $this->dispatch( '/wp/v2/users-online' );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * T13: FR6 lets a site allow one anonymous consumer back in.
	 */
	public function test_filter_can_allow_an_anonymous_request() {
		$seen = null;

		add_filter(
			'uamswp_rue_block_rest_request',
			static function ( $block, $request ) use ( &$seen ) {
				$seen = $request;
				return false;
			},
			10,
			2
		);

		$response = $this->dispatch( '/wp/v2/users' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( 'WP_REST_Request', $seen, 'The filter was not passed the request.' );
		$this->assertSame( '/wp/v2/users', $seen->get_route() );
	}

	/**
	 * FR6 defaults to blocking, and the filter is applied on the users route only.
	 */
	public function test_filter_is_not_applied_to_other_routes() {
		$calls = 0;

		add_filter(
			'uamswp_rue_block_rest_request',
			static function ( $block ) use ( &$calls ) {
				++$calls;
				return $block;
			}
		);

		$this->dispatch( '/wp/v2/posts' );
		$this->assertSame( 0, $calls );

		$this->dispatch( '/wp/v2/users' );
		$this->assertSame( 1, $calls );
	}

	/**
	 * FR7: the observability hook fires with the vector and the request.
	 */
	public function test_blocked_action_fires_for_the_rest_vector() {
		$seen = array();

		add_action(
			'uamswp_rue_blocked',
			static function ( $vector, $request ) use ( &$seen ) {
				$seen[] = array( $vector, $request );
			},
			10,
			2
		);

		$this->dispatch( '/wp/v2/users' );

		$this->assertCount( 1, $seen );
		$this->assertSame( 'rest', $seen[0][0] );
		$this->assertInstanceOf( 'WP_REST_Request', $seen[0][1] );
	}

	/**
	 * FR7: nothing fires when nothing was blocked.
	 */
	public function test_blocked_action_does_not_fire_for_allowed_requests() {
		$calls = 0;

		add_action(
			'uamswp_rue_blocked',
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		$this->dispatch( '/wp/v2/posts' );
		wp_set_current_user( self::$editor_id );
		$this->dispatch( '/wp/v2/users' );

		$this->assertSame( 0, $calls );
	}
}
