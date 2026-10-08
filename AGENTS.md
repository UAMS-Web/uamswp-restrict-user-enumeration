# Agent instructions for uamswp-restrict-user-enumeration

This file is the single source of agent instructions for this repository. Claude Code, Cursor and Codex all read it directly; there is no `CLAUDE.md`, because Claude Code reads `AGENTS.md` only when `CLAUDE.md` is absent. Put instructions here, never in a `CLAUDE.md`.

Everything above the generated section below belongs to this repository and is edited here. The generated section is rewritten by the sync from `UAMS-Web/uams-claude-skills`; edit the shared skills and rules there.

## About this repository

A single-file WordPress must-use plugin that refuses every known unauthenticated route to a username or an author slug. Built to [`docs/SPEC.md`](docs/SPEC.md); the pre-deploy audit that spec asked for is [`docs/AUDIT.md`](docs/AUDIT.md), and it holds the live baselines, the estate inventory, and what else on the estate already blocks these routes.

### One file ships

`uamswp-restrict-user-enumeration.php` is the only file that belongs on a server, and it goes in the root of `wp-content/mu-plugins/`. `wp_get_mu_plugins()` does a flat `readdir` and keeps only `*.php`, so a copy one directory down is never loaded: the site looks normal, nothing errors, and every route the plugin exists to close stays open. Everything else here is development and deployment tooling, and `docs/AUDIT.md` in particular must not reach a server, because `wp-content/` is inside the web root and that file lists what is currently exploitable.

### The target estate has no shell

The BigScoots-hosted installs give no CLI, so nothing here may assume one. Deployment is FTP into `mu-plugins/`. Anything that has to be read back from an install is either an HTTP request or a named wp-admin screen; `docs/AUDIT.md` section 6 lists which. `wp-config.php` is not editable either, so the `UAMSWP_RUE_DISABLE` kill switch ships as an mu-plugin whose name sorts before the plugin's (`wp_get_mu_plugins()` sorts), not as a `wp-config.php` edit.

### Running the checks

```bash
composer install
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
composer test   # both suites
composer lint   # PHPCS, WordPress ruleset
```

Two PHPUnit suites, not one. The kill switch is decided when the plugin file is first included and a PHP constant cannot be undefined afterward, so that suite needs its own process and carries its own config at `tests/killswitch/phpunit.xml.dist`. `composer test` runs both.

The suite installs the WordPress test library under the system temp directory, which macOS clears periodically. When the suite suddenly cannot run, re-run `bin/install-wp-tests.sh` before looking for anything subtler. The MySQL databases survive; only the core downloads go.

Set `WP_TESTS_DIR` to run against another WordPress version. The estate runs more than one, so changes to the plugin are verified against each (see `docs/AUDIT.md` section 3 for which).

### Running the checks locally (local CI)

Remote CI is off for this repository, so the shared runner is the whole gate:

```bash
node .claude/skills/local-ci/local-ci.mjs            # jobs the diff selects
node .claude/skills/local-ci/local-ci.mjs --dry-run  # the plan, and why
node .claude/skills/local-ci/local-ci.mjs --all      # everything
```

`local-ci.json` lists the jobs and mirrors `composer.json`'s scripts. Read the exit status without a pipe in between: a pipe throws it away, and the summary is a cross-check rather than the contract.

Each clone needs the commit guard wired once, because git does not run hooks from inside the repository until told to:

```bash
git config core.hooksPath .githooks
```

That hook reads the same `guard.deny` list the runner checks, so the list lives in one place. `uams-sentinel-config.php` is on it deliberately: the estate's copies carry per-site baseline signing keys and a webhook URL, and the file is a plain list of `define()` calls that reads like ordinary source.

### Verifying a deploy

`bin/acceptance.sh <origin>` checks a live site as an anonymous visitor, and takes `-f <file>` for a list. Every check is a GET except `check_batch`, which is the only one that distinguishes this plugin from something else that happens to answer 401 on the plain users URLs. Run it against the canonical origin; it warns when the host redirects. Site lists are deliberately untracked, see `.gitignore`.

### Conventions specific to this plugin

- **The declared `Requires at least` and `Requires PHP` are deliberately below what the estate runs.** They state what the code actually needs, so a version assumption cannot stop a security control from loading. Keep them that way, and keep new code inside them.
- **FR1 is enforced at two independent gates**, `rest_pre_dispatch` at `PHP_INT_MAX` and a `rest_endpoints` permission-callback wrapper. This is not belt and braces: a third-party plugin was found discarding the first gate's return value in production, which is written up in [`docs/ACF-BUG-REPORT.md`](docs/ACF-BUG-REPORT.md) and regression-tested in `tests/test-hostile-chain.php`. Either gate alone refuses the request. Do not reduce it to one.
- **Every vector has a control test** that removes the plugin's hook and asserts the leak is real. A test that only proves the closed state cannot tell a working guard from an absent vector.

<!-- uams-claude-skills:begin -->
## Shared skills and rules

