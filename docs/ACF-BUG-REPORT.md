# Bug report: ACF discards the rest_pre_dispatch filter value

Shaped to ACF's required issue template (`blank_issues_enabled: false`).
File at https://github.com/AdvancedCustomFields/acf/issues/new?template=acf-bug-report.md

Contains no site names, hostnames or usernames: it is a public report about
ACF, and which of our sites were affected is not WP Engine's business.

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

`initialize()` does not modify `$response` and is not meant to, so returning it unchanged preserves ACF's behaviour exactly while restoring the filter contract. If the intent is a side effect rather than a filter, an action would be the more thorough fix, but returning `$response` resolves the reported problem completely.

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
