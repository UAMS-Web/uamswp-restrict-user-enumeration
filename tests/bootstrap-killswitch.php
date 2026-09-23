<?php
/**
 * PHPUnit bootstrap for the kill-switch suite (T12).
 *
 * Defines UAMSWP_RUE_DISABLE before the plugin file is ever included, which is
 * what wp-config.php does on a live install. The constant cannot be undefined
 * once set, which is why this is a separate process with its own phpunit
 * config rather than one more test in the main suite.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

define( 'UAMSWP_RUE_DISABLE', true );

require_once __DIR__ . '/bootstrap-shared.php';

uamswp_rue_tests_bootstrap();
