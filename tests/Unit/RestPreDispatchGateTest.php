<?php

/**
 * Pins the rest_pre_dispatch gate, uamswp_rue_block_rest_users().
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once dirname(__DIR__).'/Support/wp-rest-stubs.php';

/**
 * Makes the converter exist, as it does on WordPress 5.7 and later, and shapes its answer.
 */
function uamswp_rue_test_converter_returns_response(): void
{
    Functions\when('rest_convert_error_to_response')->alias(
        static fn (WP_Error $error): WP_REST_Response => new WP_REST_Response(
            ['code' => $error->get_error_code(), 'message' => $error->get_error_message()],
            (int) ($error->get_error_data()['status'] ?? 0)
        )
    );
}

/**
 * Expects neither the filter nor the action to run.
 */
function uamswp_rue_test_expect_no_hooks(): void
{
    Filters\expectApplied('uamswp_rue_block_rest_request')->never();
    Actions\expectDone('uamswp_rue_blocked')->never();
}

it('returns the result untouched for a logged-in caller and calls no hook', function (): void {
    Functions\when('is_user_logged_in')->justReturn(true);
    uamswp_rue_test_expect_no_hooks();
    $result = new stdClass;

    expect(uamswp_rue_block_rest_users($result, null, new WP_REST_Request('/wp/v2/users')))->toBe($result);
});

it('returns the result untouched when the request is not a WP_REST_Request', function (): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    uamswp_rue_test_expect_no_hooks();
    $result = ['dispatch' => 'result'];

    expect(uamswp_rue_block_rest_users($result, null, new stdClass))->toBe($result)
        ->and(uamswp_rue_block_rest_users($result, null, null))->toBe($result);
});

it('returns the result untouched for a route that is not a users route', function (string $route): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    uamswp_rue_test_expect_no_hooks();

    expect(uamswp_rue_block_rest_users(null, null, new WP_REST_Request($route)))->toBeNull();
})->with([
    '/wp/v2/posts',
    '/wp/v2/userss',
    '/',
]);

it('refuses an anonymous caller on a users route with a 401 and announces it', function (string $route): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    uamswp_rue_test_converter_returns_response();
    $request = new WP_REST_Request($route);

    Filters\expectApplied('uamswp_rue_block_rest_request')->once()->with(true, $request);
    Actions\expectDone('uamswp_rue_blocked')->once()->with('rest', $request);

    $response = uamswp_rue_block_rest_users(null, null, $request);

    expect($response)->toBeInstanceOf(WP_REST_Response::class)
        ->and($response->status)->toBe(401)
        ->and($response->data)->toBe(['code' => 'rest_forbidden', 'message' => 'Authentication required.']);
})->with([
    '/wp/v2/users',
    '/WP/v2//Users/1/',
    '/wp/v2/users/me',
]);

it('discards the result a caller passed in when it refuses', function (): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    uamswp_rue_test_converter_returns_response();

    expect(uamswp_rue_block_rest_users('earlier result', null, new WP_REST_Request('/wp/v2/users')))
        ->toBeInstanceOf(WP_REST_Response::class);
});

it('lets the request through, and fires no action, when the block filter returns false', function (): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    $request = new WP_REST_Request('/wp/v2/users');
    $result = new stdClass;

    Filters\expectApplied('uamswp_rue_block_rest_request')->once()->with(true, $request)->andReturn(false);
    Actions\expectDone('uamswp_rue_blocked')->never();

    expect(uamswp_rue_block_rest_users($result, null, $request))->toBe($result);
});

it('still refuses when the block filter returns another truthy value', function (): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    uamswp_rue_test_converter_returns_response();
    $request = new WP_REST_Request('/wp/v2/users');

    Filters\expectApplied('uamswp_rue_block_rest_request')->once()->andReturn(1);
    Actions\expectDone('uamswp_rue_blocked')->once();

    expect(uamswp_rue_block_rest_users(null, null, $request))->toBeInstanceOf(WP_REST_Response::class);
});

it('returns the raw WP_Error where rest_convert_error_to_response does not exist', function (): void {
    // A fresh process: the converter is defined above and stays defined for this one.
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Support/call-gate-in-child.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    expect($process)->not->toBeFalse('The child PHP process did not start.');

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, "The child exited {$status}: {$stderr}{$stdout}");

    expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe([
        'converter_defined' => false,
        'class' => 'WP_Error',
        'code' => 'rest_forbidden',
        'status' => 401,
    ]);
});
