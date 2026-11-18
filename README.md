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

Delete the file. Where `wp-config.php` cannot be edited, the `UAMSWP_RUE_DISABLE`
kill switch goes in an mu-plugin that sorts before this one; the wiki's
[Rolling back](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/wiki/Rolling-back) page has the file to drop in.

## Extending

The plugin has one filter and one action for extending it, documented on the
wiki's [Extending](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/wiki/Extending) page.

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

The file goes up by FTP; no step needs a shell on the server. The wiki carries
the rest: [Deploying to a site](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/wiki/Deploying-to-a-site) walks through a
deploy, [Acceptance checks](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/wiki/Acceptance-checks) explains every check
`bin/acceptance.sh` runs and what each result means, and
[Troubleshooting](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/wiki/Troubleshooting) covers the failures.

The pre-deploy baselines and what else on the estate already blocks these
routes are in [docs/AUDIT.md](docs/AUDIT.md).

## Deviations from the spec

Four, all deliberate, and each commented at the code that does it. The wiki's
[Deviations from the spec](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/wiki/Deviations-from-the-spec) page explains them:
the converted response instead of a raw `WP_Error` (FR1), the declared
WordPress and PHP floors, FR1 enforced at two gates, and T7 tested with a POST.

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
| `docs/wiki/` | | The wiki's pages, published to the repository's GitHub wiki by `.github/workflows/publish-wiki.yml`. |
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
