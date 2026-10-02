# Constraint resolution — the Phase 1.5 detail

Read this when you enter Phase 1.5, and when composer reports a conflict you cannot place.

## Patch-locked and exact constraints

A Spryker project pins hundreds of individual modules next to the `spryker-feature/*` meta-packages.
Any module pinned with `~x.y.z` (patch-only) or an exact version produces "conflicts with your root
composer.json require" instead of upgrading, so bumping the feature packages alone cannot resolve.
The same applies to `^0.x`: caret on a `0.x` package is major-locked.

```bash
php $UP/check-constraint-style.php            # report patch-locked constraints
php $UP/check-constraint-style.php --relax    # rewrite ~/exact -> ^ (review the diff)
```

Exactly-pinned third-party packages are reported separately and never auto-relaxed: third-party
majors carry their own breaking changes, so each is a manual decision (matrix #32). Constraints merged
in by composer-merge-plugin from another repository cannot be fixed here at all — fix them upstream,
and install the fixed providing package alone, first (matrix #39/#40).

## Iterative resolution

Set every `spryker-feature/*` constraint in `composer.json` to the target release, then let the
resolver iterate — conflicts arrive in waves, each bump revealing the next transitive layer:

```bash
php $UP/resolve-constraints.php --max-rounds=8
```

It bumps root constraints to what the tree demands, round by round, logs every change to
`.spryker-upgrade/state/constraint-resolution-log.json`, and flags each bump that crosses a major
boundary — that flagged list is the Lane 0 migration-guide worklist. Review `git diff composer.json`.

The resolver runs `composer update … --ignore-platform-reqs --no-scripts` on the host. That run only
discovers the constraints the tree needs; it is not a resolution to keep. When the resolver is done,
keep its `composer.json` edits and discard the rest: restore `composer.lock` with `git checkout -- composer.lock`, then
`vendor/` with `script -q /dev/null docker/sdk cli composer install`. Phase 2's full update in the
container is the authoritative resolution, with the platform pin and without
`--ignore-platform-reqs`.

## Cohorts: OSCILLATION / "would LOWER"

A cohort must move together — two halves of a plugin family demanding different majors of a shared
module. Per-package bumping cannot break that tie; hand it to the un-pinner so the feature
meta-packages decide, then resolve again:

```bash
php $UP/unpin-feature-driven-modules.php --match=<substr,substr> --dry-run
php $UP/unpin-feature-driven-modules.php --match=<substr,substr>
php $UP/resolve-constraints.php --max-rounds=8
```

Modules not governed by any feature package are never unpinned, so bump those explicitly to a version
whose own requirements match the cohort (find it from packagist metadata).

## UNRESOLVED — a human decision, never a workaround

- **A third-party/eco package pinning an old core major.** Before offering options, check what it
  actually uses from the blocking module: if it references a class the new major removed, no
  constraint change can help and it needs upstream code work (Lane 5).
- **A transitive dependency blocked by a security advisory.** Read the advisory's affected range
  first: usually a safe newer version exists and the real blocker is an exact root pin. Never set
  `policy.advisories.block: false` or add ignore-ids to get past this — it is a security decision for
  the developer, and disabling it hides real vulnerabilities.
- **A lock so stale that composer reports root constraints for packages no longer in
  `composer.json`.** Back up the lock to `.spryker-upgrade/state/composer.lock.before` (Phase 0 did),
  delete it, and resolve fresh from `composer.json` (matrix #38).
