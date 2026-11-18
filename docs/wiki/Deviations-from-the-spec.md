# Deviations from the spec

Written against v1.2.0.

The plugin was built to [`docs/SPEC.md`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/blob/main/docs/SPEC.md). It differs from it in four places, all deliberate, and each is commented at the code that does it. The measurements behind them are in [`docs/AUDIT.md`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/blob/main/docs/AUDIT.md).

## 1. It returns a converted response, not a raw `WP_Error` (FR1)

FR1 asks for `WP_Error( 'rest_forbidden', ..., [ 'status' => 401 ] )`. The plugin builds exactly that, then returns `rest_convert_error_to_response()` of it.

On WordPress 7.0 and later, a raw `WP_Error` returned from `rest_pre_dispatch` is a **fatal** inside a `/batch/v1` request. `dispatch()` converts the error, `serve_batch_request_v1()` does not, and core's own `_wp_connectors_rest_settings_dispatch()` on `rest_post_dispatch` type-hints `WP_REST_Response`. That turns the intended 401 into a 500, on a route an anonymous caller chooses. It was reproduced against WordPress 7.1.2 before the fix. The converted response is the same 401, with the same body, everywhere else.

Most of the production installs were on WordPress 7.0 or later when this was found, so following FR1 to the letter would have answered 500 instead of 401 on most sites from the first deploy.

## 2. The declared floors are the organization's standard

The spec states minimum versions and asks for them to be confirmed against the oldest install. The newest WordPress API the plugin uses is `wp_sitemaps_add_provider` (5.5), and `rest_convert_error_to_response` (5.7) is called behind `function_exists`, so the code itself would run on 5.5 and needs nothing newer than PHP 7.1 syntax. The header declares the organization's floors instead: WordPress 6.8 and PHP 8.2, each met by the oldest production install. A floor lint on PHP 8.2 gates the code.

Must-use plugins ignore `Requires at least` and `Requires PHP` and always load, so the header is documentation and cannot stop the plugin from loading.

## 3. FR1 is enforced twice, not once

The spec says to implement FR1 on `rest_pre_dispatch`. That alone was found insufficient in production. `rest_pre_dispatch` is a filter chain, and **Advanced Custom Fields 6.4.2** hooks `ACF_Rest_Api::initialize()` to it at priority 10 without ever returning its input:

```php
public function initialize( $response, $handler, $request ) {
	if ( ! acf_get_setting( 'rest_api_enabled' ) ) {
		return;          // null
	}
	// ... no return ...
}                        // null
```

Any 401 an earlier callback put in `$response` is discarded, and `dispatch()` carries on as though nothing refused the request. On one production site, `/wp/v2/users` answered 200 with the full staff list while the plugin was installed and loaded, with FR2, FR3 and FR4 all working, because ACF touches only this one hook.

So FR1 has two gates. `rest_pre_dispatch` runs at `PHP_INT_MAX`, after anything registered normally, and `rest_endpoints` wraps the users routes' `permission_callback`, which is not a chain and cannot be discarded. Either one alone refuses the request. A test reproduces ACF's shape and asserts both; the upstream report is [`docs/ACF-BUG-REPORT.md`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/blob/main/docs/ACF-BUG-REPORT.md).

## 4. T7 tests a POST, not a GET

T7 asks for "anonymous `POST /batch/v1` containing a users GET". Batch v1 cannot carry a GET: the `method` parameter on `/batch/v1` is an enum of `POST, PUT, PATCH, DELETE`, so core rejects a GET with a 400 during parameter validation, before any filter runs. The suite asserts that constraint explicitly in `test_batch_v1_does_not_accept_get()`, and tests the batch vector with a `POST /wp/v2/users` sub-request instead. That still proves what T7 is for: `rest_pre_dispatch` fires for batch sub-requests, so one route check covers the batch path.

This also means spec vector **V3 was never a read-enumeration route**. It is still worth covering, since a batch write to `/wp/v2/users` is a write to the user table.

V3 is also newer than the spec assumes: `WP_REST_Users_Controller::$allow_batch` does not exist in WordPress 6.4.12 and does in 6.8.10, so on 6.4 the users routes cannot appear in a batch at all and core answers `rest_batch_not_allowed`. The suite detects this with `get_route_options()` rather than assuming it.