The files listed here are synced from [UAMS-Web/uams-claude-skills](https://github.com/UAMS-Web/uams-claude-skills) (`shared/`) and are edited there, never here; a sync pull request brings changes into this repository. This section is generated; everything outside it belongs to this repository.

Each rule below is a standing instruction for every task in this repository. Its full text is delivered three times: `.claude/rules/<name>.md`, which Claude Code loads in every session; `.cursor/rules/<name>.mdc`, which Cursor always applies; and the skill `.agents/skills/rule-<name>/`, which Codex selects by its description. Before work a rule governs, read its full text. Skills are in `.claude/skills/` and, as the same files, in `.agents/skills/`, and load when their description matches the task.

Profiles for this repository: core, php, wordpress-plugin, tests, quality, local-ci.

### Rules

- `adversarial-review`: Adversarially verify before it reaches anyone else. Full text: [`.claude/rules/adversarial-review.md`](.claude/rules/adversarial-review.md).
- `american-english-prose`: American English for prose, and a dictionary entry that overrides it needs a stated reason. Full text: [`.claude/rules/american-english-prose.md`](.claude/rules/american-english-prose.md).
- `an-empty-result-is-not-evidence`: A result is not evidence until the instrument has been shown to tell the two answers apart. Full text: [`.claude/rules/an-empty-result-is-not-evidence.md`](.claude/rules/an-empty-result-is-not-evidence.md).
- `closing-a-ticket`: Finish a ticket where it is visible, not just where it builds. Full text: [`.claude/rules/closing-a-ticket.md`](.claude/rules/closing-a-ticket.md).
- `coordination-plumbing-stays-out-of-artifacts`: Keep session-coordination plumbing out of GitHub artifacts and documentation. Full text: [`.claude/rules/coordination-plumbing-stays-out-of-artifacts.md`](.claude/rules/coordination-plumbing-stays-out-of-artifacts.md).
- `cutting-releases`: Cut a release each business day at 10:00 Central when there is unreleased work. Full text: [`.claude/rules/cutting-releases.md`](.claude/rules/cutting-releases.md).
- `design-decision-forks`: Presenting design-decision forks (lead with a compare/contrast). Full text: [`.claude/rules/design-decision-forks.md`](.claude/rules/design-decision-forks.md).
- `filing-defects-across-repos`: File a defect in the repository that owns it, and claim it first when a race is likely. Full text: [`.claude/rules/filing-defects-across-repos.md`](.claude/rules/filing-defects-across-repos.md).
- `github-api-budget`: Spend the REST quota to preserve the GraphQL one. Full text: [`.claude/rules/github-api-budget.md`](.claude/rules/github-api-budget.md).
- `impersonal-voice-in-github-artifacts`: Write GitHub artifacts in an impersonal voice. Full text: [`.claude/rules/impersonal-voice-in-github-artifacts.md`](.claude/rules/impersonal-voice-in-github-artifacts.md).
- `long-running-commands`: Bound every long-running command, and resolve what you kill. Full text: [`.claude/rules/long-running-commands.md`](.claude/rules/long-running-commands.md).
- `measurement-parity`: Mirror the *job*, not the command, before comparing to a baseline. Full text: [`.claude/rules/measurement-parity.md`](.claude/rules/measurement-parity.md).
- `no-em-dashes`: No em dashes in prose, docs, comments, or strings. Full text: [`.claude/rules/no-em-dashes.md`](.claude/rules/no-em-dashes.md).
- `no-emoji-in-durable-records`: No emoji in durable records. Full text: [`.claude/rules/no-emoji-in-durable-records.md`](.claude/rules/no-emoji-in-durable-records.md).
- `pre-merge-check`: Re-verify at the merge, not only at the build. Full text: [`.claude/rules/pre-merge-check.md`](.claude/rules/pre-merge-check.md).
- `reading-exit-status`: Read the result, not the exit code. Full text: [`.claude/rules/reading-exit-status.md`](.claude/rules/reading-exit-status.md).
- `supported-platforms`: MacOS and Windows are both supported; name the platform, write paths repo-relative. Full text: [`.claude/rules/supported-platforms.md`](.claude/rules/supported-platforms.md).
- `sync-pr-branch`: Sync every PR branch from `origin/main` before the gating run. Full text: [`.claude/rules/sync-pr-branch.md`](.claude/rules/sync-pr-branch.md).
- `worktrees`: Work from stable, pre-bootstrapped worktree slots (branch work never happens in the primary checkout). Full text: [`.claude/rules/worktrees.md`](.claude/rules/worktrees.md).

Skills: [`code-quality`](.claude/skills/code-quality/SKILL.md), [`handed-back-label`](.claude/skills/handed-back-label/SKILL.md), [`local-ci`](.claude/skills/local-ci/SKILL.md), [`php-coding-standards`](.claude/skills/php-coding-standards/SKILL.md), [`php-documentation`](.claude/skills/php-documentation/SKILL.md), [`security-audit`](.claude/skills/security-audit/SKILL.md), [`synced-files`](.claude/skills/synced-files/SKILL.md), [`tests`](.claude/skills/tests/SKILL.md), [`update-dependencies-ticket`](.claude/skills/update-dependencies-ticket/SKILL.md), [`upstream-patch-lifecycle`](.claude/skills/upstream-patch-lifecycle/SKILL.md), [`writing-comments`](.claude/skills/writing-comments/SKILL.md), [`writing-commits`](.claude/skills/writing-commits/SKILL.md), [`writing-issues`](.claude/skills/writing-issues/SKILL.md), [`writing-pull-requests`](.claude/skills/writing-pull-requests/SKILL.md), [`writing-release-notes`](.claude/skills/writing-release-notes/SKILL.md).
<!-- uams-claude-skills:end -->
