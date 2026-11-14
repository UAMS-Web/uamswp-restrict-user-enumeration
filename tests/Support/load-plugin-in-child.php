<?php

/**
 * Loads the plugin file in a fresh PHP process and reports what happened.
 *
 * The plugin reads its constants once, when the file is first included, and
 * ends the request with `exit` when ABSPATH is missing, so neither can be
 * exercised inside the test runner's own process. BootstrapTest runs this
 * script with `PHP_BINARY`, passing the constants to define as JSON in the first
 * argument, and reads the JSON report it prints in the shutdown function.
 */

declare(strict_types=1);

/** @var list<array{string, string, mixed, int, int}> $uamswp_rue_registered */
$uamswp_rue_registered = [];

$uamswp_rue_defines = json_decode((string) ($argv[1] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

foreach ($uamswp_rue_defines as $uamswp_rue_name => $uamswp_rue_value) {
    define($uamswp_rue_name, $uamswp_rue_value);
}

/**
 * Records a filter registration instead of making one.
 */
function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['uamswp_rue_registered'][] = ['filter', $hook, $callback, $priority, $accepted_args];

    return true;
}

/**
 * Records an action registration instead of making one.
 */
function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['uamswp_rue_registered'][] = ['action', $hook, $callback, $priority, $accepted_args];

    return true;
}

/**
 * Stands in for core's reader; the report shows whether it was asked.
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function get_file_data(string $file, array $headers): array
{
    $GLOBALS['uamswp_rue_read_header'] = true;

    return ['Version' => 'from-header'];
}

register_shutdown_function(static function (): void {
    echo json_encode([
        'registered' => $GLOBALS['uamswp_rue_registered'],
        'version' => defined('UAMSWP_RUE_VERSION') ? UAMSWP_RUE_VERSION : null,
        'read_header' => $GLOBALS['uamswp_rue_read_header'] ?? false,
    ], JSON_THROW_ON_ERROR);
});

include dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php';
