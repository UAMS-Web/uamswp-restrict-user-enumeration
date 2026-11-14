<?php

/**
 * Bootstrap for the kill-switch suite (T12).
 *
 * Defines UAMSWP_RUE_DISABLE before the plugin file is ever included, which is
 * what wp-config.php (or an mu-plugin that sorts first) does on a live
 * install. The constant cannot be undefined once set, which is why this is a
 * separate process with its own phpunit config rather than one more test in
 * the main suite.
 */

declare(strict_types=1);

define('UAMSWP_RUE_DISABLE', true);

require_once dirname(__DIR__).'/bootstrap.php';
