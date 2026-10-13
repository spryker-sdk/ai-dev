# Environment — docker, boot and host fallbacks

Read this in Phase 0 before the first boot, again in Phase 4.9, and whenever a command behaves
differently in the container than on the host.

## The `docker/` directory is usually a submodule

Never conclude the environment is unavailable from a missing or empty `docker/` directory. In most
Spryker projects `docker/` is a git submodule, and an un-initialised submodule looks exactly like an
absent one — an empty directory. Establish it with three commands before making any claim, and re-run
them before writing "not verifiable" in a report:

```bash
git submodule status                 # a leading '-' means present but not initialised
git submodule update --init --depth 1 docker
docker info --format '{{.ServerVersion}} CPUs={{.NCPU}} Mem={{.MemTotal}}'
```

An uninitialised submodule next to a running Docker daemon means everything downstream — migration
diff, index map, the asset build, the Back Office crawl, every browser check — is reachable. Record
work as unverifiable only after these commands show that the environment cannot run it.

Phase 1.2 moves this submodule to the Docker SDK version of the target release. Until then it stays on
the version the project already pins.

## Running commands

- Run composer and CI-grade checks inside docker: `script -q /dev/null docker/sdk cli <cmd>` (a
  pseudo-TTY is required in non-interactive shells). The detector scripts use reflection or static
  parsing only and run fine on host PHP.
- Mutagen two-way sync can resurrect deleted files — delete in the container too, then
  `mutagen sync flush <sync-session>`. It is also asynchronous: after a host-side edit, wait until
  the container sees the change before running composer or console there (matrix #67).
- Never modify files under `vendor/` — all resolution happens in `src/Pyz` and `config/`. The one
  exception is a package whose bundles are merged into the root via composer-merge-plugin: that is
  another repository and must be fixed there (matrix #39/#40).

## Booting (Phase 0 on the pre-upgrade code, Phase 4.9 on the upgraded code)

Delegate the boot itself to the `boot-and-verify` sibling skill where it applies; what follows is
what an upgrade specifically needs from the boot.

```bash
git submodule update --init --depth 1 docker
script -q /dev/null docker/sdk boot deploy.dev.yml  # generation only, cheap, validates the deploy file
script -q /dev/null docker/sdk up -t --build --assets --data
```

`-t` starts the testing container. Without it `docker/sdk testing …` runs nothing and exits 0, so every
test baseline and test run of the upgrade reads as green without having run. An environment already
up without `-t` gets it by re-running `docker/sdk up -t`; volumes and data are kept.

Read the deploy file before booting — it changes the whole verification plan:

- **Which applications exist.** `grep -oE "application: [a-z_]+" deploy.*.yml | sort -u`. A project
  with no `yves` entry is headless: there is no storefront to check, and the Back Office is the only
  UI. Do not write a storefront checklist for a project that has no storefront.
- **How many stores.** A 9-store deploy with 8 workers per application will not fit in a laptop's
  Docker memory alongside OpenSearch, MariaDB, RabbitMQ and Jenkins. If `docker info` reports less
  than ~16 GB, expect to verify against one store. Prefer an additive `deploy.upgrade-verify.yml`
  (copy, one region/store, optional dev services dropped) over editing the project's own deploy files
  — and say in the report that verification ran on a trimmed topology, because a single-store boot
  does not exercise store-resolution paths. Use the same topology for the Phase 0 baseline and the
  post-upgrade runs, or the comparison is meaningless.
- **`docker.testing.store`** — the store the test suites will use.

`boot` prints a `sudo … /etc/hosts` command. You cannot run it (it needs the developer's password):
surface it verbatim and continue — the build and the whole CLI/test path do not need it, only browser
access by hostname does.

Run the build in the background with a monitor covering **failure** signatures, not just progress: a
filter that greps only for success markers stays silent through an OOM kill or an image-pull failure,
and no output then looks the same as "still building".

If the boot cannot complete (insufficient memory, an image the project has no credentials for),
record which checks it costs, using the Phase 5, 5.5 and 6.5 lists as the
inventory of what is now unproven. "Could not boot" is only acceptable after `git submodule status`
and `docker info` have both been shown.

## Console commands outside docker (fallback when `docker/sdk` is absent)

The environment must be supplied explicitly, and the default memory limit is too low for
`transfer:generate` — it dies at 128M with a misleading fatal after partially generating:

```bash
APPLICATION_ENV=development SPRYKER_CURRENT_REGION=GLOBAL DYNAMIC_STORE_MODE=true \
  php -d memory_limit=3072M vendor/bin/console transfer:generate
```

Confirm the real exit code with stdout/stderr separated — a nonzero status can come from a shutdown
handler after the work succeeded, so check the artifacts too (`ls src/Generated/Shared/Transfer | wc -l`).

PHPStan needs `src/Generated/Client/Ide/AutoCompletion.php`, produced by
`dev:ide-auto-completion:generate`. If that command is not registered in the project's console, run
PHPStan against a copy of `phpstan.neon` with the `bootstrapFiles` block removed rather than skipping
static analysis — it is the only thing that catches constructor-arity breakage.

A host-side test run is never a substitute for the suite in the container: host PHP is usually a
different minor from the deployed image (matrix #58), and anything needing a database, a search
backend or a broker cannot run there.
