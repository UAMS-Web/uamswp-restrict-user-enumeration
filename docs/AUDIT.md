# Pre-deploy audit

Spec section 8, run 2026-09-23 before the plugin was written. Everything below
is either a finding with a file and line behind it, or is labelled as something
that still needs a live install to answer.

Sources: local clones of `uams-2020`, `UAMSWP-Find-a-Doc` and
`UAMSWP-People-Showcase`; GitHub code search across all 89 repositories in the
`UAMS-Web` organisation; shallow clones of `UAMSWP-Sentinel`,
`UAMSWP-Sentinel-configs` and `uamswp-seopress`; and anonymous HTTP against the
estate — a full vector run on `<primary-site>` and a WordPress version sweep of
all 32 sites.

These installs have no shell, so nothing below was gathered with a CLI on a
server and nothing below asks anyone to use one. Every open item is a URL or a
named wp-admin screen.

---

## 1. Who calls the routes this plugin closes

### `wp/v2/users` — two hits, neither of them blocked by this plugin

**`UAMS-Web/epic-to-uamshealth`**
`.claude/skills/uamshealth-provider-drafts/scripts/fill-provider.js:144`

```js
const users = await getJSON('/wp-json/wp/v2/users?search=<author-slug>&per_page=10&context=edit');
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

**Partly answered over HTTP.** `/wp-json/` lists every registered REST
namespace, anonymously, and plugins that register routes name themselves there:

```bash
curl -s "https://<site>/wp-json/" | jq -r '.namespaces[]'
```

That is not the full active-plugin list — a plugin with no REST routes is
invisible to it — but it settled the questions this audit actually needed, and
it is a good first pass before anyone opens 32 wp-admins. The sweep is in
section 7. Plugins with no REST routes still need **Plugins > Installed
Plugins > Active**, plus **Network Admin > Plugins** on each multisite.

What the repositories and the sweep settle:

**SEOPress is on 30 of the 31 sites that answered**, confirmed by the
`seopress/v1` namespace, and `<primary-site>` redirects `/wp-sitemap.xml` to
`/sitemaps.xml`, which is SEOPress's `Render::sitemapShortcut()`
(`src/Actions/Sitemap/Render.php:112-128`).

**The exception is `angelsguidelines.com`, which runs All in One SEO instead**
(`aioseoTableOfContents/v1`), on an otherwise unrelated stack — welaunch,
themeco, gtm4wp. Section 8 asked to flag Yoast or Rank Math; AIOSEO is the one
actually present, and it has an `author-sitemap.xml` of its own. On that site it
is not serving: `/author-sitemap.xml` returns 302 and `/sitemap.xml` redirects
to core's `/wp-sitemap.xml`, so AIOSEO's sitemap module is off. Worth
re-checking if anyone turns it on.

**SEOPress ships its own author sitemap, and this plugin does not touch it.**

- Served at `/author.xml`, registered only when
  `seopress_xml_sitemap_author_enable === '1'`
  (`src/Actions/Sitemap/Router.php:43-44`).
- Off unless someone turned it on: `SitemapOption::authorIsEnable()`
  (`src/Services/Options/SitemapOption.php:83-85`) reads an option that is unset
  by default.
- On `<primary-site>` it is off — `/author.xml` returns 404.
- FR3 removes the *core* provider only. This is out of scope by the spec's own
  decision, so `bin/acceptance.sh` reports `/author.xml` as a **warning** rather
  than a failure: it will not block a deploy, and it will not be silent either.
  Fix by unchecking it in SEOPress > XML Sitemap on any site that answers 200.

**FR3 is still needed despite SEOPress.** SEOPress redirects `/wp-sitemap.xml`,
`/sitemap.xml` and `/sitemap_index.xml`, and nothing else. `/wp-sitemap-users-1.xml`
is served by core directly and is not on that list. (On `<primary-site>` it
already 404s, so core sitemaps appear to be off there by some other means —
worth noting that FR3 will be a no-op on some sites and load-bearing on others.)

**No conflict between SEOPress and FR2.** Both are on `template_redirect`.
FR2's callback returns immediately unless `$_GET['author']` is set, so it never
stands between SEOPress and a sitemap URL.

---

## 3. Oldest PHP and WordPress in the estate

There is no shell on these installs, so both halves of this were approached as
an HTTP question first and a wp-admin question second.

### WordPress — measured, 30 of 32, over HTTP

Feeds carry the version in their `<generator>` tag, so the whole estate can be
read anonymously from a laptop with no wp-admin and no CLI:

```bash
curl -sL "https://<site>/feed/" | grep -oE '\?v=[0-9.]+'
```

Swept 2026-09-23:

| Version | Installs |
|---|---|
| **7.1.2** | <dev-site> |
| **7.0.6** | 26 installs — aging, cancer, communications, communityhealth, giving, go, healthprofessions, hr, medicine, nursing, nwa, partnerships, pharmacy, psychiatry, publichealth, regionalcampuses, research, sites, students, tri, uams-academy, uamsdev, uamshealth, uamsonlinedev, uasys, web |
| **6.8.10** | idhi, news, uamscaregiving |
| unread | angelsguidelines.com (200, no feed), uams-pepper.bigscoots-wpo.com (no response) |

**The oldest WordPress in the estate is 6.8.10, not 6.4.** The spec's assumption
was wrong in the safe direction. Two consequences:

1. **The WP 7.x batch fatal in section 4 is not a forward-looking concern, it is
   a live one.** `_wp_connectors_rest_settings_dispatch()` arrived in WP 7.0, so
   **27 of the 32 installs have it today**. Shipping FR1's literal `WP_Error`
   would have turned the intended 401 into a 500 on most of the estate on day
   one.
2. Nothing in the estate is anywhere near the plugin's floor, so the version
   question carries no risk either way.

The two unread sites should be checked at **Tools > Site Health > Info >
WordPress** when someone is next in their wp-admin. Neither is a production
`uams.edu` site.

### PHP — measured, 8.2.x

BigScoots strips `X-Powered-By` (`server: BigScoots Gateway/1.0.0` is all that
comes back) and the Site Health REST endpoints need authentication, so this one
cannot be read over HTTP. It came from **Tools > Site Health > Info > Server >
PHP version**, read 2026-09-23:

| Install | PHP |
|---|---|
| `sites.uams.edu` (and the subsites it serves) | **8.2.33** |
| `<primary-site>` | **8.2.30** |

**8.2, not the 8.1 the spec assumed**, and comfortably above it. Sentinel's
`"php": ">=8.1"` was a floor, not a reading, and it held.

**The two differ by patch level, which is the more useful finding.** These are
separate servers rather than one host serving the estate, so "the PHP version"
is not a single fact and a per-install reading is the only accurate one. The
remaining 30 are unread; the BigScoots control panel lists PHP per site and is
the cheaper place to collect them than 30 trips through wp-admin.

Incidentally this retires a hazard the plugin guards against anyway:
`strtolower()` stopped being locale-sensitive in PHP 8.2, so the Turkish-locale
case-folding problem that `uamswp_rue_normalize_route()` uses `strtr()` to avoid
cannot occur on these servers. The code does not depend on that being true.

### Why it does not gate the deploy either way

The plugin was written so nothing rests on either answer:

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

Verified by running the full suite against **WP 6.4.12, 6.8.10, 7.0.6 and
7.1.2**, each single-site and multisite: 16 runs, 66 tests and 170 assertions
apiece, all green. 6.8.10 and 7.0.6 are the versions the estate actually runs;
6.4.12 is the spec's stated floor and 7.1.2 is current, so the bracket holds on
both sides of what is deployed.

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

**This is live, not forward-looking.** The version sweep in section 3 puts 27
of the 32 installs on WP 7.0.6 or 7.1.2, so most of the estate has the
connectors filter today. Shipping FR1 literally would have answered 500 instead
of 401 on those sites from the first deploy.

Sentinel is not affected, for the reason in section 1. Anything else in the
estate that short-circuits `rest_pre_dispatch` with a `WP_Error` is.

---

## 5. Pre-deploy baseline: what leaks right now

`bin/acceptance.sh https://<primary-site>`, anonymous, 2026-09-23, before deploy.

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
| `/?author=1` | **301 to `/author/<author-slug>/`** — slug leaked |
| oEmbed | `author_name=<staff name>`, `author_url=.../author/<author-slug>/` |
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

