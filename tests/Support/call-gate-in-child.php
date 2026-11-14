<?php

/**
 * Calls uamswp_rue_block_rest_users() in a fresh PHP process where
 * rest_convert_error_to_response() is not defined, as on WordPress below 5.7.
 *
 * A function that Brain Monkey defines in the test runner stays defined for the
 * rest of the run, so the raw-error path cannot be reached there. Prints a JSON
 * report of what the gate returned.
 */

declare(strict_types=1);

require __DIR__.'/wp-rest-stubs.php';

define('ABSPATH', '/');

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
    return false;
}

function apply_filters(string $hook, mixed $value): mixed
{
    return $value;
}

function do_action(): void
{
}

include dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php';

$result = uamswp_rue_block_rest_users(null, null, new WP_REST_Request('/wp/v2/users'));

echo json_encode([
    'converter_defined' => function_exists('rest_convert_error_to_response'),
    'class' => is_object($result) ? $result::class : gettype($result),
    'code' => $result instanceof WP_Error ? $result->get_error_code() : null,
    'status' => $result instanceof WP_Error ? ($result->get_error_data()['status'] ?? null) : null,
], JSON_THROW_ON_ERROR);
