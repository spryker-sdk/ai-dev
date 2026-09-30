# Tooling, Docker SDK and deploy files — the Phase 1.2 procedure

Read this when you enter Phase 1.2, and again if the tooling step has to be deferred until after
Phase 2.

The target release is more than a set of Spryker module versions. It is also the Docker SDK and the
image the release runs on, and the dev tooling its code is checked with. A composer update of
`spryker*/*` touches none of those, so they are aligned here, as their own step and commit, against the
reference demo shop of the target release.

## 1. Fetch the reference demo shop at the target release

Use the demo shop the project was created from (B2B, B2C, marketplace B2B, marketplace B2C — read it
from the project's README, its `composer.json` name, or ask the developer). Clone it at the release
tag into the state directory, which is gitignored:

```bash
git clone --depth 1 --branch <release-tag> https://github.com/spryker-shop/<demo-shop>.git .spryker-upgrade/state/reference
# or: gh repo clone spryker-shop/<demo-shop> .spryker-upgrade/state/reference -- --depth 1 --branch <release-tag>
```

List the tags first if you are unsure of the exact name (`git ls-remote --tags <repo-url>`). Then see
what differs:

```bash
php $UP/check-tooling-alignment.php --reference .spryker-upgrade/state/reference
```

It compares the tooling set, `.git.docker` and the deploy files' image and service versions with the
reference, checks that the `docker/` checkout matches `.git.docker`, and writes
`tooling-alignment-report.json`. Exit 0 means aligned, 1 a difference, 2 a reference directory it
cannot read. Packages only the project has show as `project-only`, and packages or deploy keys only
the reference has show as `missing-in-project`; both are informational, never a failure (a package the
project lacks is not added). A `.git.docker` the project lacks is the one `missing-in-project` row that
fails.

## 2. Docker SDK and deploy files — one step

The Docker SDK version and the deploy files depend on each other: a newer image tag or a renamed key
needs the Docker SDK that understands it, and a newer Docker SDK may drop keys the old deploy files
use. Move them together.

1. **Pick the version.** The reference's `.git.docker` names it; its submodule pointer confirms it:
   `git -C .spryker-upgrade/state/reference ls-tree HEAD docker`.
2. **Update the pointer and the file.** `git -C docker fetch --tags`, check out that version in the
   submodule, and write the same version into the project's `.git.docker`.
3. **Read the Docker SDK release notes between the two versions**
   (`gh release list -R spryker/docker-sdk`, then `gh release view <tag> -R spryker/docker-sdk` for each
   one in the range). Note every change to `deploy.*.yml`: image tag, service versions (database,
   search, key-value store, broker), new required keys, renamed or removed keys.
4. **Update every `deploy.*.yml`** accordingly, and compare with the reference's deploy files. Take the
   image tag, the service versions and the key names the release needs. Keep the project's own
   topology — its regions, stores, domains, applications and extra services are project decisions, not
   upgrade material (see SKILL.md § Scope).
5. **Validate.** `script -q /dev/null docker/sdk boot deploy.dev.yml` must pass for each deploy file
   the project uses.
6. Show the developer the deploy diff and the Docker SDK version change, and name any service version
   the project deliberately keeps (a production database engine, for example) — `check-tooling-alignment.php`
   will keep reporting it, and the report lists it as a recorded project choice.

7. **Rebuild.** `script -q /dev/null docker/sdk up -t --build`. The container keeps running the old
   image and Docker SDK until this rebuild, and steps 3 and 4 below — the platform check, the tooling
   update and the baseline re-takes — run in the container.

A new image tag changes the PHP version the project runs on, so step 3 below comes after this one.

If the old lock cannot install on the new image (a locked package does not support the newer PHP),
keep the old image for now and move the image tag together with the Phase 2 update — the Docker SDK
and the other deploy keys still move here, and the rebuild of step 7 still runs. Record in the report
which image the re-taken baselines of step 4 ran on.

## 3. Platform pin — resolve for the project's platform, not the host

Do this before the first composer command below, because every lock produced without it may be
uninstallable, and the failure appears only when something installs it in the container.

```bash
php $UP/check-platform-alignment.php                         # host PHP vs every deploy image, lock vs target PHP
grep -hoE 'tag: spryker/php:[0-9.]+' deploy*.yml | sort -u    # what the project runs
```

If the host PHP minor differs from the image and `config.platform` is absent, stop and fix that first:

```json
"config": { "platform": { "php": "8.3.2" } }
```

`require.php` does not protect you — `">=8.3"` is satisfied by a host on 8.5, so composer selects
dependencies (usually dev tooling: `doctrine/instantiator`, `symfony/*`, `phpunit`) that require a PHP
the container does not have (matrix #56). Choose the lowest patch that satisfies the lock rather than
the image's current patch, because `spryker/php:8.x` is a floating tag and environments pull it at
different times.

Then run composer inside the container anyway:

```bash
script -q /dev/null docker/sdk cli composer check-platform-reqs   # must be 0 failures
```

The pin fixes the PHP version; it does not give the host the project's extensions. A machine without
`ext-redis` or `ext-pgsql` cannot resolve `spryker/redis` at all (matrix #57). Never use
`--ignore-platform-req` to get past that — it re-creates the unrunnable lock the pin prevents (the
Phase 1.5 resolver's host run only discovers constraints; its lock is discarded). Without
the pin the damage surfaces only when `docker/sdk up` fails at `composer install`, phases later, and
any test run on the host in the meantime ran on the wrong PHP minor.

## 4. The tooling set

The tooling set is `phpstan/phpstan`, `spryker-sdk/evaluator`, `spryker/code-sniffer`, `codeception/*`
and `phpunit/phpunit`, plus the PHPStan extensions the project already uses (`phpstan/*`,
`spryker-sdk/phpstan-*`) when the reference moves them too. `check-tooling-alignment.php` compares
exactly these packages.

1. Copy the reference's `require-dev` constraints for those packages into the project's
   `composer.json`. Do not add packages the project does not have.
2. Update only those packages, in the container:

   ```bash
   script -q /dev/null docker/sdk cli composer update phpstan/phpstan spryker-sdk/evaluator spryker/code-sniffer "codeception/*" phpunit/phpunit --with-all-dependencies
   ```

   Add the PHPStan extensions whose constraints moved to that package list.

   A filtered update is right here and only here: it moves the tooling alone and removes no root pin,
   so matrix #35 does not apply. The release-group update in Phase 2 is always a full one.
3. Commit this step on its own (`composer.json`, `composer.lock`, and the tool configuration files the
   new versions require — a renamed PHPStan parameter, a new sniffer ruleset reference).
4. **Re-take the baselines** — the new tools bring new rules, and a finding from a new rule is not
   upgrade damage. The re-takes go to `*-post-tooling.txt`; the Phase 0 files stay as they are:

   ```bash
   script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6 2>&1 | tee .spryker-upgrade/state/phpstan-post-tooling.txt
   script -q /dev/null docker/sdk cli vendor/bin/console code:sniff:style 2>&1 | tee .spryker-upgrade/state/sniff-post-tooling.txt
   script -q /dev/null docker/sdk cli vendor/bin/evaluator evaluate 2>&1 | tee .spryker-upgrade/state/evaluator-post-tooling.txt
   ```

   When `codeception/*` or `phpunit/phpunit` moved, re-take the tests as well, with the exact
   invocation Phase 0 pinned, into `codecept-post-tooling.txt`. Use the project's own sniffer entry
   point if it has one (Phase 0 recorded which). Then `php $UP/check-baselines.php` must exit 0 again:
   it checks each `*-post-tooling.txt` that exists for a real result. From here on a tool's baseline is
   its `*-post-tooling.txt` file (after a deferred tooling step: see the next section).

## When the new tools cannot resolve against the old code

New tooling can require versions of shared dependencies (`symfony/*`, `nikic/php-parser`,
`phpunit`'s own dependencies) that the pre-upgrade Spryker modules exclude. Then:

1. Record the composer output that shows the conflict, and revert the tooling edit to `composer.json`
   and the lock.
2. Run the Phase 2 update without it.
3. Directly after Phase 2 — before Phase 3 and before any lane edits a file — do the tooling step
   above as its own commit, with the re-takes into the `*-post-tooling.txt` files. The Phase 0 files
   stay unchanged.
4. Those re-takes run on upgraded code, so they mix two causes. Compare each with its Phase 0 file:
   a finding whose rule or error identifier is new in the tool version is a tool finding; every other
   new finding is Phase 3 upgrade damage. The baseline for the rest of the upgrade is the Phase 0
   findings plus only the tool findings; list those tool findings in the report.
5. Say in the report that the tooling moved after Phase 2 and why, and which image the re-takes ran
   on when the image tag was deferred too.

Baseline here always means the run recorded under `.spryker-upgrade/state/`. It never means
`phpstan-baseline.neon`, an ignore rule or a narrowed path (non-negotiable 5).

## 5. Finish

`php $UP/check-tooling-alignment.php --reference .spryker-upgrade/state/reference` is clean, or every
remaining difference is a recorded project choice from step 2.6.
