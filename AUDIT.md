# Pre-deploy audit

Spec section 8, run 2026-09-23 before the plugin was written. Everything below
is either a finding with a file and line behind it, or is labelled as something
that still needs a live install to answer.

Sources: local clones of `uams-2020`, `UAMSWP-Find-a-Doc` and
`UAMSWP-People-Showcase`; GitHub code search across all 89 repositories in the
`UAMS-Web` organisation; shallow clones of `UAMSWP-Sentinel`,
`UAMSWP-Sentinel-configs` and `uamswp-seopress`; and one anonymous HTTP run
against `https://uamshealth.com`.

---

## 1. Who calls the routes this plugin closes

### `wp/v2/users` — two hits, neither of them blocked by this plugin

**`UAMS-Web/epic-to-uamshealth`**
`.claude/skills/uamshealth-provider-drafts/scripts/fill-provider.js:144`

```js
const users = await getJSON('/wp-json/wp/v2/users?search=uamsweb&per_page=10&context=edit');
```

Not anonymous. `getJSON` sends `credentials: 'same-origin'` with
`X-WP-Nonce: window.wpApiSettings.nonce` (line 17), and the file's own header
says it is pasted into a loaded `post-new.php?post_type=provider`. It is a
logged-in editor's browser, and `context=edit` already requires `list_users`,
so it is a strictly narrower request than the one FR1 still allows.
**No action.**

**`UAMS-Web/UAMSWP-Sentinel`**
`uams-sentinel/includes/class-live-hooks.php:243-256`, `class-live-rules.php:114`,
`admin/views/settings.php:23-25`

Sentinel does not *call* the route; it watches it. Three things follow, and
none were in the spec:

1. **Detection survives this deploy.** Sentinel's L6 rule ("Unauthenticated GET
   /wp-json/wp/v2/users from `$ip`", medium, one finding per IP per day) is on
   `rest_pre_dispatch` at priority 10, the same hook and priority this plugin
   uses. `apply_filters` runs every callback regardless of what an earlier one
   returned, so Sentinel still records the attempt after this plugin starts
   refusing it. The estate does not go blind by deploying this. Worth saying
   out loud, because the opposite is the natural assumption.

