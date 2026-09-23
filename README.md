# UAMSWP Restrict User Enumeration

A single-file WordPress must-use plugin that closes every known unauthenticated
route to a username or an author slug, and leaves logged-in editing alone.

Built to [the spec](uamswp-restrict-user-enumeration-spec.md). The pre-deploy
audit it asked for is in [AUDIT.md](AUDIT.md) and was done first.

## What it does

| Vector | Handling |
|---|---|
| `GET /wp-json/wp/v2/users`, `/wp/v2/users/{id}` | 401 when not logged in, route matched case-insensitively |
| `?rest_route=/wp/v2/users` | same check, it is the same route |
| `/batch/v1` carrying a users sub-request | same check: `rest_pre_dispatch` fires per sub-request |
| `_embed` on a posts request | same check: embedding dispatches a real sub-request |
| `/?author=N` | 301 to the home page, before `redirect_canonical` can answer with `/author/<slug>/` |
| `/wp-sitemap-users-1.xml` | core's `users` sitemap provider is not registered |
| `/wp-json/oembed/1.0/embed` | `author_name` becomes the site name, `author_url` becomes the home URL |

Deliberately **not** handled, per the spec: login error messages,
`/author/<slug>/` archive pages themselves, `dc:creator` in RSS, SEO-plugin
author sitemaps, and XML-RPC (already authenticated). The audit found SEOPress
in the estate with its own `/author.xml`; `bin/acceptance.sh` reports it as a
warning so it is visible without failing the deploy.

## Install

mu-plugins does not load subdirectories, so the file goes in the root:

```
wp-content/mu-plugins/uamswp-restrict-user-enumeration.php
```

That is the whole install. No options, no database writes, no admin UI, no
activation step.

## Rollback

Two ways, either of which is immediate:

```php
// wp-config.php
define( 'UAMSWP_RUE_DISABLE', true );
```

or delete the file. With the constant set the plugin registers no hooks at all
— not a partial rollback, and the kill-switch test suite asserts all four
vectors come back.

## Extending

```php
// Let one known anonymous consumer through. Default is true, meaning block.
add_filter( 'uamswp_rue_block_rest_request', function ( $block, $request ) {
	return '/wp/v2/users' === $request->get_route() && some_check( $request )
		? false
		: $block;
}, 10, 2 );

// Fires on every refusal. $vector is 'rest' or 'author_query'.
add_action( 'uamswp_rue_blocked', function ( $vector, $request ) {
	// No logging by default; this is the hook for it.
}, 10, 2 );
```

## Tests

66 tests over two suites. The kill switch needs its own process, because
`UAMSWP_RUE_DISABLE` is read when the file is first included and a constant
cannot be undefined afterwards.

```bash
composer install
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
composer test        # both suites
composer lint        # PHPCS, WordPress ruleset
```

Verified green on **WP 6.4.12, 6.8.10, 7.0.6 and 7.1.2, single-site and
multisite** — 16 combinations, 66 tests and 170 assertions each. 6.8.10 and
7.0.6 are what the estate actually runs (see AUDIT.md section 3); 6.4.12 is the
spec's stated floor and 7.1.2 is current.

Beyond the spec's T1-T13, the suite asserts the things a regression would
quietly break:

- **Controls for each vector.** With the hook removed, `?author=1` really does
  redirect to `/author/enumtarget/`, `_embed` really does hand over the slug,
  and oEmbed really does name the author. Each leak is proven real before it is
  proven closed.
- **Core's behaviour, not just ours.** A test reflects into
  `match_request_to_handler()` to assert core still routes `/wp/v2/Users` to the
  users controller — if that ever changes, the case handling is belt and braces
  and the test says so.
- **No collateral damage.** Posts, pages, categories, types, the REST root, the
  namespace index and a `/wp/v2/users-online` lookalike route all still answer
  200 anonymously.
- **Nothing identifying leaks anywhere.** Several tests JSON-encode the whole
  response and assert the login and the display name appear nowhere in it,
  rather than checking one field.

