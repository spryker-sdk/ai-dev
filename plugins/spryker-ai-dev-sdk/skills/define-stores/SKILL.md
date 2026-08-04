---
name: define-stores
description: "Use when a Spryker DMS project's stores or region must be created or redefined pre-boot — a new store set, a region rename, or hardcoded store/locale literals (CodeBucketConfig, default_store, host maps) that block boot. Invoked by the wizard's store step, or standalone on an un-booted clone. Store skeleton only — catalog/CMS/price content is project-data's job."
---

# define-stores (skeleton + config, files-mode)

You make the project's stores **exist** before first boot: the store-definition CSVs, the region token across deploy/recipes, and the hardcoded literals that otherwise fatal the boot. You do **not** bring catalog/CMS/price content — that is `project-data`. You adapt the shipped store-definition import **in place**, working from the store dirs — but read the seeding rule first.

> **CRITICAL — seed EVERY project store from ONE canonical demo source dir; do NOT 1:1-rename whichever demo dirs happen to exist.** The shipped demo store dirs embody **different commercial conventions**, so pairing project stores with different demo dirs produces **structurally inconsistent stores**. Real regression (biggest failure of a whole run): three demo dirs (`DE`/`AT`/`US`) mapped 1:1 onto three project stores because the counts matched — but `common/US` is **net-only** (US quotes pre-tax), **USD**, and currency-mixed per file, while `DE`/`AT` carry gross+net in EUR. The US-seeded store ended up with 4,124 `gross=0` prices (no visible prices), lost four products' only prices (their "stray EUR" rows were deleted as dirt), and priced off a USD→EUR→PLN double conversion — while its siblings priced off EUR directly. Two resets and half the debugging went to this.
>
> **The rule:** pick ONE canonical source dir — the primary EU one, `common/DE` (gross+net, EUR, richest/most-complete) — and seed **all** project stores from it: rename `common/DE` to your first project store, **copy** it for each additional store, and **DELETE the other demo store dirs** (`common/AT`, `common/US`, …) so their divergent data never enters. Project stores differ only by locale / currency / country / store-name; the catalog+price *structure* is identical across them (one source currency, one rate per target — see `project-data` adapt). Never repurpose a differently-shaped demo dir just because it exists.

Concretely: rewrite each store dir's CSVs (`store.csv`, per-store `currency_store`/`country_store`/`locale_store`/`default_locale_store`). No `data/import/<project>/` tree — git on the fresh clone is the diff/revert.

You drive the `spryker-import-tools` scripts (`csv.php` + `validate.php`); you supply the judgment. Read `references/literal-sweep.md` — the authoritative region-token surface and config-literal spots (proven by the playbook run).

**Tools & command discipline:** follow **`spryker-import-tools` → "Invocation & command discipline"** (the authoritative copy). In short: invoke `csv.php`/`validate.php` by their literal path from the project cwd (`$CSV`/`$VALIDATE` = that path, substituted inline — never a shell variable, never `cd`); one op over many files = one command with `--in-place`; no shell loops/operators; count via `rowCount`/`matchedRows`. Specific to this step: move the canonical source dir to the first store with a single `mv`; `cp -r` the canonical dir to each **additional** store (this single-source copy is the one place `cp -r` is correct — see the seeding rule above); then `rm -rf` the other demo store dirs you're not seeding from (each `rm` surfaced as an explicit deletion step).

## Inputs

Read `.ai-dev/project-setup.md`: `project.name` (→ import root `data/import/<slug>/`), `region`, `stores[]`. Standalone without a state file → ask only for these.

## Steps

1. **Store-definition CSVs** — small, write directly with exact demoshop headers. Per region: `<REGION>/store.csv` (`name` rows, one per store). Per store dir `<STORE>/`:
   - `currency_store.csv` — `currency_code,store_name,is_default` (one row per currency; default flagged).
   - `country_store.csv` — `store_name,country` (mirror the shipped header).
   - `locale_store.csv` — one row per locale.
   - `default_locale_store.csv` — single row.
   - `store_context.csv` — keep the misspelled header `store_name,appication_context_collection` verbatim; timezone JSON payload.
   Match the shipped column shapes exactly — read a demoshop store dir (e.g. `data/import/common/DE/`) as the reference; never invent columns.

