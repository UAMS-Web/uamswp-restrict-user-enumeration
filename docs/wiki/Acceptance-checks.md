# Acceptance checks

Written against v1.2.0.

`bin/acceptance.sh` checks a live site as a stranger would: anonymous, with no cookies and no credentials. It runs from a laptop, not the server. Pass one origin, or `-f <file>` with one origin per line (`#` starts a comment).

```bash
bin/acceptance.sh https://example.org
bin/acceptance.sh -f sites.txt
```

## Results

Each check prints one line, `PASS`, `WARN` or `FAIL`, with a label and the detail it saw.

| Result | Meaning | Exit status |
|---|---|---|
| `PASS` | The route answered as the plugin intends. | unaffected |
| `FAIL` | The route leaked, or answered something unexpected. | 1 |
| `WARN` | Something real but outside this plugin's scope, or a check that could not be exercised on this site. | unaffected |

The script exits 0 when every check on every site passed and 1 otherwise, so it can go straight into a monitor.

## The checks

Sixteen per site, in this order, after a canonical-host check that prints only when the host redirects. Every check is a GET except the batch check.

| Check | Request | Passes when |
|---|---|---|
| canonical host | `GET /wp-json/` | no redirect. A redirect is a `WARN`: re-run against the origin it names, since every other check reads a status code |
| REST users routes (7) | `/wp-json/wp/v2/users`, `/Users`, `/USERS`, `/users/1`, `/users/`, `/?rest_route=/wp/v2/users`, `/?rest_route=/wp/v2/Users` | each answers 401, however the route is spelled |
| batch `/wp/v2/users` | `POST /wp-json/batch/v1` carrying a users sub-request | the sub-request is refused with 401 |
| `_embed=author` | `GET /wp-json/wp/v2/posts?per_page=1&_embed=author` | no author is embedded, or the embedded author is refused |
| users sitemap | `GET /wp-sitemap-users-1.xml` | 404 |
| sitemap index | `GET /wp-sitemap.xml`, following up to 3 redirects | no users or author sitemap is listed |
| `/author.xml` (SEOPress) | `GET /author.xml` | not 200. A 200 is a `WARN`: SEOPress has its own author sitemap enabled |
| `/?author=1` | `GET /?author=1` | 301 to somewhere other than `/author/<slug>/` |
| oEmbed author fields | `GET /wp-json/oembed/1.0/embed?url=<origin>/` | `author_url` is present and is not an author archive |
| no collateral (2) | `GET /wp-json/wp/v2/posts`, `GET /wp-json/` | each answers 200 |

## Why the batch check matters

It is the only check that says whether **this** plugin is loaded. A site may already have something that answers 401 on the plain users URLs, and every other check passes either way. Those blockers hook once per HTTP request, so they only see the outer `/batch/v1` route, and the users sub-request in the body goes through. The plugin's `rest_pre_dispatch` gate runs once per sub-request and refuses it.

| Batch result | What it means |
|---|---|
| `401 rest_forbidden` | The plugin is loaded and blocking at dispatch. |
| `400 rest_missing_callback_param` | The plugin is not loaded; something else answered the plain URLs. |
| `500` | The WordPress 7.0+ batch fatal; see [Deviations from the spec](Deviations-from-the-spec.md#1-it-returns-a-converted-response-not-a-raw-wp_error-fr1). |

The batch request creates nothing: the sub-request carries no body, it is refused before the handler runs, and core requires `create_users` regardless.

## When a check reads WARN

- **`_embed=author`: site has no posts; check not exercised.** There is nothing to embed, so the check proves nothing. Saying so is more accurate than counting it as a pass.
- **`/author.xml` (SEOPress): 200.** An SEO plugin's own author sitemap is out of this plugin's scope. Disable it in SEOPress.
- **sitemap index: no readable sitemap index.** Check `/wp-sitemap.xml` by hand.
- **canonical host: redirects.** Re-run against the origin in the message.

## Reference

- [Deploying to a site](Deploying-to-a-site.md) runs these checks as step 2.
- [Troubleshooting](Troubleshooting.md) covers the failures.
- The script: [`bin/acceptance.sh`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/blob/main/bin/acceptance.sh).
