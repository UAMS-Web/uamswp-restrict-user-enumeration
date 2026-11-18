# Rolling back

Written against v1.2.0.

The plugin has to come off a site, or be switched off while something else is ruled out.

## Delete the file

Remove `wp-content/mu-plugins/uamswp-restrict-user-enumeration.php`. On hosting that does not allow editing `wp-config.php`, this is the primary route, not the fallback.

## Switch it off with the kill switch

The constant `UAMSWP_RUE_DISABLE` turns the plugin off without removing it. It is worth preferring over deleting the file when the code should stay put and auditable: during a soak, say, or while proving that something else is the cause of a problem.

1. **Where `wp-config.php` can be edited,** add `define( 'UAMSWP_RUE_DISABLE', true );` there as normal.
2. **Where it cannot,** put the constant in an mu-plugin that loads first. `wp_get_mu_plugins()` sorts the directory, so a leading digit guarantees the order. Upload `wp-content/mu-plugins/0-uamswp-rue-disable.php`:

   ```php
   <?php
   // Loads before uamswp-restrict-user-enumeration.php because mu-plugins are
   // loaded in sorted order, so the constant is set before the plugin reads it.
   define( 'UAMSWP_RUE_DISABLE', true );
   ```

3. **Confirm it is off:** run the [acceptance checks](Acceptance-checks.md). With the constant set, the users routes answer as core does again.
4. **To switch the plugin back on,** delete `0-uamswp-rue-disable.php` (or remove the `define()`).

It is all or nothing. With the constant set, the plugin registers no hooks at all, and the kill-switch test suite asserts that all four vectors come back.

**Warning:** do not put the `define()` in another plugin's generated configuration file, even one that loads early enough. If that plugin's generator refuses to overwrite its file, borrowing the file ties two unrelated rollbacks together.

## Reference

- The constant is read once, when the plugin file is first included, which is why its test suite runs in a process of its own: [`tests/Integration/killswitch/`](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration/tree/main/tests/Integration/killswitch).
