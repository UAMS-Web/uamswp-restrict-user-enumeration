# UAMSWP Restrict User Enumeration

A single-file WordPress must-use plugin that closes every known unauthenticated
route to a username or an author slug, and leaves logged-in editing alone.

> ### Deploy this one file
>
> ```
> uamswp-restrict-user-enumeration.php  ->  wp-content/mu-plugins/uamswp-restrict-user-enumeration.php
> ```
>
> It is the only file in this repository that belongs on a server. Everything
> else is development and deployment tooling. See [Install](#install).

Built to [the spec](docs/SPEC.md). The pre-deploy audit it asked for is in
[docs/AUDIT.md](docs/AUDIT.md) and was done first.

## What it does

| Vector | Handling |
|---|---|
| `GET /wp-json/wp/v2/users`, `/wp/v2/users/{id}` | 401 when not logged in, route matched case-insensitively, enforced at **two independent gates** |
| `?rest_route=/wp/v2/users` | same check, it is the same route |
| `/batch/v1` carrying a users sub-request | same check: `rest_pre_dispatch` fires per sub-request |
| `_embed` on a posts request | same check: embedding dispatches a real sub-request |
| `/?author=N` | 301 to the home page, before `redirect_canonical` can answer with `/author/<slug>/` |
| `/wp-sitemap-users-1.xml` | core's `users` sitemap provider is not registered |
| `/wp-json/oembed/1.0/embed` | `author_name` becomes the site name, `author_url` becomes the home URL |

Deliberately **not** handled, per the spec: login error messages,
`/author/<slug>/` archive pages themselves, `dc:creator` in RSS, SEO-plugin
author sitemaps, and XML-RPC (already authenticated). The audit found SEOPress
across the estate with its own `/author.xml`, and All in One SEO on
`angelsguidelines.com` with its `author-sitemap.xml`. Both are off today;
`bin/acceptance.sh` reports the SEOPress one as a warning so it stays visible
without failing the deploy.

## Install

**One file, not this folder.** Upload `uamswp-restrict-user-enumeration.php`
and nothing else:

```
uamswp-restrict-user-enumeration.php  ->  wp-content/mu-plugins/uamswp-restrict-user-enumeration.php
```

That is the whole install. No options, no database writes, no admin UI, no
activation step.

Uploading the folder instead does not half-work, it **silently does nothing**.
`wp_get_mu_plugins()` (`wp-includes/load.php`) does a flat `readdir` of
`wp-content/mu-plugins/` and keeps only entries ending in `.php`; it never
recurses. A file one directory down is never included, so the site looks
entirely normal, nothing errors, and every route this plugin exists to close
stays open.

Everything else here is development scaffolding and belongs nowhere near a
server: `tests/`, `bin/`, `composer.json`, the phpunit and phpcs configs, this
README, and especially `docs/AUDIT.md`, which is a list of what is currently
exploitable on these exact sites. `wp-content/` is inside the web root.

`bin/acceptance.sh` is the one other file you will use and it runs from a
laptop, not the server.

## Rollback

**Delete the file.** On this estate that is the primary route, not the fallback:
BigScoots does not let us edit `wp-config.php` (the UAMS Sentinel loader says so
in its own header, which is why Sentinel reads its constants from a file in
`mu-plugins` instead).

The kill switch still works, it just needs somewhere to live. Any mu-plugin that
loads before this one can define it, and `wp_get_mu_plugins()` **sorts** the
directory, so a leading digit guarantees the order. Drop in
`0-uamswp-rue-disable.php`:

```php
<?php
// Loads before uamswp-restrict-user-enumeration.php because mu-plugins are
// loaded in sorted order, so the constant is set before the plugin reads it.
define( 'UAMSWP_RUE_DISABLE', true );
```

Delete that file to switch the plugin back on. It is worth preferring over
deleting the plugin when you want the code to stay put and auditable: during a
soak, say, or while proving that something else is the cause of a problem.

Where `wp-config.php` *is* editable, the constant goes there as normal.

Either way it is all or nothing: with the constant set the plugin registers no
hooks at all, and the kill-switch suite asserts all four vectors come back.

Do not add the `define()` to `uams-sentinel-config.php`. It would work, since that
file loads earlier, but Sentinel's own generator refuses to overwrite it and
its repo tells you never to regenerate it, so borrowing it couples two unrelated
rollbacks together.

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

90 tests over three suites: 5 Unit tests on Pest 5 with no WordPress, and 85
behavioral tests (79 Integration, 6 kill switch) on PHPUnit 9.6 over a real
WordPress. The kill switch needs its own process, because `UAMSWP_RUE_DISABLE`
is read when the file is first included and a constant cannot be undefined
afterwards. The two PHPUnit suites have their own Composer setup under
`tests/Integration/`, because WordPress' test suite supports PHPUnit 9 only.

```bash
composer install
composer setup:integration      # once
mysql -u root -h 127.0.0.1 -e 'CREATE DATABASE IF NOT EXISTS uamswp_rue_tests'
WP_TESTS_ABSPATH=/path/to/wordpress composer test   # all three suites
composer lint                   # PHPCS, WordPress ruleset
```

The Unit suite alone runs with `vendor/bin/pest` (the same as `composer test:unit`).

Verified green on **WP 6.4.12, 6.8.10, 7.0.6 and 7.1.2, single-site and
multisite**: 16 combinations, 85 tests and 221 assertions each. 6.8.10 and
7.0.6 are what the estate actually runs (see [docs/AUDIT.md](docs/AUDIT.md) section 3); 6.4.12 is the
spec's stated floor and 7.1.2 is current.

Beyond the spec's T1-T13, the suite asserts the things a regression would
quietly break:

- **Controls for each vector.** With the hook removed, `?author=1` really does
  redirect to `/author/enumtarget/`, `_embed` really does hand over the slug,
  and oEmbed really does name the author. Each leak is proven real before it is
  proven closed.
- **Core's behavior, not just ours.** A test reflects into
  `match_request_to_handler()` to assert core still routes `/wp/v2/Users` to the
  users controller. If that ever changes, the case handling is belt and braces
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
has to be read from an install is a wp-admin screen, and docs/AUDIT.md section 6
names each one. No step needs a CLI on the server.

```bash
bin/acceptance.sh https://one-site.uams.edu
bin/acceptance.sh -f sites.txt          # one origin per line
```

Sixteen checks per site, anonymous and without cookies. Exits non-zero if
anything fails, so it goes straight into a monitor. Run it against the
**canonical** origin; it warns if the host redirects, because a wall of 301s
says nothing about the plugin.

`WARN` is neither pass nor fail and does not affect the exit status. It means
either something real but out of scope (an SEO plugin's own author sitemap), or
that a check could not be exercised: `_embed` proves nothing on a site with no
posts, and saying so is more honest than banking it as a pass.

Every check is a GET except `check_batch`, which POSTs a users sub-request to
`/wp-json/batch/v1`. That one earns its exception: it is the only check that
says whether **this** plugin is loaded. A site may already have something that
answers 401 on the plain users URLs (`sites.uams.edu` did), and every other
check passes either way. Those blockers hook once per HTTP request, so they
only ever see the outer `/batch/v1` route and the users sub-request in the body
goes through untouched. `rest_pre_dispatch` runs per sub-request and catches it.

    401 rest_forbidden             loaded, blocking at dispatch
    400 rest_missing_callback_param  not loaded; something else answered the plain URLs
    500                            the WP 7.0+ fatal, see below

It creates nothing: the sub-request carries no body, it is refused before the
handler runs, and core requires `create_users` regardless.

Three sites already refused these routes before this plugin existed, for two
different reasons, and neither conflicts with it. `uamsonlinedev.com` and
`angelsguidelines.com` run Wordfence with its username-discovery option on;
`sites.uams.edu` has something else that has not been identified. docs/AUDIT.md
section 7 has the fingerprints. Deploy to all of them anyway: the Wordfence
setting is a checkbox somebody can untick.

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
docs/AUDIT.md section 4. The converted response is the same 401, with the same body,
everywhere else.

This is not hypothetical: **27 of the 32 installs are on WP 7.0.6 or 7.1.2
today**, so shipping FR1 literally would have answered 500 instead of 401 on
most of the estate from the first deploy.

**2. The declared floor is WP 5.5 and, since the organization's WordPress standard, PHP 8.2 (section 4).**

Section 4 states those minimums and asks for them to be confirmed against the
oldest install. The WordPress dependency was removed rather than guessed at: the
newest API the plugin uses is `wp_sitemaps_add_provider` (WP 5.5), and
`rest_convert_error_to_response` is called behind `function_exists`. The PHP
floor was 7.1 until the organization standard (UAMS-Web/uams-claude-skills#125)
set 8.2 for every WordPress repository, which is the oldest PHP read in the
estate; the code itself needs nothing newer than PHP 7.1 syntax, and a floor lint
on PHP 8.2 gates it. A security control that fails to load because of a version
guess is worse than one with a conservative header, and mu-plugins ignore these
headers anyway.

The estate was then measured: **the oldest WordPress is 6.8.10**, not 6.4, read
from 30 of the 32 sites over HTTP, and **PHP is 8.2**: 8.2.33 on
`sites.uams.edu`, 8.2.30 on `uamshealth.com`, read from Site Health. Nothing is
near the declared WordPress floor, and the two PHP readings sit at the PHP floor. See docs/AUDIT.md section 3.

**3. FR1 is enforced twice, not once.**

The spec says to implement on `rest_pre_dispatch`. That alone was found
insufficient in production. `rest_pre_dispatch` is a filter chain, and
**Advanced Custom Fields 6.4.2** hooks `ACF_Rest_Api::initialize()` to it at
priority 10 without ever returning its input:

```php
public function initialize( $response, $handler, $request ) {
	if ( ! acf_get_setting( 'rest_api_enabled' ) ) {
		return;          // null
	}
	// ... no return ...
}                        // null
```

Any 401 an earlier callback put in `$response` is discarded, and `dispatch()`
carries on as though nothing refused the request. On `uamshealth.com` that meant
`/wp/v2/users` answered 200 with the full staff list while the plugin was
installed, loaded and apparently working, with FR2, FR3 and FR4 all live,
because ACF only touches this one hook. It silently disables **any** plugin
short-circuiting `rest_pre_dispatch` at priority 10 or earlier, including UAMS
Sentinel's own `uams_sentinel_block_user_enum`.

So FR1 now has two gates. `rest_pre_dispatch` runs at `PHP_INT_MAX`, after
anything registered normally; and `rest_endpoints` wraps the users routes'
`permission_callback`, which is not a chain and cannot be discarded. Either one
alone refuses the request. `tests/test-hostile-chain.php` reproduces ACF's
shape and asserts both.

**4. T7 tests a POST, not a GET.**

T7 asks for "anonymous `POST /batch/v1` containing a users GET". Batch v1
cannot carry a GET: the `method` parameter on `/batch/v1` is an enum of
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

V3 is also newer than the spec assumes: `WP_REST_Users_Controller::$allow_batch`
does not exist in WP 6.4.12 and does in 6.8.10, so on 6.4 the users routes
cannot appear in a batch at all and core answers `rest_batch_not_allowed`. The
suite detects this with `get_route_options()` rather than assuming.

## Layout

One file ships. It is the only `.php` in the repository root, and everything
else is sorted by who uses it: `bin/` is run by whoever deploys, `docs/` is
read, `tests/` and the tool configs are for whoever changes the plugin.

| Path | | What it is |
|---|---|---|
| `uamswp-restrict-user-enumeration.php` | **ships** | The plugin. Goes to `wp-content/mu-plugins/`. |
| `README.md` | | This file. |
| `docs/SPEC.md` | | The spec this was built to. |
| `docs/AUDIT.md` | | Spec section 8, done before the code. Holds the pre-deploy baselines and what else already blocks these routes. |
| `docs/ACF-BUG-REPORT.md` | | The upstream ACF bug that made two gates necessary, written up for WP Engine. |
| `bin/acceptance.sh` | | Spec section 5. Run from a laptop against each site after deploy. |
| `tests/Unit/` | | Pest unit tests, no WordPress. |
| `tests/Integration/` | | The behavioral suite on PHPUnit 9.6 over real WordPress, with its own Composer setup. |
| `tests/Integration/killswitch/` | | FR5, with its own phpunit config because it needs its own process. |
| `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist` | | Tooling. |

Nothing outside the first row should ever reach a server. `wp-content/` is
inside the web root, and `docs/AUDIT.md` in particular is a list of what is
currently exploitable on these exact sites.


## Pre-commit guard

A hook in `.githooks/pre-commit` refuses a commit that stages a file such as a
`wp-config.php`, an SQL dump, a spreadsheet, an `.env` file or a key, since
git history is permanent and this repository is public. Install it once per
clone:

```bash
git config core.hooksPath .githooks
```

The deny list sits at the top of the hook. To allow a file that matches, change
the list in the same pull request that adds the file.

## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Brent Passmore
