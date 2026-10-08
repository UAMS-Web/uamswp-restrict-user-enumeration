# Bug report: ACF discards the rest_pre_dispatch filter value

**Status: filed as [#1040](https://github.com/AdvancedCustomFields/acf/issues/1040),
then closed as a duplicate of [#1000](https://github.com/AdvancedCustomFields/acf/issues/1000).**
The live contribution is a comment on #1000. The report below is kept as our own
record of what was found and how.

The tracker was not searched before drafting, which it should have been. #1000
predates this by seven months (2026-02-27) and [#988](https://github.com/AdvancedCustomFields/acf/issues/988)
reports the same root cause from 2025-11-07. One API call would have found them.

Nothing here carries site names, hostnames or usernames: it is a public report
about ACF, and which of our sites were affected is not WP Engine's business.

---

## Closing note for #1040

Duplicate of #1000, which reports the same defect with the same suggested fix, and predates this by seven months. Also overlaps #988.

Closing in favor of #1000 and moving the corroborating detail there as a comment, rather than leaving a third open issue for maintainers to reconcile. Apologies for the noise: I should have searched the tracker before opening this.

---

## Comment for #1000

Corroborating from a third production estate, with a consequence class not yet represented in this thread and a workaround that does not depend on priority.

**What it cost us.** A must-use plugin refusing unauthenticated access to `/wp/v2/users`. It was installed, loaded, correctly registered and demonstrably running (its three non-REST hooks all worked) while the endpoint it exists to close kept answering `200` with the full staff list: logins, display names mapping people to departments, and paginated, so `?per_page=100&page=N` walks the whole directory. Unauthenticated PII exposure rather than a rate limiter going quiet, but the same silent-failure shape: nothing logged, nothing to notice, and every other part of the plugin visibly healthy.

That last detail is what made it hard. Because ACF touches only `rest_pre_dispatch`, the plugin's other three hooks (`template_redirect`, `oembed_response_data`, `wp_sitemaps_add_provider`) all behaved correctly. Three of four requirements passing is a very convincing picture of a working deployment.

**Extends the affected range downward.** This thread reports 6.7.0.2 and 6.8.5/6.8.9. We hit it on **ACF PRO 6.4.2**, and `initialize()` is byte-identical in **ACF Free 6.4.3**. Together with the comment above, that is at least 6.4 → 6.8.9, free and PRO, unchanged throughout.

**Diagnosis, in case it saves anyone else the time.** Elimination against installed plugins got nowhere; what settled it in one request was dumping the chain from a temporary mu-plugin that registered its responder from inside `rest_api_init`, late enough to survive:

```
10  <our callback>              <- returns the 401
10  ACF_Rest_Api::initialize    <- discards it
10  <three others>
11  <another plugin's gate>     <- unaffected, runs after ACF
```

The plugin at priority 11 on the same site worked correctly throughout, which is the tell: whether a gate works depends only on whether it registered before or after ACF.

**On the priority-99 workaround.** Agreed that it is fragile, and for a security control that fragility is the whole problem: it trades one ordering assumption for another. What we settled on is two gates, the second of which is not a filter chain and so has nothing to discard:

```php
// Gate 1: after anything registered normally.
add_filter( 'rest_pre_dispatch', 'my_block_callback', PHP_INT_MAX, 3 );

// Gate 2: the route's own permission callback. Not a chain, so no later
// callback can drop it. Covers /batch/v1 sub-requests and the sub-requests
// _embed makes, since both run respond_to_request().
add_filter( 'rest_endpoints', function ( $endpoints ) {
	foreach ( $endpoints as $route => $handlers ) {
		if ( ! is_array( $handlers ) || ! my_route_matches( $route ) ) {
			continue;
		}
		foreach ( $handlers as $i => $handler ) {
			// Skips the route-level siblings register_rest_route() leaves
			// alongside the method handlers: allow_batch, schema, namespace.
			if ( ! is_array( $handler ) || ! array_key_exists( 'permission_callback', $handler ) ) {
				continue;
			}
			$original = $handler['permission_callback'];
			$endpoints[ $route ][ $i ]['permission_callback'] = function ( $request ) use ( $original ) {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 401 ) );
				}
				return is_callable( $original ) ? call_user_func( $original, $request ) : true;
			};
		}
	}
	return $endpoints;
}, PHP_INT_MAX );
```

Either gate alone refuses the request, so it takes two unrelated plugins misbehaving to reopen the route. Offered as a pattern for anyone stuck here, not as an argument against fixing it: the two-line change in this issue is still the right answer, and a regression test that registers a short-circuiting `rest_pre_dispatch` callback at priority 10 alongside ACF would keep it fixed.

Happy to help with a PR if that is useful.

---

## The original report, as filed

Kept for the record. Its substance is in the #1000 comment above.

---

## Title

ACF_Rest_Api::initialize() does not return the rest_pre_dispatch filter value, silently discarding other plugins' short-circuits

## Body

**Describe the bug**

`ACF_Rest_Api::initialize()` is registered as a **filter** callback on `rest_pre_dispatch` (`includes/rest-api/class-acf-rest-api.php:22`) but never returns its first argument. Every code path returns `null`:

```php
// line 22
add_filter( 'rest_pre_dispatch', array( $this, 'initialize' ), 10, 3 );

// line 26
public function initialize( $response, $handler, $request ) {
    if ( ! acf_get_setting( 'rest_api_enabled' ) ) {
        return;                       // null
    }

    $this->request = new ACF_Rest_Request();
    $this->request->parse_request( $request );
    $this->register_field();

    if ( acf_get_setting( 'rest_api_embed_links' ) ) {
        $this->embed_links = new ACF_Rest_Embed_Links();
        $this->embed_links->initialize();
    }
}                                     // null
```

Both exits yield `null`: the early `return;` when the REST integration is off, and falling off the end when it is on. The setting does not change the outcome.

Because `apply_filters()` chains, this erases whatever an earlier callback placed in `$response`. `WP_REST_Server::dispatch()` then sees `empty( $result )` and routes the request normally, as though nothing had refused it:

```php
$result = apply_filters( 'rest_pre_dispatch', null, $this, $request );

if ( ! empty( $result ) ) {
    // ... serve $result instead of routing
}
```

**Effect:** with ACF active, every `rest_pre_dispatch` short-circuit registered at priority 10 or earlier is silently disabled. Callbacks at priority 11 or later are unaffected, which makes it hard to diagnose: on one site, one plugin's gate works and another's does not, with nothing in either plugin to explain the difference.

Security plugins are the common case, since refusing a route before dispatch is what this hook is for. We hit it with a plugin that blocks unauthenticated access to `/wp/v2/users`. It was installed, loaded, correctly registered and demonstrably running (its three non-REST hooks all worked), while the endpoint it exists to close kept answering `200` with the full user list. There is no notice, no error and no log entry.

Observed on a live site running WordPress 7.0.6 with ACF PRO 6.4.2, diagnosed by dumping the `rest_pre_dispatch` callback chain at dispatch time:

```
10  <our callback>                 <- returns the 401
10  ACF_Rest_Api::initialize       <- discards it
10  ...
11  <another plugin's gate>        <- unaffected, runs after ACF
```

The same code is present in ACF free 6.4.3, so this is not PRO-specific.

**To Reproduce**

1. Install and activate ACF (free or PRO), leaving `rest_api_enabled` at its default.
2. Add the mu-plugin under **Code** below.
3. Logged out, request the users endpoint:
   `curl -s -o /dev/null -w '%{http_code}' https://example.test/wp-json/wp/v2/users`
   Returns **200** with the user list.
4. Change the callback's priority from `10` to `11` and repeat. Returns **401**.
5. Restore priority `10`, deactivate ACF, repeat. Returns **401**.

Steps 4 and 5 isolate ACF as the cause.

**Expected behavior**

A filter callback returns the value it was given when it does not intend to change it. `initialize()` should return `$response` on both paths, so that a short-circuit set by an earlier `rest_pre_dispatch` callback survives:

```php
public function initialize( $response, $handler, $request ) {
    if ( ! acf_get_setting( 'rest_api_enabled' ) ) {
        return $response;
    }

    // ... unchanged ...

    return $response;
}
```

`initialize()` does not modify `$response` and is not meant to, so returning it unchanged preserves ACF's behavior exactly while restoring the filter contract. If the intent is a side effect rather than a filter, an action would be the more thorough fix, but returning `$response` resolves the reported problem completely.

**Screenshots or Video**

Not applicable; the reproduction is a status code.

**Code**

Not field-group related, so no JSON export applies. The minimal reproduction is this mu-plugin:

```php
<?php
/**
 * Plugin Name: rest_pre_dispatch short-circuit demo
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( ! is_user_logged_in() && '/wp/v2/users' === $request->get_route() ) {
        return new WP_Error( 'demo_blocked', 'Blocked.', array( 'status' => 401 ) );
    }
    return $result;
}, 10, 3 );
```

**Version Information:**
 - WordPress Version: observed on 7.0.6; the affected core code (`WP_REST_Server::dispatch()`) is unchanged across 6.4 through 7.1
 - PHP Version: 8.2.30
 - ACF Version: ACF PRO 6.4.2 (observed); identical code in ACF Free 6.4.3
 - Browser: not applicable, reproduced with curl

**Additional context**

Two suggestions, offered in case they are useful:

- It may be worth scanning for the same shape elsewhere in ACF, i.e. an `add_filter()` callback whose body never returns the filtered value.
- A regression test that registers a short-circuiting `rest_pre_dispatch` callback at priority 10 alongside ACF, and asserts the short-circuit survives, would catch this class of bug.
