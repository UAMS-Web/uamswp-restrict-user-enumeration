# Bug report: ACF_Rest_Api::initialize() discards the rest_pre_dispatch filter value

Draft for WP Engine / the ACF issue tracker. Found while deploying
`uamswp-restrict-user-enumeration`; see [AUDIT.md](AUDIT.md) and the README
section "Deviations from the spec".

Deliberately contains no site names, hostnames or usernames. It is a public
report about ACF, and the specifics of which of our sites were affected are not
WP Engine's business and not something to put in a public tracker.

---

## Summary

`ACF_Rest_Api::initialize()` is registered as a **filter** callback on
`rest_pre_dispatch` but never returns its first argument. Every code path
returns `null`. Any value an earlier callback placed in `$response` is silently
discarded, so a plugin that short-circuits `rest_pre_dispatch` at priority 10 or
earlier has no effect whenever ACF is active.

## Affected versions

Confirmed identical in:

- Advanced Custom Fields **6.4.3** (free)
- Advanced Custom Fields Pro **6.4.2**

File: `includes/rest-api/class-acf-rest-api.php`

## The code

```php
// line 22
add_filter( 'rest_pre_dispatch', array( $this, 'initialize' ), 10, 3 );

// line 26
public function initialize( $response, $handler, $request ) {
	if ( ! acf_get_setting( 'rest_api_enabled' ) ) {
		return;                       // null
	}

	// Parse request and set the object for local access.
	$this->request = new ACF_Rest_Request();
	$this->request->parse_request( $request );

	// Register the 'acf' REST property.
	$this->register_field();

	// If embed links are enabled in ACF's global settings, init the handler and set for local access.
	if ( acf_get_setting( 'rest_api_embed_links' ) ) {
		$this->embed_links = new ACF_Rest_Embed_Links();
		$this->embed_links->initialize();
	}
}                                     // null
```

Both exits yield `null`: the early `return;` when the REST integration is
switched off, and falling off the end when it is switched on. The setting does
not change the outcome.

## Why this matters

`rest_pre_dispatch` is the documented way to short-circuit a REST request.
`WP_REST_Server::dispatch()` treats a non-empty return as the response:

```php
$result = apply_filters( 'rest_pre_dispatch', null, $this, $request );

if ( ! empty( $result ) ) {
	// ... serve $result instead of routing the request
}
```

Because `apply_filters()` chains, a callback that does not return its input
erases whatever earlier callbacks decided. With ACF active, `$result` is `null`
by the time `dispatch()` reads it, and the request is routed normally — as
though nothing had refused it.

This is not specific to any one plugin. It silently disables **every**
`rest_pre_dispatch` short-circuit registered at priority ≤ 10. Callbacks at
priority 11 or later are unaffected, which is what makes it confusing to
diagnose: on the same site, one plugin's gate works and another's does not,
with nothing in either plugin to explain the difference.

Security plugins are the common case, because refusing a route before dispatch
is exactly what this hook is for. We found it because a plugin that blocks
unauthenticated access to `/wp/v2/users` was installed, loaded, correctly
registered and demonstrably running — its three other hooks all worked — while
the REST endpoint it exists to close kept answering `200` with the full user
list. The behaviour is silent: no notice, no error, no log entry.

## Reproduction

WordPress 6.8 or 7.x, ACF active, `rest_api_enabled` at its default.

1. Add this as an mu-plugin:

```php
<?php
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( ! is_user_logged_in() && '/wp/v2/users' === $request->get_route() ) {
		return new WP_Error( 'demo_blocked', 'Blocked.', array( 'status' => 401 ) );
	}
	return $result;
}, 10, 3 );
```

2. Request the route logged out:

```bash
curl -s -o /dev/null -w '%{http_code}\n' "https://example.test/wp-json/wp/v2/users"
```

**Expected:** `401`
**Actual:** `200`, with the user list

3. Change the priority from `10` to `11` and repeat. It now returns `401`,
   because the callback runs after ACF rather than before it.

4. Deactivate ACF and repeat at priority `10`. It returns `401`.

## Suggested fix

Return the filtered value on both paths:

```php
public function initialize( $response, $handler, $request ) {
	if ( ! acf_get_setting( 'rest_api_enabled' ) ) {
		return $response;
	}

	// ... unchanged ...

	return $response;
}
```

`initialize()` does not modify `$response` and is not meant to, so returning it
unchanged preserves ACF's behaviour exactly while restoring the filter
contract.

A broader option, if the intent is a side effect rather than a filter, is to
move the setup to an action — but returning `$response` is the smaller change
and fixes the reported problem completely.

## Notes

- Worth a scan for the same shape elsewhere in ACF: a `add_filter()` callback
  whose function body has no `return` of the filtered value.
- A regression test that registers a short-circuiting `rest_pre_dispatch`
  callback at priority 10 alongside ACF and asserts the short-circuit survives
  would catch this class of bug.
