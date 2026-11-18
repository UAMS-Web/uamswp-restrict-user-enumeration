# UAMSWP Restrict User Enumeration

This wiki covers deploying, checking, rolling back and extending the plugin, and why it departs from its spec in four places. The [README](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration#readme) keeps the overview: what the plugin blocks, how to install the one file, the test suites and the repository layout.

**Getting started**

- [Installation (README)](https://github.com/UAMS-Web/uamswp-restrict-user-enumeration#install): the one file and where it goes.
- [Deploying to a site](Deploying-to-a-site.md): upload the file, run the acceptance checks, confirm the block editor still works.

**Guides**

- [Rolling back](Rolling-back.md): delete the file, or switch the plugin off with the kill switch where `wp-config.php` cannot be edited.

**Reference**

- [Acceptance checks](Acceptance-checks.md): what `bin/acceptance.sh` checks and what each result means.
- [Extending](Extending.md): the filter that lets a known consumer through, and the action that fires on every refusal.

**Troubleshooting**

- [Troubleshooting](Troubleshooting.md): symptoms seen on a deploy, their causes and fixes.

**Project**

- [Deviations from the spec](Deviations-from-the-spec.md): the four places the plugin differs from its spec, and why.
