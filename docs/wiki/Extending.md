# Extending

Written against v1.2.0.

The plugin has no settings. It exposes one filter, to let a known anonymous consumer through the REST block, and one action, which fires on every refusal. There is no logging by default; the action is the hook for it.

## `uamswp_rue_block_rest_request` (filter)

Decides whether an anonymous request to a users route is refused.

| Parameter | Type | Meaning |
|---|---|---|
| `$block` | `bool` | `true` (the default) refuses the request; return `false` to let it through. |
| `$request` | `WP_REST_Request` | The request being dispatched. |

```php
// Let one known anonymous consumer through. Default is true, meaning block.
add_filter( 'uamswp_rue_block_rest_request', function ( $block, $request ) {
	return '/wp/v2/users' === $request->get_route() && some_check( $request )
		? false
		: $block;
}, 10, 2 );
```

`some_check()` stands for whatever identifies the consumer. A request a logged-in user makes is never refused, so the filter matters only for anonymous callers.

## `uamswp_rue_blocked` (action)

Fires each time the plugin refuses something.

| Parameter | Type | Meaning |
|---|---|---|
| `$vector` | `string` | `'rest'` for a users-route request, `'author_query'` for a `?author=` query. |
| `$request` | `WP_REST_Request\|null` | The REST request for `'rest'`; `null` for `'author_query'`. |

```php
// Fires on every refusal. $vector is 'rest' or 'author_query'.
add_action( 'uamswp_rue_blocked', function ( $vector, $request ) {
	// No logging by default; this is the hook for it.
}, 10, 2 );
```

## Reference

- Where each hook is called: [`uamswp-restrict-user-enumeration.php`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/blob/main/uamswp-restrict-user-enumeration.php).