## Deploying

These installs have no shell. The file goes up by FTP to
`wp-content/mu-plugins/`, the checks below run from a laptop, and anything that
has to be read from an install is a wp-admin screen — AUDIT.md section 6 names
each one. No step needs a CLI on the server.

```bash
bin/acceptance.sh https://one-site.uams.edu
bin/acceptance.sh -f sites.txt          # one origin per line
```

Anonymous, read-only, no cookies. Exits non-zero if anything fails, so it goes
straight into a monitor. Run it against the **canonical** origin; it warns if
the host redirects, because a wall of 301s says nothing about the plugin.

Then open a post in the block editor as an Editor and confirm the Author
dropdown still populates. That is the one thing the automated tests cover but
production configuration can still break, because it depends on a valid REST
nonce reaching the server.

The audit holds a pre-deploy baseline for `uamshealth.com` to compare against.

## Deviations from the spec

Three, all deliberate. Each is commented at the code that does it.

**1. It returns a converted response, not a raw `WP_Error` (FR1).**

FR1 asks for `WP_Error( 'rest_forbidden', ..., [ 'status' => 401 ] )`. The
plugin builds exactly that and then hands back
`rest_convert_error_to_response()` of it.

On WordPress 7.0+ a raw `WP_Error` returned from `rest_pre_dispatch` is a
**fatal** inside a `/batch/v1` request: `dispatch()` converts the error,
`serve_batch_request_v1()` does not, and core's own
`_wp_connectors_rest_settings_dispatch()` on `rest_post_dispatch` type-hints
`WP_REST_Response`. That turns the intended 401 into a 500 on a route an
anonymous caller picks. Reproduced against WP 7.1.2 before the fix; see
AUDIT.md section 4. The converted response is the same 401, with the same body,
everywhere else.

This is not hypothetical: **27 of the 32 installs are on WP 7.0.6 or 7.1.2
today**, so shipping FR1 literally would have answered 500 instead of 401 on
most of the estate from the first deploy.

**2. Declared floors are WP 5.5 and PHP 7.0, not WP 6.4 and PHP 8.1 (section 4).**

Section 4 states those minimums and asks for them to be confirmed against the
oldest install. The dependency was removed rather than guessed at: the newest
API the plugin uses is `wp_sitemaps_add_provider` (WP 5.5),
`rest_convert_error_to_response` is called behind `function_exists`, and nothing
needs PHP 8. A security control that fails to load because of a version guess is
worse than one with a conservative header, and mu-plugins ignore these headers
anyway.

The estate was then measured: **the oldest WordPress is 6.8.10**, not 6.4, read
from 30 of the 32 sites over HTTP. PHP is 8.1 or later, corroborated by Sentinel
running estate-wide with that requirement. Nothing is near the declared floor.
See AUDIT.md section 3.

**3. T7 tests a POST, not a GET.**

T7 asks for "anonymous `POST /batch/v1` containing a users GET". Batch v1
cannot carry a GET — the `method` parameter on `/batch/v1` is an enum of
`POST, PUT, PATCH, DELETE` (`class-wp-rest-server.php:131-135`), so core
rejects it with a 400 during parameter validation, before any filter runs. The
suite asserts that constraint explicitly in
`test_batch_v1_does_not_accept_get()`, so it is documented rather than assumed,
and tests the batch vector with a `POST /wp/v2/users` sub-request instead. That
still proves what T7 is for: `rest_pre_dispatch` fires for batch sub-requests,
so one route check covers the batch path.

This also means spec vector **V3 was never a read-enumeration route**. It is
still worth covering, since a batch write to `/wp/v2/users` is a write to the
user table.

## Layout

```
uamswp-restrict-user-enumeration.php   the plugin; this is the only file that ships
AUDIT.md                               spec section 8, done before the code
bin/acceptance.sh                      spec section 5, live checks
bin/install-wp-tests.sh                WordPress test suite installer
tests/                                 main suite
tests/killswitch/                      FR5, separate process
```