2. **Sentinel's own blocker has the case bug.** The optional
   `uams_sentinel_block_user_enum` toggle matches
   `'#^/wp/v2/users(?:/|$)#'` — no `i` flag, the same defect the spec found in
   the draft code. It is bypassable by `/wp/v2/Users`. Recommendation: leave
   that toggle **off** and let this plugin be the control. If it is already on
   somewhere, nothing breaks (both answer 401 and whichever runs second
   preserves the first's result), but it is not a substitute.

3. **Sentinel is not exposed to the WP 7.x batch fatal** described in section 4
   below, because its rule returns early unless `'GET' === $request->get_method()`
   and batch v1 cannot carry a GET. This plugin blocks every method, so it is
   exposed, and handles it.

No hits in `uams-2020`, `UAMSWP-Find-a-Doc` or `UAMSWP-People-Showcase`.

### `_embed` — nothing, anywhere

Zero hits org-wide for `?_embed`, `&_embed`, `_embed=true` and `_embed:`. Every
local match for the bare string is something else:

- `UAMSWP-Find-a-Doc` — `clinical_resource_nci_embed`, an ACF field
  (`includes/class.fad-custom-post.php:5113`, `templates/single-clinical-resource.php:300`,
  and the matching `acf-json` group). Not the REST parameter.
- `uams-2020` — `assets/scss/uams-supporting/file.scss:33`, a commented-out
  `.wp-block-file__embed` rule.

The spec's "a template relies on `_embed` for author names" risk does not
apply to any repository in the org. It can still apply to a headless consumer
outside GitHub; the live check in `bin/acceptance.sh` covers that.

### `?author=` — one hit, commented out

`uamswp-people-showcase/utils.php:299`

```php
// $link = $file . '?author=' . $psuser;
```

Nothing links numeric author URLs. **No action.**

---

## 2. Active plugins, and SEO author sitemaps

Per-install plugin lists cannot be read from here; that needs wp-admin or
`wp plugin list --status=active` on each of the 32 installs, and it should be
captured during the deploy. What the repositories and one live site do settle:

**SEOPress is in the estate and confirmed live.** `UAMS-Web/uamswp-seopress`
and `uamswp-seopress-pro` are org repositories, and `uamshealth.com` redirects
`/wp-sitemap.xml` to `/sitemaps.xml`, which is SEOPress's
`Render::sitemapShortcut()` (`src/Actions/Sitemap/Render.php:112-128`).

**SEOPress ships its own author sitemap, and this plugin does not touch it.**

- Served at `/author.xml`, registered only when
  `seopress_xml_sitemap_author_enable === '1'`
  (`src/Actions/Sitemap/Router.php:43-44`).
- Off unless someone turned it on: `SitemapOption::authorIsEnable()`
  (`src/Services/Options/SitemapOption.php:83-85`) reads an option that is unset
  by default.
- On `uamshealth.com` it is off — `/author.xml` returns 404.
- FR3 removes the *core* provider only. This is out of scope by the spec's own
  decision, so `bin/acceptance.sh` reports `/author.xml` as a **warning** rather
  than a failure: it will not block a deploy, and it will not be silent either.
  Fix by unchecking it in SEOPress > XML Sitemap on any site that answers 200.

**FR3 is still needed despite SEOPress.** SEOPress redirects `/wp-sitemap.xml`,
`/sitemap.xml` and `/sitemap_index.xml`, and nothing else. `/wp-sitemap-users-1.xml`
is served by core directly and is not on that list. (On `uamshealth.com` it
already 404s, so core sitemaps appear to be off there by some other means —
worth noting that FR3 will be a no-op on some sites and load-bearing on others.)

**No conflict between SEOPress and FR2.** Both are on `template_redirect`.
FR2's callback returns immediately unless `$_GET['author']` is set, so it never
stands between SEOPress and a sitemap URL.

---

## 3. Oldest PHP and WordPress in the estate

**PHP — corroborated, not measured.** UAMS Sentinel is deployed estate-wide as
an mu-plugin and declares `"php": ">=8.1"` (`uams-sentinel/composer.json`). That
is good evidence for the spec's 8.1 assumption but it is not a reading from any
install. Confirm with `wp eval 'echo PHP_VERSION;'` during the deploy.

**WordPress — unknown, and deliberately made not to matter.**

Nothing in the repositories records a WordPress version. Rather than block on
confirming the spec's "WP 6.4 or later" assumption, the plugin was written so
the assumption has nothing resting on it:

| API used | Available since |
|---|---|
| `rest_pre_dispatch` | WP 4.4 |
| `oembed_response_data` | WP 4.4 |
| `template_redirect`, `wp_safe_redirect`, `is_user_logged_in` | long before |
| `wp_sitemaps_add_provider` | WP 5.5 |
| `rest_convert_error_to_response` | WP 5.7, called behind `function_exists` |

The real floor is **WP 5.5**, which is what the plugin header declares, along
with `Requires PHP: 7.0`. Below 5.5 there are no core sitemaps to remove and the
filter simply never fires, so the plugin still does the other three jobs. This
is a deliberate deviation from spec section 4 and is called out in the README.

Verified by running the full suite against **WP 6.4.12** and **WP 7.1.2**, each
single-site and multisite: 66 tests, 170 assertions, green on all four.

Note that mu-plugins ignore `Requires at least` and `Requires PHP` — they always
load. The header is documentation, which is another reason to declare the floor
the code actually needs rather than the one the estate happens to run.

---

## 4. Found while building: a WordPress 7.x fatal, not in the spec

Any plugin that returns a `WP_Error` from `rest_pre_dispatch` causes a **500,
not a 401**, when the matching route appears inside a `/batch/v1` request on
WordPress 7.0 or later.

- `WP_REST_Server::dispatch()` converts a `WP_Error` from that filter with
  `error_to_response()` (`class-wp-rest-server.php:1079-1092`).
- `serve_batch_request_v1()` does not. It passes the filter's return value to
  `rest_post_dispatch` through `rest_ensure_response()`
  (`class-wp-rest-server.php:1866, 1894`), and `rest_ensure_response()` returns
  a `WP_Error` unchanged.
- Core's own `_wp_connectors_rest_settings_dispatch()` is registered on
  `rest_post_dispatch` as of WP 7.0 (`wp-includes/connectors.php:753`) and
  type-hints `WP_REST_Response`. A `WP_Error` reaching it is a `TypeError`.

This was reproduced by the batch test against WP 7.1.2 before the fix. The
plugin returns the already-converted response instead, which is byte-identical
on the wire on every other path. FR1 asks for a `WP_Error`; the deviation and
the reason are documented at the return statement and in the README.

Sentinel is not affected today, for the reason in section 1. Anything else in
the estate that short-circuits `rest_pre_dispatch` with a `WP_Error` is.

---

## 5. Pre-deploy baseline: what leaks right now

`bin/acceptance.sh https://uamshealth.com`, anonymous, 2026-09-23, before deploy.

| Check | Result |
|---|---|
| `/wp-json/wp/v2/users` | **200** — leaking |
| `/wp-json/wp/v2/Users` | **200** — the case bypass, confirmed on a live server |
| `/wp-json/wp/v2/USERS` | **200** |
| `/wp-json/wp/v2/users/1` | **200** |
| `/wp-json/wp/v2/users/` | **200** — trailing slash is served, so T4 is a real vector |
| `/?rest_route=/wp/v2/users` | **200** |
| `/?rest_route=/wp/v2/Users` | **200** |
| `/wp/v2/posts?_embed=author` | embedded author resolved |
| `/?author=1` | **301 to `/author/uamsweb/`** — slug leaked |
| oEmbed | `author_name=Chadley Uekman`, `author_url=.../author/chadley/` |
| `/wp-sitemap-users-1.xml` | 404 already |
| `/author.xml` (SEOPress) | 404, author sitemap off |
| `/wp-json/wp/v2/posts` | 200 (control) |

Both defects the spec identified in the draft code are confirmed against a
production BigScoots-hosted install, not just against core source.

---

## 6. Still open

**The "existing hourly external security monitor" in spec section 5 could not
be found.** Searched all 89 `UAMS-Web` repositories and the personal repositories
for monitor, sentinel, security, uptime and watch. What exists is:

- `UAMS-Web/UAMSWP-Sentinel` — in-WordPress, scheduled scan plus a rendered-page
  check, one Healthchecks.io heartbeat per site. Not an external hourly URL
  monitor.
- `bpmore/sky-sentinel` — a generic fork of the same plugin.

Someone needs to name the monitor. `bin/acceptance.sh` exits non-zero if any
check fails and takes `-f sites.txt`, so it drops into a cron entry or a CI
schedule without modification.

**Other live-only items for the deploy:**

- `wp plugin list --status=active` per install; flag any other SEO plugin with
  an author sitemap (Yoast's `author-sitemap.xml`, Rank Math's).
- `wp eval 'echo PHP_VERSION;'` per install.
- Whether BigScoots will do the optional edge rule in spec section 6. Unknown;
  needs a support ticket.

---

## Appendix: the 32 installs

From the directory names in `UAMS-Web/UAMSWP-Sentinel-configs`. Hostnames only —
those files hold per-site signing keys and webhook URLs and nothing was copied
out of them.

```
aging.uams.edu              angelsguidelines.com        cancer.uams.edu
communications.uams.edu     communityhealth.uams.edu    giving.uams.edu
go.uams.edu                 healthprofessions.uams.edu  hr.uams.edu
idhi.uams.edu               medicine.uams.edu           news.uams.edu
nursing.uams.edu            nwa.uams.edu                partnerships.uams.edu
pharmacy.uams.edu           psychiatry.uams.edu         publichealth.uams.edu
regionalcampuses.uams.edu   research.uams.edu           sites.uams.edu
students.uams.edu           tri.uams.edu                uamscaregiving.org
uamsdev.com                 uamshealth.com              uamshealth.dev
uamsonlinedev.com           uasys.uamsweb.com           web.uams.edu
uams-academy.bigscoots-staging.com
uams-pepper.bigscoots-wpo.com
```

32, matching the spec's count. `uamsdev.com`, `uamshealth.dev`,
`uamsonlinedev.com` and `uams-academy.bigscoots-staging.com` read as
development or staging and are the natural first targets for step 2 of the
deployment plan.
