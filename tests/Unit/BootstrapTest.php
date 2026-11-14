<?php

/**
 * Pins what decides whether any hook is registered: the ABSPATH guard, the
 * double-load guard, the version constant, the kill switch and the hook list.
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * Loads the plugin file in a fresh PHP process.
 *
 * The constants are read once and `exit` ends the request, so these cases
 * cannot run in the test runner's own process.
 *
 * @param  array<string, mixed>  $defines  Constants defined before the file is included.
 * @param  string  $mode  `second-copy` to include a copy of the file from another path after the first load.
 * @return array{registered: list<array{string, string, mixed, int, int}>, version: ?string, read_header: bool}
 */
function uamswp_rue_test_load_in_child(array $defines, string $mode = ''): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Support/load-plugin-in-child.php', json_encode($defines, JSON_THROW_ON_ERROR), $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    expect($process)->not->toBeFalse('The child PHP process did not start.');

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, "The child exited {$status}: {$stderr}{$stdout}");

    /** @var array{registered: list<array{string, string, mixed, int, int}>, version: ?string, read_header: bool} */
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The five registrations, in the order the plugin makes them.
 *
 * @return list<array{string, string, string, int, int}>
 */
function uamswp_rue_test_expected_hooks(): array
{
    return [
        ['filter', 'rest_pre_dispatch', 'uamswp_rue_block_rest_users', PHP_INT_MAX, 3],
        ['filter', 'rest_endpoints', 'uamswp_rue_guard_endpoints', PHP_INT_MAX, 1],
        ['action', 'template_redirect', 'uamswp_rue_block_author_query', 1, 1],
        ['filter', 'wp_sitemaps_add_provider', 'uamswp_rue_remove_users_sitemap', 10, 2],
        ['filter', 'oembed_response_data', 'uamswp_rue_scrub_oembed_author', 10, 1],
    ];
}

it('ends the request when ABSPATH is not defined', function (): void {
    // The child defines no ABSPATH, so the first guard runs `exit`, before the version is read.
    $report = uamswp_rue_test_load_in_child([]);

    expect($report['version'])->toBeNull()
        ->and($report['read_header'])->toBeFalse()
        ->and($report['registered'])->toBe([]);
});

it('loads when ABSPATH is defined', function (): void {
    // Control for the case above: the same child, with the guard satisfied.
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/']);

    expect($report['version'])->toBe('from-header')
        ->and($report['registered'])->toHaveCount(5);
});

it('skips the header read and the registration when the version constant is already defined', function (): void {
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/', 'UAMSWP_RUE_VERSION' => 'first-copy']);

    expect($report['version'])->toBe('first-copy')
        ->and($report['read_header'])->toBeFalse()
        ->and($report['registered'])->toBe([]);
});

it('takes the version constant from the header read', function (): void {
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/']);

    expect($report['read_header'])->toBeTrue()
        ->and($report['version'])->toBe('from-header');
});

it('reports the kill switch off when the constant is not defined', function (): void {
    expect(defined('UAMSWP_RUE_DISABLE'))->toBeFalse()
        ->and(uamswp_rue_disabled())->toBeFalse();
});

it('registers the five hooks with their priorities and argument counts', function (): void {
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/']);

    expect($report['registered'])->toBe(uamswp_rue_test_expected_hooks());
});

it('registers the same five hooks when the kill switch is defined false', function (): void {
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/', 'UAMSWP_RUE_DISABLE' => false]);

    expect($report['registered'])->toBe(uamswp_rue_test_expected_hooks());
});

it('registers no hook when the kill switch is defined true', function (): void {
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/', 'UAMSWP_RUE_DISABLE' => true]);

    expect($report['version'])->toBe('from-header')
        ->and($report['registered'])->toBe([]);
});

it('registers each hook from uamswp_rue_bootstrap() when the kill switch is off', function (): void {
    Filters\expectAdded('rest_pre_dispatch')->once()->with('uamswp_rue_block_rest_users', PHP_INT_MAX, 3);
    Filters\expectAdded('rest_endpoints')->once()->with('uamswp_rue_guard_endpoints', PHP_INT_MAX);
    Actions\expectAdded('template_redirect')->once()->with('uamswp_rue_block_author_query', 1);
    Filters\expectAdded('wp_sitemaps_add_provider')->once()->with('uamswp_rue_remove_users_sitemap', 10, 2);
    Filters\expectAdded('oembed_response_data')->once()->with('uamswp_rue_scrub_oembed_author', 10);

    uamswp_rue_bootstrap();
});

it('loads a second copy of the file without a fatal and registers the hooks once', function (): void {
    // A stray copy in plugins/ beside the one in mu-plugins/. The child exits non-zero on the
    // "Cannot redeclare" fatal, which the helper's status check reports.
    $report = uamswp_rue_test_load_in_child(['ABSPATH' => '/'], 'second-copy');

    expect($report['registered'])->toBe(uamswp_rue_test_expected_hooks());
});
