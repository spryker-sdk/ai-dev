# Environment preflight

Shared by `spryker-feature-install` (Phase 0) and `spryker-upgrade`. Run it before the first `composer require` or `composer update`, and before any step that needs the stack (codegen, `propel:install`, imports, verification). Reading a guide or planning a checklist needs no stack: skip the preflight for a plan-only run. Everything here is read-only except the bootstrap, which the user confirms first.

Report a failure as a **pre-existing environment issue**, before the confirmation block, and not as a feature or upgrade failure.

## 1. Is the stack needed, and is it up

`composer` runs in the project's PHP container (`docker/sdk cli composer ...`); `--ignore-platform-reqs` is not an option. Check `docker info` and the running containers of the project (`docker ps`). `docker/` is usually a submodule: `git submodule status` before calling it absent (see `../../spryker-upgrade/references/environment.md`).

When the stack is down or was never booted, **suggest the bootstrap in the confirmation block** instead of failing at `composer require`:

- name the deploy file: the project's `deploy.*.yml` files, with the one the user named or the last-booted one first;
- check for port clashes with other running stacks (`docker ps` published ports, the deploy file's hosts);
- offer `docker/sdk boot <deploy file>` plus `docker/sdk start` when containers exist, `docker/sdk up` when they do not (that is a build and takes a while, say so);
- never `docker/sdk reset` or `clean-data`.

## 2. Does the container see the host files

Compare a checksum of `composer.json` and `composer.lock` on the host and in the container (`docker/sdk cli sha1sum composer.json composer.lock`). On a mutagen-synced project a mismatch means the sync is behind: run `mutagen sync flush` (the project's sync session, `mutagen sync list`), re-compare, and report sync conflicts (for example from deleted `vendor/` folders) without resolving them silently.

## 3. Is `vendor/` in line with `composer.lock`

`docker/sdk cli composer install --dry-run`: expect no operations. Operations listed mean `vendor/` is behind the lock (a dry run of one new package would then show every pending package); report the count, and offer `composer install` as the first step of the run, not as part of the feature.

## 4. Do the frontend dependencies resolve

For every `vendor/**/package.json` the Yves build uses (`spryker-shop/*`, `spryker/*`), check that each dependency appears in the project's `package-lock.json`. A package that requires a dependency the lock lacks (for example `style-dictionary` required by `spryker-shop/shop-ui`) fails the frontend build with `ERR_MODULE_NOT_FOUND`. Report it before the install; the fix is `frontend:project:install-dependencies` or a lock update, offered to the user.
