<?php

declare(strict_types=1);
/**
 * Plugin Name:       UAMSWP Restrict User Enumeration
 * Plugin URI:        https://github.com/UAMS-Web/uamswp-restrict-user-enumeration
 * Description:       Blocks every known unauthenticated username and author-slug enumeration route (REST users endpoints, numeric ?author= queries, the core users sitemap, and oEmbed author fields) while leaving logged-in editing untouched.
 * Version:           1.1.0
 * Author:            Brent Passmore
 * Author URI:        https://communications.uams.edu/web/
 * Requires at least: 5.5
 * Requires PHP:      7.0
 * License:           GPL-2.0-or-later
 *
 * Must-use plugin. Drop this single file in wp-content/mu-plugins/ (the root,
 * not a subdirectory: mu-plugins does not recurse).
 *
 * Emergency rollback: delete this file, or define( 'UAMSWP_RUE_DISABLE', true ).
 * No hooks are then registered at all.
 *
 * On hosting that does not allow editing wp-config.php, which includes this
 * estate, put the define in an mu-plugin that sorts before this one, such as
 * 0-uamswp-rue-disable.php. wp_get_mu_plugins() sorts the directory, so a
 * leading digit is enough to guarantee it loads first. See the README.
 */
if (! defined('ABSPATH')) {
    exit;
}

// Loaded twice (mu-plugins plus a stray copy in plugins/) would be a fatal
// redeclare. Second copy returns quietly instead.
if (defined('UAMSWP_RUE_VERSION')) {
    return;
}

define('UAMSWP_RUE_VERSION', '1.1.0');

/**
 * Whether the wp-config.php kill switch is set.
 */
function uamswp_rue_disabled(): bool
{
    return defined('UAMSWP_RUE_DISABLE') && UAMSWP_RUE_DISABLE;
}

/**
 * Normalize a REST route so the same request cannot be spelled two ways.
 *
 * Lowercases, collapses repeated slashes, and forces exactly one leading and
 * no trailing slash. Core matches registered routes case-insensitively
 * (`preg_match( '@^' . $route . '$@i', ... )` in
 * WP_REST_Server::match_request_to_handler), so a case-sensitive check here
 * would let /wp/v2/Users through to the same handler.
 *
 * strtr rather than strtolower: strtolower was locale-sensitive before PHP
 * 8.2, and in a Turkish locale it maps I to a dotless i, which would undo
 * exactly the fix this function exists for.
 *
 * @param  string  $route  Raw route from WP_REST_Request::get_route().
 * @return string Normalized route, always starting with a slash.
 */
