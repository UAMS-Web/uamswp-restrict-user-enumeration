# Spec: UAMSWP Restrict User Enumeration

**Type:** WordPress must-use plugin (single file)
**Slug:** `uamswp-restrict-user-enumeration`
**Target:** All UAMS WordPress multisite installs (32) and single-site installs, hosted on BigScoots
**Repo:** New repo in the UAMS-Web GitHub org (assumed; confirm name)
**Status:** Draft code exists (Appendix A) with two confirmed defects. This spec supersedes it.

## 1. Summary

Anonymous visitors can currently harvest WordPress usernames and author slugs through the REST API and several secondary routes. This plugin blocks every known unauthenticated enumeration route while leaving logged-in editing (including the block editor's author dropdown) untouched.

The draft code from chat is a usable starting point, but review against WordPress core source found two bugs that make it bypassable. Both are fixed in the requirements below:

1. **Case bypass (verified).** Core matches REST routes case-insensitively (`preg_match( '@^' . $route . '$@i', ... )` in `WP_REST_Server::match_request_to_handler`). The draft's regex is case-sensitive, so `/wp-json/wp/v2/Users` gets through.
2. **Author redirect never blocked (verified).** Core hooks `redirect_canonical` to `template_redirect` at priority 10. It redirects `?author=N` to `/author/<nicename>/` and exits before the draft's priority-10 hook runs, so the slug still leaks.

A third leak the draft missed is also covered: oEmbed responses include the author's display name and author archive URL (verified in `get_oembed_response_data()`).

## 2. Scope

### In scope

| # | Vector | Leaks | Handling |
|---|---|---|---|
| V1 | `GET /wp-json/wp/v2/users` and `/wp/v2/users/{id}` | Login-derived slug, display name, avatar hash, ID | Block when not logged in |
| V2 | `?rest_route=/wp/v2/users` | Same as V1 | Covered by V1 (route-level check) |
| V3 | `/batch/v1` containing users requests | Same as V1 | Covered by V1 (verified: `rest_pre_dispatch` also fires in `serve_batch_request_v1`) |
| V4 | `_embed` on posts (embedded author) | Same as V1 | Covered by V1 (verified: `embed_links()` calls `dispatch()`) |
| V5 | `/?author=N` | Author nicename via redirect | Intercept before `redirect_canonical` |
| V6 | `/wp-sitemap-users-1.xml` | Author archive URLs (slugs) | Remove core users sitemap provider |
| V7 | `/wp-json/oembed/1.0/embed` and oEmbed discovery | Display name, author archive URL | Replace with site name and home URL |

### Out of scope (document only, do not build)

- Login error messages that confirm whether a username exists
- `/author/<slug>/` archive pages themselves (the slug is public by design if author archives are in use)
- Author display names in RSS `dc:creator` and in theme templates
- SEO plugin author sitemaps (Yoast `author-sitemap.xml`, Rank Math). Flag in the audit (Section 8) if any install runs one.
- XML-RPC (`wp.getAuthors`, `wp.getUsers` require authentication already)

## 3. Functional Requirements

**FR1. REST users routes require authentication.**
When `is_user_logged_in()` is false, any REST request whose route matches `^/wp/v2/users(/.*)?$` **case-insensitively** returns `WP_Error( 'rest_forbidden', 'Authentication required.', [ 'status' => 401 ] )`. Implement on `rest_pre_dispatch`.

- Normalize the route before matching: lowercase, collapse repeated slashes, strip trailing slash.
- Use `is_user_logged_in()`, not `current_user_can( 'list_users' )`. Editors lack `list_users` but the block editor calls `/wp/v2/users?who=authors` for them.
- Logged-in requests must be completely unaffected.

**FR2. Numeric author queries do not reveal slugs.**
For anonymous front-end requests where `$_GET['author']` is present, redirect 301 to `home_url( '/' )`. Must run **before** `redirect_canonical`: hook `template_redirect` at priority `1` (or earlier via `parse_request`; developer's choice, with a test proving order).

**FR3. Core users sitemap is removed.**
`wp_sitemaps_add_provider` returns `false` for provider name `users`. `/wp-sitemap-users-1.xml` returns 404 and the sitemap index no longer lists it.

**FR4. oEmbed responses do not expose authors.**
Filter `oembed_response_data` so `author_name` is `get_bloginfo( 'name' )` and `author_url` is `home_url( '/' )` (these are core's own fallbacks when no author exists).

**FR5. Kill switch.**
If the constant `UAMSWP_RUE_DISABLE` is defined and true, the plugin registers no hooks. Allows emergency rollback per install through `wp-config.php` without a deploy.

**FR6. Extension point.**
Apply filter `uamswp_rue_block_rest_request` (bool, `WP_REST_Request`) after the route match so a site can allow a specific anonymous consumer if one is discovered. Default `true`.

**FR7. Observability.**
Fire `do_action( 'uamswp_rue_blocked', $vector, $request_or_null )` whenever a request is blocked (`$vector` is one of `rest`, `author_query`). No logging by default; this is a hook for later.

## 4. Non-functional Requirements

- Single PHP file, no dependencies, no database writes, no options, no admin UI.
- Must work as a must-use plugin (loaded from `wp-content/mu-plugins/` root; mu-plugins does not load subdirectories).
- PHP 8.1 or later and WordPress 6.4 or later (assumed; confirm against the oldest install in the estate).
- WordPress Coding Standards (PHPCS `WordPress` ruleset) clean.
- Plugin header must include Name, Description, Version, Author (UAMS Web Services), Requires at least, Requires PHP.

## 5. Tests

### Automated (PHPUnit with the WordPress test suite)

| ID | Case | Expected |
|---|---|---|
| T1 | Anonymous `GET /wp/v2/users` | 401, code `rest_forbidden` |
| T2 | Anonymous `GET /wp/v2/users/1` | 401 |
| T3 | Anonymous `GET /wp/v2/USERS` and `/wp/v2/Users/1` | 401 (regression test for the case bypass) |
| T4 | Anonymous `GET /wp/v2/users/` (trailing slash) | 401 |
| T5 | Editor-role user `GET /wp/v2/users?who=authors` | 200 |
| T6 | Anonymous `GET /wp/v2/posts?_embed=author` | 200 for posts; embedded author is an error object, not user data |
| T7 | Anonymous `POST /batch/v1` containing a users GET | Inner response 401 |
| T8 | Anonymous `GET /wp/v2/posts` | 200 (no collateral blocking) |
| T9 | Anonymous `?author=1` where user 1 has published posts | 301 to home; `redirect_canonical` never runs (regression test for the priority bug) |
| T10 | Users sitemap provider | Not registered |
| T11 | oEmbed data for a post | `author_name` equals site name, `author_url` equals home URL |
| T12 | `UAMSWP_RUE_DISABLE` true | No hooks registered; T1 returns 200 |
| T13 | Filter `uamswp_rue_block_rest_request` returns false | T1 returns 200 |

### Live acceptance (run against each site after deploy)

```bash
SITE="https://example.uams.edu"
for u in "/wp-json/wp/v2/users" "/wp-json/wp/v2/Users" "/?rest_route=/wp/v2/users" \
         "/wp-json/wp/v2/users/1" "/wp-sitemap-users-1.xml" "/?author=1"; do
  printf "%-32s %s\n" "$u" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$SITE$u")"
done
curl -s "$SITE/wp-json/oembed/1.0/embed?url=$SITE/" | grep -o '"author_[a-z]*":"[^"]*"'
```

Expected: 401, 401, 401, 401, 404, 301 to home, and oEmbed author fields showing the site name and home URL. Then open any post in the block editor as an Editor and confirm the Author dropdown populates.

Add these URLs to the existing hourly external security monitor so a regression (plugin removed, host config change) raises an alert.

## 6. Deployment

1. Pre-deploy audit (Section 8) must be complete.
2. Deploy to one low-traffic multisite first; run live acceptance on every subsite in that network.
3. Soak 48 hours; watch for editor reports of a missing Author dropdown and for front-end errors on sites that use `_embed`.
4. Roll to the remaining installs.
5. Rollback: define `UAMSWP_RUE_DISABLE` in `wp-config.php`, or delete the file.

Edge rule (optional, defense in depth): if a WAF or CDN fronts these sites, block `/wp-json/wp/v2/users` and any request whose `rest_route` parameter contains `/wp/v2/users` (both case-insensitive) when no `wordpress_logged_in_` cookie is present. The plugin remains the actual control. Whether BigScoots supports this at the server level is unknown; that needs a support ticket.

## 7. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A theme or plugin fetches `/wp/v2/users` anonymously (headless front end, author widgets) | Unknown until audit | Section 8 grep; FR6 filter for a targeted exception |
| A template relies on `_embed` for author names | Low to medium | Section 8 grep for `_embed`; render the name server-side instead |
| Sites intentionally link numeric `?author=` URLs | Low | Grep content for `?author=` |
| An SEO plugin publishes its own author sitemap | Unknown | Section 8 check; disable in that plugin's settings |
| `is_user_logged_in()` false for cookie requests without a REST nonce | Expected behavior | This is core's CSRF protection working, not a bug; legitimate editor requests send the nonce |

## 8. Pre-deploy Audit (Code should do this first)

1. In the UAMS-Web repos (uams-2020, UAMSWP-Find-a-Doc, UAMSWP-People-Showcase, UAMSWP-minervakb, and any others), search for `wp/v2/users`, `_embed`, and `?author=`. Report each hit with file, line, and whether it runs for anonymous visitors.
2. List active plugins per install and flag any SEO plugin with author sitemaps enabled.
3. Confirm the oldest PHP and WordPress versions in the estate against Section 4.

## Appendix A: Draft Code From Chat (baseline, do not ship as is)

Known defects: the REST regex lacks the `i` flag (FR1 bypass), the `template_redirect` hook runs after `redirect_canonical` (FR2 ineffective), and it has no oEmbed handling, kill switch, filter, or action (FR4 through FR7).

```php
<?php
/**
 * Plugin Name: UAMS Restrict REST Users Endpoint
 * Description: Blocks unauthenticated access to /wp/v2/users and user enumeration vectors.
 */

// 1. REST: require login for the users collection and single-user routes.
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( is_user_logged_in() ) {
		return $result;
	}
	if ( preg_match( '#^/wp/v2/users(/|$)#', $request->get_route() ) ) {
		return new WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 401 ) );
	}
	return $result;
}, 10, 3 );

// 2. Core sitemap: drop wp-sitemap-users-1.xml, which lists author slugs.
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return ( 'users' === $name ) ? false : $provider;
}, 10, 2 );

// 3. ?author=N enumeration: stop the redirect that reveals the author slug.
add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() && isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );
```

Also from chat: the live acceptance loop in Section 5 (extended here with the case-variant URL and the oEmbed check).

## Appendix B: Verification Basis

| Claim | Status | Source |
|---|---|---|
| REST route matching is case-insensitive | Verified | `wp-includes/rest-api/class-wp-rest-server.php`, `preg_match( '@^' . $route . '$@i', ... )` (WordPress trunk, fetched September 23, 2026) |
| `rest_pre_dispatch` fires for batch sub-requests and `_embed` | Verified | Same file: `serve_batch_request_v1()` and `embed_links()` both route through `dispatch()` or apply the filter directly |
| `redirect_canonical` is on `template_redirect` at priority 10 and redirects numeric `?author=` to the nicename URL | Verified | `wp-includes/default-filters.php`; `wp-includes/canonical.php` |
| `wp_sitemaps_add_provider` filter exists with `$name` | Verified | `wp-includes/sitemaps/class-wp-sitemaps-registry.php` |
| oEmbed data includes `author_name` and `author_url` from the post author | Verified | `wp-includes/embed.php`, `get_oembed_response_data()` |
| No UAMS repo calls `/wp/v2/users` anonymously | Assumed | Not checked; Section 8 item 1 |
| PHP 8.1 and WordPress 6.4 minimums | Assumed | Section 8 item 3 |
