# Troubleshooting

Written against v1.2.0.

- [The users routes still answer 200 after the upload](#the-users-routes-still-answer-200-after-the-upload)
- [`FAIL batch /wp/v2/users: sub-request reached the handler`](#fail-batch-wpv2users-sub-request-reached-the-handler-this-plugin-is-not-loaded)
- [`/wp/v2/users` answers 200 with the plugin loaded](#wpv2users-answers-200-with-the-plugin-loaded)
- [`FAIL batch /wp/v2/users: HTTP 500`](#fail-batch-wpv2users-http-500-fatal-in-the-batch-handler-not-a-401)
- [Every check reports a 301](#every-check-reports-a-301)
- [`WARN _embed=author: site has no posts`](#warn-_embedauthor-site-has-no-posts-check-not-exercised)
- [The block editor's Author dropdown is empty](#the-block-editors-author-dropdown-is-empty)

## The users routes still answer 200 after the upload

**Cause:** the file is in a folder under `wp-content/mu-plugins/`, not in that directory itself. `wp_get_mu_plugins()` reads `mu-plugins/` without descending into folders and keeps only entries ending in `.php`, so a copy one directory down is never loaded. Nothing errors and the site looks normal.

**Fix:** move `uamswp-restrict-user-enumeration.php` to `wp-content/mu-plugins/uamswp-restrict-user-enumeration.php`, then re-run the [acceptance checks](Acceptance-checks.md).

## `FAIL batch /wp/v2/users: sub-request reached the handler: this plugin is not loaded`

The batch sub-request answered `400 rest_missing_callback_param`, while the plain users URLs may still answer 401.

**Cause:** the plugin is not loaded, and something else on the site (another plugin's username-discovery option, say) is answering the plain URLs. Those blockers hook once per HTTP request and never see the users sub-request inside a batch.

**Fix:** check the file's location as above, and that no `UAMSWP_RUE_DISABLE` constant is set (see [Rolling back](Rolling-back.md)).

## `/wp/v2/users` answers 200 with the plugin loaded

The other vectors are closed, but the plain users route returns the user list.

**Cause:** a plugin on the `rest_pre_dispatch` filter is discarding the value it was given. Advanced Custom Fields 6.4.2 does this: `ACF_Rest_Api::initialize()` is hooked at priority 10 and returns nothing, so any refusal an earlier callback set is lost. It silently disables any plugin that short-circuits `rest_pre_dispatch` at priority 10 or earlier.

**Fix:** none is needed from v1.1.0, which refuses the users routes at a second gate (a wrapped `permission_callback`) that a filter chain cannot discard. If this appears, the site is running an older copy: replace it. The full write-up is [`docs/ACF-BUG-REPORT.md`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/blob/main/docs/ACF-BUG-REPORT.md).

## `FAIL batch /wp/v2/users: HTTP 500: fatal in the batch handler, not a 401`

**Cause:** on WordPress 7.0 and later, a raw `WP_Error` returned from `rest_pre_dispatch` is a fatal inside a `/batch/v1` request. The plugin returns a converted response to avoid it; a copy that returns the raw error hits it.

**Fix:** replace the file with the current release. [Deviations from the spec](Deviations-from-the-spec.md#1-it-returns-a-converted-response-not-a-raw-wp_error-fr1) has the detail.

## Every check reports a 301

**Cause:** the origin redirects (`www` to the apex, or `http` to `https`), so every check reads the redirect instead of the route. The first line reads `WARN canonical host: redirects to <origin>`.

**Fix:** re-run against the origin the warning names.

## `WARN _embed=author: site has no posts; check not exercised`

**Cause:** there is no post whose author could be embedded, so the check cannot show anything either way.

**Fix:** none needed. It does not affect the exit status. Re-run once the site has a published post if the check matters there.

## The block editor's Author dropdown is empty

**Cause:** the editor reads users over the REST API with the logged-in user's nonce. If the nonce does not reach the server (a caching or proxy layer stripping it, for instance), the request is treated as anonymous and refused.

**Fix:** confirm the `X-WP-Nonce` header reaches WordPress on the editor's REST requests, and that the page carrying the nonce is not served from a cache. The plugin itself never refuses a logged-in request.