function uamswp_rue_normalize_route($route): string
{
    $route = strtr((string) $route, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

    $collapsed = preg_replace('#/+#', '/', $route);
    if (is_string($collapsed)) {
        $route = $collapsed;
    }

    return '/'.trim($route, '/');
}

/**
 * Whether a normalized route is one of the core users routes.
 *
 * The `i` modifier is redundant after uamswp_rue_normalize_route() lowercases
 * the route, and is kept on purpose: a missing `i` here is the bypass this
 * plugin was written to close, so the check does not depend on one function.
 *
 * @param  string  $route  Normalized route.
 */
function uamswp_rue_is_users_route($route): bool
{
    return preg_match('#^/wp/v2/users(/.*)?$#i', $route) === 1;
}

/**
 * Refuse the REST users routes to anonymous callers.
 *
 * Runs on rest_pre_dispatch, which also fires for /batch/v1 sub-requests and
 * for the sub-requests _embed makes, so those routes are covered by this one
 * check rather than by a separate rule each.
 *
 * is_user_logged_in() rather than current_user_can( 'list_users' ): Editors do
 * not have list_users, but the block editor calls /wp/v2/users?who=authors on
 * their behalf and the Author dropdown is empty without it.
 *
 * @param  mixed  $result  Dispatch result to override, or null.
 * @param  WP_REST_Server  $server  Server instance (unused).
 * @param  WP_REST_Request  $request  Current request.
 * @return mixed A 401 response to block the request, otherwise $result untouched.
 */
function uamswp_rue_block_rest_users($result, $server, $request)
{
    if (is_user_logged_in()) {
        return $result;
    }

    if (! $request instanceof WP_REST_Request) {
        return $result;
    }

    if (! uamswp_rue_is_users_route(uamswp_rue_normalize_route($request->get_route()))) {
        return $result;
    }

    /**
     * Allow one anonymous consumer of the REST users routes.
     *
     * Returning false lets the request through to the normal handler. Intended
     * for a headless front end or a widget found during the pre-deploy audit,
     * scoped as tightly as that consumer allows.
     *
     * @param  bool  $block  Whether to refuse the request. Default true.
     * @param  WP_REST_Request  $request  The request being refused.
     */
    if (! apply_filters('uamswp_rue_block_rest_request', true, $request)) {
        return $result;
    }

    /**
     * Fires when a request is refused.
     *
     * @param  string  $vector  Which route was blocked: 'rest' or 'author_query'.
     * @param  WP_REST_Request|null  $request  The request, when there is one.
     */
    do_action('uamswp_rue_blocked', 'rest', $request);

    $error = new WP_Error(
        'rest_forbidden',
        'Authentication required.',
        ['status' => 401]
    );

    /*
     * Handed back as an already-converted response rather than as the WP_Error
     * itself, which is the same 401 on the wire and is safe on one path where
     * the raw error is not.
     *
     * WP_REST_Server::dispatch() converts a WP_Error from this filter with
     * error_to_response() before returning it. serve_batch_request_v1() does
     * not: it passes whatever the filter returned to rest_post_dispatch
     * through rest_ensure_response(), which leaves a WP_Error alone. Core's
     * own _wp_connectors_rest_settings_dispatch() is on rest_post_dispatch as
     * of WP 7.0 and type-hints WP_REST_Response, so a WP_Error reaching it is
     * a TypeError: a 500 on a route an anonymous caller chooses, instead of
     * the 401 this plugin exists to send.
     *
     * rest_convert_error_to_response() is WP 5.7+. Below that there is no
     * connectors filter to fatal, so the raw error is fine.
     */
    return function_exists('rest_convert_error_to_response')
        ? rest_convert_error_to_response($error)
        : $error;
}

/**
 * Refuse the users routes at the route's own permission callback.
 *
 * The second of two independent gates, and the one that cannot be undone by
 * anything in a filter chain: it replaces the permission callback the route
 * was registered with, so core asks it during respond_to_request() and honours
 * the WP_Error it returns.
 *
 * It exists because the first gate was observed being silently switched off in
 * production. Advanced Custom Fields 6.4.2 hooks ACF_Rest_Api::initialize() to
 * rest_pre_dispatch at priority 10 and never returns its input -- both the
 * early `return;` and falling off the end yield null -- so any 401 handed to it
 * by an earlier callback at the same priority is discarded and dispatch()
 * carries on as though nothing refused the request. That silently disables
 * every rest_pre_dispatch short-circuit registered at or before priority 10,
 * not just this one.
 *
 * Batch sub-requests and the sub-requests _embed makes both run
 * respond_to_request(), so this gate covers them as well.
 *
 * @param  array<string, array<int, array<string, mixed>>>  $endpoints  Registered routes.
 * @return array<string, array<int, array<string, mixed>>>
 */
function uamswp_rue_guard_endpoints($endpoints)
{
    if (! is_array($endpoints)) {
        return $endpoints;
    }

    foreach ($endpoints as $route => $handlers) {
        if (! is_array($handlers)) {
            continue;
        }

        if (! uamswp_rue_is_users_route(uamswp_rue_normalize_route($route))) {
            continue;
        }

        foreach ($handlers as $index => $handler) {
            if (! is_array($handler) || ! array_key_exists('permission_callback', $handler)) {
                continue;
            }

            $endpoints[$route][$index]['permission_callback'] =
                uamswp_rue_wrap_permission_callback($handler['permission_callback']);
        }
    }

    return $endpoints;
}

/**
 * Wrap one permission callback so anonymous callers are refused first.
 *
 * A logged-in caller reaches the original callback untouched, so an Editor's
 * Author dropdown and every other authenticated use behave exactly as core
 * decided they should.
 *
 * @param  callable|null  $original  The callback the route was registered with.
 * @return callable
 */
function uamswp_rue_wrap_permission_callback($original)
{
    return function ($request) use ($original) {
        if (! is_user_logged_in()) {
            /** This filter is documented in uamswp-restrict-user-enumeration.php */
            if (apply_filters('uamswp_rue_block_rest_request', true, $request)) {
                /** This action is documented in uamswp-restrict-user-enumeration.php */
                do_action('uamswp_rue_blocked', 'rest', $request);

                return new WP_Error(
                    'rest_forbidden',
                    'Authentication required.',
                    ['status' => 401]
                );
            }
        }

        if (is_callable($original)) {
            return call_user_func($original, $request);
        }

        // A route registered without a usable callback is public by default in
        // core; preserve that rather than inventing a refusal for logged-in users.
        return true;
    };
}

/**
 * Send anonymous ?author=N requests to the home page before the slug leaks.
 *
 * Priority 1, because core hooks redirect_canonical() to template_redirect at
 * priority 10 and it redirects ?author=N to /author/<nicename>/ and exits. A
 * hook at 10 registered after core's never runs, and the slug is in the
 * Location header either way.
 */
function uamswp_rue_block_author_query(): void
{
    if (is_user_logged_in()) {
        return;
    }

    // Presence is the whole test; the value is never read, so there is nothing
    // to sanitize and no form submission to carry a nonce.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (! isset($_GET['author'])) {
        return;
    }

    /** This action is documented in uamswp-restrict-user-enumeration.php */
    do_action('uamswp_rue_blocked', 'author_query', null);

    wp_safe_redirect(home_url('/'), 301);
    exit;
}