2. **Region token — full surface** (`references/literal-sweep.md`): rewrite the old region token across `deploy.dev.yml` (regions/groups/endpoints/service namespaces/hosts/`docker.testing.region`) and the four active `config/install/*.yml` recipes; **rename (`mv`, not `cp -r`)** the primary shipped region dir to `config/install/<REGION>/` and rewrite its tokens, removing the other shipped region dir(s) — per the literal-sweep reference. Anchored, formatting-preserving edits.
   - **The region-token rewrite creates file obligations — this skill owns the STORE-DEFINITION manifest.** The recipes reference `data/import/local/store_${SPRYKER_CURRENT_REGION}.yml` (and `full_…`), so after the token rewrite those paths point at files that don't exist yet. Rename `data/import/local/store_EU.yml` → `store_<REGION>.yml` and repoint its `source:` paths at the project store dirs (pure store skeleton — this skill's charter). The catalog manifest (`full_<REGION>.yml`) stays with `project-data` (its import-config step) — but note the obligation exists so a `leave`-mode or standalone run doesn't strand the recipes.

3. **Hardcoded literals** (`references/literal-sweep.md`): `CodeBucketConfig::getCodeBuckets()` (hard boot blocker — set to region + stores), `config/Shared/default_store.php` (a declared store), `STORE_TO_YVES_HOST_MAPPING`/`REGION_TO_YVES_HOST_MAPPING`, Zed translator fallback map (add `<new_locale> => ['en_US']`), `StockConfig` store→warehouse, `CheckoutPageConfig` T&C locales.

4. **Dangling-manifest sweep — EVERY yml that references a deleted/renamed store dir, not just the classic-mode pair.** Deleting the demo store dirs (seeding rule above) breaks every manifest that `source:`s them. Grep **all** of `data/import/**/*.yml` for paths into the removed dirs and disposition each hit explicitly:
   - the classic-mode (`dynamic-store-off`) variants (`data/import/production/full_<REGION>.yml`, `config/install/<REGION>/*.dynamic-store-off.yml`) — **delete when `store_mode: dms`**;
   - the per-PBC `*_setup_import_config_<SRC-REGION>.yml` group, `minimal.yml`, `b2b_full_*`, `full_ROBOT.yml`/`b2b_full_ROBOT.yml` — delete the ones no active lane uses (verify against `config/install/*.yml`);
   - the **`data/import/b2b_robot/{DE,AT,EU,US}/` fixture tree** wired to the robot CI recipes (`config/install/docker.robot.ci.*.yml`) — its fate is the CI keep/drop-lane decision recorded by `project-ci-generator`: dropped lane → delete tree + recipes; kept lane → the fixtures must be adapted (flag, don't silently keep broken).
   Leaving broken inert config is the worst option. Surface each `rm` as an explicit deletion step.

## Validate

- `php "$VALIDATE" refs <each store-def *_store.csv> --column <store-col> --in <stores>` → store values ⊆ declared (detect the store column from the header; it varies).
- `php "$VALIDATE" absent src/SprykerConfig/CodeBucketConfig.php config/Shared/default_store.php --string "'<OLD_STORE>'"` → **zero hits required** (must-be-clean; boot blocker otherwise).
- Broader `absent` over `config/` + `src/Pyz` is a **triage aid, not a gate** — some literals are intentional keeps (`de_DE` in the translator fallback, classic `stores.php`); classify per `references/literal-sweep.md`, don't blindly "fix". Old locale = demoshop locales NOT in the project set (e.g. only `de_DE`; `en_US` is kept).

Mark the `define-stores` step `done`. Hand off to **`project-data`** for the content per `data.mode` — it picks the strategy: **adapt** (reshape the demo catalog), **clean** (minimal bootable set), **generate** (themed catalog); each assembles its own import config. (`leave` skips this skill entirely — demo stores unchanged.)

## Boundary

This skill builds the **skeleton** (store definitions + config/region/literals) — no catalog, CMS, prices, glossary, or import config. All content + the import-config assembly belong to the chosen data-mode skill. Live-project store changes are the later `add-store`/`delete-store`.

> **The content path is chosen by `data.mode`, all handled by `project-data`:** `adapt` (reshape the demo catalog), `clean` (minimal bootable set, no demo catalog; keep-list in `references/minimal-baseline.md`), `generate` (themed catalog). `leave` skips both this skill and the content step (demo stores + data kept as-is).
