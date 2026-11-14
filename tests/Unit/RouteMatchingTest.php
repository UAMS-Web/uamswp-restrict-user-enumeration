<?php

/**
 * Pins the two pure route helpers both REST gates decide by.
 */

declare(strict_types=1);

it('normalizes a route', function (string $raw, string $expected): void {
    expect(uamswp_rue_normalize_route($raw))->toBe($expected);
})->with([
    'already normal' => ['/wp/v2/users', '/wp/v2/users'],
    'upper case' => ['/WP/V2/Users', '/wp/v2/users'],
    'dotted capital I' => ['/wp/v2/USERS/I', '/wp/v2/users/i'],
    'repeated slashes' => ['/wp//v2///users', '/wp/v2/users'],
    'no leading slash' => ['wp/v2/users', '/wp/v2/users'],
    'trailing slash' => ['/wp/v2/users/', '/wp/v2/users'],
    'several trailing slashes' => ['/wp/v2/users///', '/wp/v2/users'],
    'empty route' => ['', '/'],
    'only slashes' => ['///', '/'],
    'bytes outside ASCII are left alone' => ["/wp/v2/\u{130}SERS", "/wp/v2/\u{130}sers"],
]);

it('casts a non-string route before normalizing it', function (mixed $raw, string $expected): void {
    // The parameter is untyped, so the cast is what keeps the return a string.
    expect(uamswp_rue_normalize_route($raw))->toBe($expected);
})->with([
    'null' => [null, '/'],
    'integer' => [12, '/12'],
    'false' => [false, '/'],
]);

it('keeps the lowercased route when the slash collapse fails', function (): void {
    // preg_replace() returns null on an engine error, which the is_string guard must not adopt.
    // A one-step backtrack limit without the JIT makes the collapse fail on a long run of slashes
    // on some PCRE builds (macOS, PHP 8.4.27) and not on others (the Linux runner), so the test
    // first proves the failure happened and skips, naming the reason, when it cannot.
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    $input = str_repeat('/', 5000).'A';

    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $failed = preg_replace('#/+#', '/', $input);
        $result = uamswp_rue_normalize_route($input);
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    if ($failed !== null) {
        $this->markTestSkipped('This PCRE build does not fail preg_replace() under pcre.backtrack_limit=1, so the is_string guard cannot be reached here.');
    }

    expect($result)->toBe('/a');
});

it('matches the core users routes', function (string $route): void {
    expect(uamswp_rue_is_users_route($route))->toBeTrue();
})->with([
    '/wp/v2/users',
    '/wp/v2/users/',
    '/wp/v2/users/1',
    '/wp/v2/users/me',
    '/wp/v2/users/1/application-passwords',
]);

it('matches an upper-case route that was never normalized', function (string $route): void {
    // The `i` modifier is redundant after normalization and kept on purpose.
    expect(uamswp_rue_is_users_route($route))->toBeTrue();
})->with([
    '/wp/v2/Users',
    '/WP/V2/USERS/ME',
]);

it('leaves the near misses open', function (string $route): void {
    expect(uamswp_rue_is_users_route($route))->toBeFalse();
})->with([
    '/wp/v2/userss',
    '/wp/v2/user',
    '/wp/v2/posts',
    '/wp/v2/users-x',
    '/wp/v2/usersx/1',
    '/x/wp/v2/users',
    'wp/v2/users',
    '',
]);

it('refuses every spelling of the users route once normalized', function (string $raw): void {
    expect(uamswp_rue_is_users_route(uamswp_rue_normalize_route($raw)))->toBeTrue();
})->with([
    '/WP/v2/Users',
    '//wp//v2//users//',
    'wp/v2/users/1/',
]);