/**
 * Drop the core users sitemap, which is a list of author archive URLs.
 *
 * Removes /wp-sitemap-users-1.xml and its entry in the sitemap index.
 *
 * @param  WP_Sitemaps_Provider|false  $provider  Provider about to be registered.
 * @param  string  $name  Provider name.
 * @return WP_Sitemaps_Provider|false
 */
function uamswp_rue_remove_users_sitemap($provider, $name)
{
    return ($name === 'users') ? false : $provider;
}

/**
 * Replace the author fields in oEmbed responses with the site's own.
 *
 * Core puts the author's display name in author_name and the author archive
 * URL, which carries the nicename, in author_url. Both are served to anonymous
 * callers at /wp-json/oembed/1.0/embed, and both are set unconditionally in
 * get_oembed_response_data().
 *
 * @param  array  $data  Prepared oEmbed response data.
 * @return array
 */
function uamswp_rue_scrub_oembed_author($data)
{
    if (! is_array($data)) {
        return $data;
    }

    if (isset($data['author_name'])) {
        $data['author_name'] = get_bloginfo('name');
    }

    if (isset($data['author_url'])) {
        $data['author_url'] = home_url('/');
    }

    return $data;
}

/**
 * Register every hook, unless the kill switch says not to.
 */
function uamswp_rue_bootstrap(): void
{
    if (uamswp_rue_disabled()) {
        return;
    }

    /*
     * PHP_INT_MAX, not 10. rest_pre_dispatch is a filter chain, so a later
     * callback that fails to return its input throws away whatever an earlier
     * one decided. ACF 6.4.2 does exactly that at priority 10, which was found
     * silently disabling this gate in production. Running last means nothing
     * registered normally can discard the refusal.
     *
     * Paired with the rest_endpoints gate below, which does not depend on the
     * filter chain at all. Either alone refuses the request; both together mean
     * one more badly-behaved plugin is not enough to reopen the route.
     */
    add_filter('rest_pre_dispatch', 'uamswp_rue_block_rest_users', PHP_INT_MAX, 3);
    add_filter('rest_endpoints', 'uamswp_rue_guard_endpoints', PHP_INT_MAX);
    add_action('template_redirect', 'uamswp_rue_block_author_query', 1);
    add_filter('wp_sitemaps_add_provider', 'uamswp_rue_remove_users_sitemap', 10, 2);
    add_filter('oembed_response_data', 'uamswp_rue_scrub_oembed_author', 10);
}

uamswp_rue_bootstrap();
