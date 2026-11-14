<?php

/**
 * Unit bootstrap.
 *
 * The Unit suite runs with no WordPress: WordPress functions are mocked per
 * test with Brain Monkey. The Integration suite does not come through here; it
 * has its own Composer setup and bootstrap under tests/Integration.
 */

declare(strict_types=1);

/*
 * Runs at most once. phpunit.xml's `bootstrap` loads this file.
 */
if (defined('UAMSWP_RUE_TESTS_BOOTSTRAPPED')) {
    return;
}

define('UAMSWP_RUE_TESTS_BOOTSTRAPPED', true);

require_once dirname(__DIR__).'/vendor/autoload.php';

/*
 * The Unit suite.
 *
 * Patchwork first: it can only redefine functions declared in files loaded
 * after it, so loading it before the plugin is what lets a test mock one of the
 * plugin's own functions, not only WordPress'.
 */
require_once dirname(__DIR__).'/vendor/antecedent/patchwork/Patchwork.php';

/*
 * Plugin/theme files open with an ABSPATH guard. Define it so they load,
 * pointing at a directory that is not a WordPress install.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/uamswp_rue-tests-no-wordpress/');
}

/*
 * Load definitions+registrations inside one Brain Monkey session. Do not
 * define permanent WordPress function stubs in this file — Patchwork treats
 * this bootstrap as too early and Brain Monkey cannot redefine them in tests
 * (DefinedTooEarly). See the tests skill and the wordpress-theme template.
 */
Brain\Monkey\setUp();

Brain\Monkey\Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__).'/');
Brain\Monkey\Functions\when('plugin_dir_url')->justReturn('https://example.test/wp-content/plugins/'.basename(dirname(__DIR__)).'/');
Brain\Monkey\Functions\when('plugin_basename')->alias(static function ($file) {
    return basename(dirname($file)).'/'.basename($file);
});
Brain\Monkey\Functions\when('is_multisite')->justReturn(false);
Brain\Monkey\Functions\when('get_site_option')->alias(static function ($key, $default = false) {
    return $default;
});
Brain\Monkey\Functions\when('get_option')->alias(static function ($key, $default = false) {
    return $default;
});
Brain\Monkey\Functions\when('update_option')->justReturn(null);
Brain\Monkey\Functions\when('register_activation_hook')->justReturn(null);
Brain\Monkey\Functions\when('register_deactivation_hook')->justReturn(null);
Brain\Monkey\Functions\when('register_uninstall_hook')->justReturn(null);
Brain\Monkey\Functions\when('load_plugin_textdomain')->justReturn(null);
Brain\Monkey\Functions\when('is_admin')->justReturn(false);
Brain\Monkey\Functions\when('wp_enqueue_script')->justReturn(null);
Brain\Monkey\Functions\when('wp_enqueue_style')->justReturn(null);
Brain\Monkey\Functions\when('wp_register_script')->justReturn(null);
Brain\Monkey\Functions\when('wp_register_style')->justReturn(null);
Brain\Monkey\Functions\when('wp_localize_script')->justReturn(null);
Brain\Monkey\Functions\when('esc_html')->returnArg();
Brain\Monkey\Functions\when('esc_attr')->returnArg();
Brain\Monkey\Functions\when('esc_url')->returnArg();
Brain\Monkey\Functions\when('__')->returnArg();
Brain\Monkey\Functions\when('_e')->justReturn(null);
Brain\Monkey\Functions\when('esc_html__')->returnArg();
Brain\Monkey\Functions\when('esc_attr__')->returnArg();
Brain\Monkey\Functions\when('wp_die')->justReturn(null);
Brain\Monkey\Functions\when('status_header')->justReturn(null);
Brain\Monkey\Functions\when('nocache_headers')->justReturn(null);

// Core's get_file_data(): the first 8 KB, one case-insensitive multiline pattern per
// header. The plugin file reads its own Version through it when it loads.
Brain\Monkey\Functions\when('get_file_data')->alias(static function ($file, $headers) {
    $head = str_replace("\r", "\n", (string) file_get_contents($file, false, null, 0, 8192));
    $data = [];

    foreach ($headers as $field => $regex) {
        $found = preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*'.preg_quote($regex, '/').':(.*)$/mi', $head, $match);
        $data[$field] = $found ? trim($match[1]) : '';
    }

    return $data;
});

if (is_readable(dirname(__DIR__).'/uamswp-restrict-user-enumeration.php')) {
    require_once dirname(__DIR__).'/uamswp-restrict-user-enumeration.php';
}

Brain\Monkey\tearDown();

// cspell:ignore ABSPATH
