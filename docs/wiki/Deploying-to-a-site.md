# Deploying to a site

Written against v1.2.0.

At the end of this you will have the plugin loaded on one site, the acceptance checks passing against it, and the block editor's Author dropdown confirmed working. No step needs a shell on the server: the file goes up by FTP, the checks run from a laptop, and anything read from the install is a wp-admin screen.

1. **Upload the one file.** Put `uamswp-restrict-user-enumeration.php` directly in `wp-content/mu-plugins/`, not in a folder under it. The [README](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration#install) says why a folder silently does nothing. There is no activation step: must-use plugins load on the next request.
2. **Run the acceptance checks against the canonical origin.**

   ```bash
   bin/acceptance.sh https://example.org
   bin/acceptance.sh -f sites.txt          # one origin per line, # for comments
   ```

   Every check should read `PASS`. The script exits non-zero if any check fails. [Acceptance checks](Acceptance-checks.md) says what each one tests and what a `WARN` means.
3. **Confirm the batch check passed.** It is the only check that tells this plugin apart from something else that already answers 401 on the plain users URLs. A `400 rest_missing_callback_param` there means the plugin is not loaded.
4. **Open a post in the block editor as an Editor** and confirm the Author dropdown still lists users. The automated tests cover this, but production configuration can still break it, because it depends on a valid REST nonce reaching the server.

**Note:** deploy to a site even when it already refuses these routes through another plugin's setting. That setting is a checkbox somebody can clear.

**Note:** the list of sites is kept out of this repository; a `sites.txt` passed to `-f` is gitignored.

## Going further

- [Rolling back](Rolling-back.md) if something looks wrong after the deploy.
- [Troubleshooting](Troubleshooting.md) for a check that fails.
