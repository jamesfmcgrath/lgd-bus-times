---
description: Run PHPCS, PHPStan and PHPUnit in DDEV, then review the changes with drupal-reviewer
argument-hint: [optional scope, e.g. a path, "staged", or "vs main"]
allowed-tools: Bash(make:*), Bash(ddev:*), Bash(git:*), Read, Grep, Glob, Task
---

Verify the current work on `localgov_bus_data` before it is committed.

Scope for this run (empty means the full uncommitted working tree): $ARGUMENTS

## Ground rules

- Run every command from the repo root, `lgd-bus-data-dev`.
- Never run `php`, `phpcs`, `phpcbf`, `phpstan`, or `phpunit` directly on the
  host. They only work inside DDEV, through the Makefile targets below.
- If DDEV is not running, run `make start` first and say so.
- No em dashes in anything you write, including commit messages and summaries.

## 1. Quality gates

Run these three separately, in this order, and do not stop at the first
failure. `make check` runs all three in one go, but a single target failing
would hide the results of the others.

1. `make lint` (PHPCS: Drupal and DrupalPractice)
2. `make stan` (PHPStan)
3. `make test` (PHPUnit, `custom` testsuite)

Read the output text rather than trusting the exit status: `ddev exec` does not
always propagate a non-zero exit code from `make stan` in this repo. Treat any
reported error or violation as a failure regardless of what the shell returns.

For each gate report: pass or fail, and for failures the file, line, and rule
or assertion. Fix anything that is clearly a defect in the new work, then
re-run that gate. Do not "fix" a failure by loosening a rule, adding a PHPStan
baseline entry, or deleting an assertion. If a failure is pre-existing and
unrelated to the current change, say so and leave it alone.

`make lint-fix` (PHPCBF) is available for mechanical whitespace and formatting
violations. Review its diff before accepting it.

## 2. Config sanity

If the change touches `config/install/*.yml` or `config/schema/*.yml`:

- Confirm every file still parses.
- Confirm no `{{UPPER_SNAKE}}` placeholder or stray merge marker survived.
- Confirm shipped config still matches any code-level defaults that mirror it,
  for example `Drupal\localgov_bus_data\Utility\BusMessages::DEFAULTS`.

If the change adds or edits a `hook_update_N`, state plainly whether existing
sites need `drush updatedb`, and whether a site that deploys config from a
repository will have the update reverted by the next `drush config:import`.

## 3. Review

Launch the `drupal-reviewer` subagent with the Task tool. Give it:

- the diff for the scope above (`git diff`, `git diff --staged`, or
  `git diff main...HEAD`, whichever matches the requested scope), taken in
  `web/modules/custom/localgov_bus_data` since the module is its own repo;
- the full output of any gate that failed;
- a one-line statement of what the change is meant to do.

Ask it for correctness, security, and Drupal API misuse findings, ranked most
severe first, each with file, line, and a concrete failure scenario.

## 4. Report

Finish with a short summary: each gate as pass or fail, what you fixed, what
the reviewer found that you agree with, and anything you deliberately left.
State clearly whatever could not be verified in this run, for example anything
needing a live site, a browser, or a real GTFS import.