**Other items for the deploy.** There is no shell on these installs, so each of
these is a wp-admin screen or an HTTP request, never a `wp` command:

- **Active plugins per install** — Plugins > Installed Plugins > Active, and
  Network Admin > Plugins on each multisite. Flag any other SEO plugin with an
  author sitemap; check Yoast's `/author-sitemap.xml` and Rank Math's
  `/author-sitemap.xml` the same way `bin/acceptance.sh` already checks
  SEOPress's `/author.xml`, and turn the feature off in the plugin's own
  settings rather than trying to block the URL.
- **PHP version per install** — Tools > Site Health > Info > Server.
- **WordPress version for the two unread sites** — Tools > Site Health > Info >
  WordPress, for `angelsguidelines.com` and `uams-pepper.bigscoots-wpo.com`.
  The other 30 were read over HTTP in section 3.
- **The optional edge rule in spec section 6** — whether BigScoots will do it at
  the server level is unknown and needs a support ticket. The plugin remains
  the actual control either way.

**Deployment is by FTP**, the same path `UAMSWP-Sentinel` uses for its own
mu-plugin: upload `uamswp-restrict-user-enumeration.php` to
`wp-content/mu-plugins/`.

**Rollback is deleting the file.** Spec section 6 offers `wp-config.php` as the
other route, and on this estate that route does not exist: BigScoots does not
allow editing `wp-config.php`. UAMS Sentinel's loader says so in its own header,
which is why Sentinel reads its constants from
`mu-plugins/uams-sentinel-config.php` rather than from `wp-config.php`.

