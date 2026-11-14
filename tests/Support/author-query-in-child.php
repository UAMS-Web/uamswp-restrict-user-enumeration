<?php

/**
 * Calls uamswp_rue_block_author_query() in a fresh PHP process and reports what it did.
 *
 * The callback ends with `exit` after the redirect, so it cannot run in the test
 * runner's process. The first argument is JSON: `get` is the value of $_GET and
 * `logged_in` is what is_user_logged_in() answers. The report is printed from a
 * shutdown function, so it appears whether or not the callback exited, and
 * `returned` says whether control came back from the call.
 */

declare(strict_types=1);

define('ABSPATH', '/');

/** @var array{actions: list<array<int, mixed>>, redirects: list<array{string, int}>, returned: bool} $uamswp_rue_report */
$uamswp_rue_report = ['actions' => [], 'redirects' => [], 'returned' => false];

$uamswp_rue_input = json_decode((string) ($argv[1] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
$_GET = $uamswp_rue_input['get'] ?? [];

function add_filter(): bool
{
    return true;
}

function add_action(): bool
{
    return true;
}

function get_file_data(): array
{
    return ['Version' => 'child'];
}

function is_user_logged_in(): bool
{
    return (bool) $GLOBALS['uamswp_rue_input']['logged_in'];
}

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['uamswp_rue_report']['actions'][] = [$hook, ...$arguments];
}

function home_url(string $path = ''): string
{
    return 'https://example.test'.$path;
}

function wp_safe_redirect(string $location, int $status = 302): bool
{
    $GLOBALS['uamswp_rue_report']['redirects'][] = [$location, $status];

    return true;
}

register_shutdown_function(static function (): void {
    echo json_encode($GLOBALS['uamswp_rue_report'], JSON_THROW_ON_ERROR);
});

include dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php';

$uamswp_rue_report['actions'] = [];
$uamswp_rue_report['redirects'] = [];

uamswp_rue_block_author_query();

$uamswp_rue_report['returned'] = true;
