<?php

/**
 * Pins the permission-callback gate: uamswp_rue_guard_endpoints() and the closure
 * uamswp_rue_wrap_permission_callback() returns.
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once dirname(__DIR__).'/Support/wp-rest-stubs.php';

/**
 * A permission callback that records each call and returns a marker.
 *
 * @param  list<mixed>  $calls  Receives the arguments of every call.
 */
function uamswp_rue_test_spy(array &$calls, mixed $returns = 'original-answer'): Closure
{
    return static function (mixed ...$arguments) use (&$calls, $returns): mixed {
        $calls[] = $arguments;

        return $returns;
    };
}

describe('uamswp_rue_guard_endpoints', function (): void {
    it('returns a value that is not an array as it is', function (mixed $input): void {
        expect(uamswp_rue_guard_endpoints($input))->toBe($input);
    })->with([
        'null' => [null],
        'string' => ['routes'],
        'false' => [false],
    ]);

    it('skips a route whose handlers are not an array', function (): void {
        $endpoints = ['/wp/v2/users' => 'not handlers', '/wp/v2/users/1' => null];

        expect(uamswp_rue_guard_endpoints($endpoints))->toBe($endpoints);
    });

    it('leaves a route that is not a users route unchanged', function (): void {
        $callback = static fn (): bool => true;
        $endpoints = [
            '/wp/v2/posts' => [['methods' => 'GET', 'permission_callback' => $callback]],
            '/wp/v2/userss' => [['permission_callback' => $callback]],
        ];

        expect(uamswp_rue_guard_endpoints($endpoints))->toBe($endpoints);
    });

    it('wraps the permission callback of every handler on a users route', function (string $route): void {
        $first = static fn (): bool => true;
        $second = static fn (): bool => false;
        $endpoints = [$route => [
            ['methods' => 'GET', 'permission_callback' => $first],
            ['methods' => 'POST', 'permission_callback' => $second],
        ]];

        $guarded = uamswp_rue_guard_endpoints($endpoints);

        expect(array_keys($guarded))->toBe([$route])
            ->and($guarded[$route][0]['permission_callback'])->toBeInstanceOf(Closure::class)
            ->and($guarded[$route][0]['permission_callback'])->not->toBe($first)
            ->and($guarded[$route][1]['permission_callback'])->toBeInstanceOf(Closure::class)
            ->and($guarded[$route][1]['permission_callback'])->not->toBe($second)
            ->and($guarded[$route][0]['methods'])->toBe('GET')
            ->and($guarded[$route][1]['methods'])->toBe('POST');
    })->with([
        '/wp/v2/users',
        '/wp/v2/Users',
        '/wp/v2/users/(?P<id>[\d]+)',
        '/wp/v2/users/me',
    ]);

    it('leaves a handler with no permission_callback key, or that is not an array, unchanged', function (): void {
        $callback = static fn (): bool => true;
        $endpoints = ['/wp/v2/users' => [
            ['methods' => 'GET'],
            'not a handler',
            ['methods' => 'POST', 'permission_callback' => $callback],
        ]];

        $guarded = uamswp_rue_guard_endpoints($endpoints);

        expect($guarded['/wp/v2/users'][0])->toBe(['methods' => 'GET'])
            ->and($guarded['/wp/v2/users'][1])->toBe('not a handler')
            ->and($guarded['/wp/v2/users'][2]['permission_callback'])->toBeInstanceOf(Closure::class)
            ->and($guarded['/wp/v2/users'][2]['permission_callback'])->not->toBe($callback);
    });

    it('keeps going after a route it skips', function (): void {
        // A skipped route must not end the loop: each skip is followed by a users route that needs wrapping.
        $callback = static fn (): bool => true;
        $endpoints = [
            '/wp/v2/broken' => 'not handlers',
            '/wp/v2/posts' => [['permission_callback' => $callback]],
            '/wp/v2/users' => [['permission_callback' => $callback]],
            '/wp/v2/users/me' => [['permission_callback' => $callback]],
        ];

        $guarded = uamswp_rue_guard_endpoints($endpoints);

        expect($guarded['/wp/v2/broken'])->toBe('not handlers')
            ->and($guarded['/wp/v2/posts'][0]['permission_callback'])->toBe($callback)
            ->and($guarded['/wp/v2/users'][0]['permission_callback'])->not->toBe($callback)
            ->and($guarded['/wp/v2/users/me'][0]['permission_callback'])->not->toBe($callback);
    });

    it('wraps a handler whose permission_callback key holds null', function (): void {
        // array_key_exists, not isset: a registered null callback is still a callback slot.
        $guarded = uamswp_rue_guard_endpoints(['/wp/v2/users' => [['permission_callback' => null]]]);

        expect($guarded['/wp/v2/users'][0]['permission_callback'])->toBeInstanceOf(Closure::class);
    });
});

describe('the wrapped permission callback', function (): void {
    it('hands a logged-in caller to the original callback and returns its answer', function (): void {
        Functions\when('is_user_logged_in')->justReturn(true);
        Filters\expectApplied('uamswp_rue_block_rest_request')->never();
        Actions\expectDone('uamswp_rue_blocked')->never();
        $calls = [];
        $request = new WP_REST_Request('/wp/v2/users');

        $wrapped = uamswp_rue_wrap_permission_callback(uamswp_rue_test_spy($calls));

        expect($wrapped($request))->toBe('original-answer')
            ->and($calls)->toBe([[$request]]);
    });

    it('refuses an anonymous caller with a 401 and never calls the original', function (): void {
        Functions\when('is_user_logged_in')->justReturn(false);
        $request = new WP_REST_Request('/wp/v2/users');
        Filters\expectApplied('uamswp_rue_block_rest_request')->once()->with(true, $request);
        Actions\expectDone('uamswp_rue_blocked')->once()->with('rest', $request);
        $calls = [];

        $result = uamswp_rue_wrap_permission_callback(uamswp_rue_test_spy($calls))($request);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('rest_forbidden')
            ->and($result->get_error_message())->toBe('Authentication required.')
            ->and($result->get_error_data())->toBe(['status' => 401])
            ->and($calls)->toBe([]);
    });

    it('lets an anonymous caller through to the original when the block filter returns false', function (): void {
        Functions\when('is_user_logged_in')->justReturn(false);
        $request = new WP_REST_Request('/wp/v2/users');
        Filters\expectApplied('uamswp_rue_block_rest_request')->once()->with(true, $request)->andReturn(false);
        Actions\expectDone('uamswp_rue_blocked')->never();
        $calls = [];

        $result = uamswp_rue_wrap_permission_callback(uamswp_rue_test_spy($calls))($request);

        expect($result)->toBe('original-answer')
            ->and($calls)->toBe([[$request]]);
    });

    it('answers true for a caller the gate lets through when the original is not callable', function (mixed $original): void {
        Functions\when('is_user_logged_in')->justReturn(true);

        expect(uamswp_rue_wrap_permission_callback($original)(new WP_REST_Request('/wp/v2/users')))->toBeTrue();
    })->with([
        'null' => [null],
        'a string that names no function' => ['uamswp_rue_no_such_function'],
        'an array that is no callable' => [['no', 'method']],
    ]);

    it('still refuses an anonymous caller when the original is not callable', function (): void {
        Functions\when('is_user_logged_in')->justReturn(false);
        $request = new WP_REST_Request('/wp/v2/users');
        Actions\expectDone('uamswp_rue_blocked')->once()->with('rest', $request);

        expect(uamswp_rue_wrap_permission_callback(null)($request))->toBeInstanceOf(WP_Error::class);
    });
});