The kill switch still works, it just needs somewhere to live.
`wp_get_mu_plugins()` sorts the directory before including anything, so an
mu-plugin named with a leading digit is guaranteed to load first and can define
the constant. The README has the file. Neither route needs a shell.

---

## 7. What else already blocks these routes

Three of the 32 sites answered 401 on `/wp/v2/users` before this plugin was
deployed anywhere. They are not all the same thing, and the difference matters.

### Wordfence, on `uamsonlinedev.com` and `angelsguidelines.com`

Both carry the `wordfence/v1` and `wordfence-login-security/v1` namespaces; no
other site in the estate does. The behaviour is Wordfence's **"Prevent
discovery of usernames through '/?author=N' scans, the oEmbed API, the
WordPress REST API, and WordPress XML Sitemaps"** option, which is on by
default in both the free and paid versions.

The fingerprint is distinctive — it reuses core's own wording rather than
inventing a message:

| Request | Answer |
|---|---|
| `GET /wp/v2/users` | 401 `rest_user_cannot_view`, "Sorry, you are not allowed to list users." |
| `GET /wp/v2/users/1` | 404 `rest_user_invalid_id` |
| `?author=1` | 404 |
| `_embed=author` | embedded author is `rest_user_invalid_id` |
| users sitemap | blocked |
| **batch sub-request** | **401 — Wordfence does catch this** |

Note the last row. Wordfence blocks per dispatch, so it covers the batch
envelope, which is more than the `sites.uams.edu` blocker manages. It also
answers `rest_user_cannot_view` to a `POST` that was never a list request,
which is the tell that it is intercepting rather than letting core decide.

**No conflict with this plugin.** Both refuse anonymous callers with a 401;
whichever runs first, the other preserves the result. Deploy here anyway: the
Wordfence option is a checkbox someone can turn off, and this plugin is not.

### Something unidentified, on `sites.uams.edu`

Not Wordfence — that site's namespaces are `sweep/v1`, `search-regex/v1`,
`seopress/v1`, `wp-mail-smtp/v1`, `genesis/v1`. Whatever it is registers no
REST routes of its own, which fits an mu-plugin or a snippet in a theme.

Its pre-deploy fingerprint, which is **not** Wordfence's:

- `rest_forbidden`, "Authentication required." — the wording from this spec,
  not from core
- case-insensitive: `/wp/v2/Users` and `/wp/v2/USERS` both refused
- `?author=1` → **301 to the home page**, where Wordfence 404s
- oEmbed author fields replaced with the site name and home URL
- **batch sub-request went straight through** to the handler

That last point is the important one. It only ever sees the outer HTTP request,
which is the signature of a hook like `rest_authentication_errors` rather than
`rest_pre_dispatch`. Everything else about it looks like a competent
implementation of FR1 through FR4 — so most likely someone has already applied
a version of this fix there, using the wrong hook for the batch vector.

**It cannot be identified from outside**, because this plugin now returns the
same message on that site. Resolving it takes one look at
`wp-content/mu-plugins/` over FTP, and at Plugins > Installed Plugins. Worth
doing: if it is a hand-rolled snippet it should come out now that this plugin
supersedes it, and if it is something else it should be understood before the
other 31 sites are assumed clean.

### The other 29

No blocking of any kind. `GET /wp/v2/users` returns 200 with real user data,
`/wp/v2/Users` likewise. `uams-pepper.bigscoots-wpo.com` did not respond at all
and is unassessed.

---

## Appendix: the 32 installs

From the directory names in `UAMS-Web/UAMSWP-Sentinel-configs`. Hostnames only —
those files hold per-site signing keys and webhook URLs and nothing was copied
out of them.

```
(32 hostnames redacted)
```

32, matching the spec's count. The development and staging installs among them are the natural first targets for step 2 of the deployment plan.
