---
name: configure-codebase
description: "Use when a Spryker project adopts a custom namespace instead of Pyz and the code layer must resolve, build, lint, and test it — namespace registration, composer autoload, frontend build config, codeception wiring. A pre-boot wizard step of project start; skipped entirely when the project keeps Pyz."
---

# configure-codebase

Goal: after this, a class in `src/<Ns>/...` overrides Pyz/core, a Yves component under `src/<Ns>/Yves/*/Theme` compiles, and the project has **runnable test infrastructure** — a committed example test under `tests/<Ns>Test/` that `codecept build` + `codecept run` prove green (post-boot), not just an empty `.gitkeep` tree. Verified working by the playbook's E4 experiment — this skill encodes exactly what E4 proved is needed.

Read `.ai-dev/project-setup.md` → `namespace`. If `mode: keep-pyz`, record the step `skipped (keep-Pyz)` and stop. Otherwise `<Ns>` = `namespace.name` (validated CamelCase, no collision with `Pyz`/core).

Work from real files; these are judgment edits (each config file has its own idiom), not bulk transforms.

> **Placement principle — governs the WHOLE project run, not just this step.** Once `<Ns>` is registered (`PROJECT_NAMESPACES = ['<Ns>','Pyz']`, `<Ns>` wins resolution), **every project-level PHP customization — config classes, plugins, expanders, dependency providers — is created in `src/<Ns>/…`, EXTENDING the `Pyz` class (or core if no Pyz counterpart exists), and `src/Pyz/…` is left pristine** as the inherited demoshop layer. **Never edit a `src/Pyz` class in place to customize it** when a custom namespace is set — including store-keyed config classes a later step (e.g. `define-stores`' literal sweep) needs to change. Extend the **Pyz** class, not core, when a Pyz counterpart exists, or its existing overrides are silently dropped. (`keep-pyz` mode: `src/Pyz` *is* the project layer — edit it directly.) This is the rule the other skills assume; real regression — `SalesPaymentMerchant*` configs were edited in `src/Pyz` while `StockConfig`/`CheckoutPageConfig` were correctly created in `src/<Ns>`.

## Edits

1. **Register the namespace** — `config/Shared/config_default.php`: `KernelConstants::PROJECT_NAMESPACE = '<Ns>'` (primary), `KernelConstants::PROJECT_NAMESPACES = ['<Ns>', 'Pyz']` (resolver order — project wins, Pyz stays as working fallback so the app keeps booting via its literal `Pyz\` bootstraps), and the same in `GlueBackendApiApplicationConstants::PROJECT_NAMESPACES`.
   - **CRITICAL — the environment config files CLOBBER this edit.** The per-environment overlays load *after* `config_default.php` and this clone **re-assigns `GlueBackendApiApplicationConstants::PROJECT_NAMESPACES = ['Pyz']`** in `config/Shared/config_default-docker.dev.php` and `config_default-ci.php` — i.e. in exactly the environment the first boot runs (`deploy.dev.yml` → `environment: docker.dev`) and in CI. Editing only the base file ships a Backend Glue API that silently ignores `src/<Ns>`. **Grep every `config/Shared/config_default-*.php` for `PROJECT_NAMESPACE` re-assignments and patch each one to `['<Ns>', 'Pyz']`** — the base-file edit alone is not registration.
   - **Glue API Platform source directories are Pyz-hardcoded separately from the resolver.** `config/Glue/packages/spryker_api_platform.php`, `config/GlueBackend/packages/spryker_api_platform.php`, and `config/GlueStorefront/packages/spryker_api_platform.php` each call `sourceDirectories([...'src/Pyz'...])` — API resources under `src/<Ns>` are invisible to discovery until `'src/<Ns>'` is appended in all three (anchored edit; the array also carries vendor paths — add, never replace).
2. **Autoload** — `composer.json`: `autoload.psr-4` add `"<Ns>\\": "src/<Ns>/"`; `autoload-dev.psr-4` add `"<Ns>Test\\": "tests/<Ns>Test/"`. (Autoload dump happens at boot.)
3. **Skeletons** — `src/<Ns>/{Client,Glue,Service,Shared,Yves,Zed}/` with `.gitkeep`, including `src/<Ns>/Zed/Translator/data/` (the project-level Back Office translation location, auto-scanned per project namespace). `tests/<Ns>Test/{Zed,Yves,Glue,Shared}/`.
4. **Test infrastructure — must be RUNNABLE, not a `.gitkeep` skeleton.** "Ready to write tests" is not the deliverable; **working test infrastructure is** (a main requirement). A test only runs when its *module* has a `codeception.yml` **and** the aggregate suite configs include the namespace — a bare skeleton runs nothing. Do all four; the first three are pre-boot file edits, the fourth is verified post-boot:
   - **Root `codeception.yml`** — add `tests/<Ns>Test/*/*` to `include`; add `src/<Ns>/*.php` to the coverage whitelist.
   - **The three aggregate suite configs** — `tests/codeception.acceptance.yml`, `tests/codeception.api.yml`, `tests/codeception.ci.functional.yml` each ship `include: PyzTest/*/*` and whitelist `src/Pyz/*.php` **only**. Add `<Ns>Test/*/*` to each `include` and `src/<Ns>/*.php` to each whitelist — otherwise `codecept run` via those suites silently skips `<Ns>Test` and never covers `src/<Ns>`. (Patching only the root config — the earlier gap — leaves these three blind.) **Leave each file's top-level `namespace: PyzTest` key untouched** — it only namespaces root-level suites (there are none); it is not a resolver setting to "fix".
   - **Store helper — the one thing that makes the PHP suites green from the FIRST CI run on a non-`DE` project (two places).** The core `\SprykerTest\Shared\Store\Helper\StoreDependencyHelper` hardcodes `DEFAULT_STORE = 'DE'` and publishes it as the container store service, so on any project whose stores aren't named `DE` the suites throw `StoreNotFoundException: Store with name "DE" not found` — **deep inside a plugin (e.g. the tax calculator), so it reads as a code bug, not a test-infra defect** — before exercising any project code. Fix it with a **project-level helper the suites resolve the store from the DB through** (b2b-demo-marketplace PR #1270 / CC-39652):
     1. **The helper (one source of truth)** — `tests/PyzTest/Shared/Store/_support/Helper/StoreDependencyHelper.php`, `namespace PyzTest\Shared\Store\Helper;`, extends the core `SprykerStoreDependencyHelper`, uses `LocatorHelperTrait`, and overrides `_before()` to set `SERVICE_STORE` to `getLocator()->store()->facade()->getCurrentStore(true)->getNameOrFail()` (the DB store, whatever the project named it).
     2. **Every suite `codeception.yml` that enables a store helper** — swap `- \SprykerTest\Shared\Store\Helper\StoreDependencyHelper` → `- \PyzTest\Shared\Store\Helper\StoreDependencyHelper` in `modules.enabled` (the demoshop repointed its ~33 suite configs). **When you author a new `<Ns>Test` module suite that needs a store, reference the PROJECT helper, never the core one.**
     - **Detect first, don't assume:** recent clones already ship this (helper present + suites repointed → inherit it, just follow the pattern for new suites). Older clones don't — `grep -rl 'SprykerTest\\Shared\\Store\\Helper\\StoreDependencyHelper' tests/PyzTest --include=codeception.yml` finds the suites still on the core helper; then create the helper and repoint them.
     - **Also fix the store-name-vs-country-ISO2 conflation** the same PR addressed: a suite must derive the country from the store's `getCountries()`, not reuse the store name as an ISO2 literal, or tax/shipping totals silently drift on a non-`DE` store.
     - **Sibling trap in the CI static-analysis job** (not codeception, but the same "shipped CI assumes store `DE`" family): its `console` runs on the bare runner and fatals `Missing setup for store: <STORE>` unless the DMS job sets `SPRYKER_DYNAMIC_STORE_MODE: true` — owned by `project-ci-generator`, recorded in **project-starter-wizard step 1**.
   - **`test-autoload.php`** — add an `<Ns>Test` branch mirroring the `PyzTest` branch (same `array_shift` → `tests/<Ns>Test/<app>/<module>/_support/<Class>.php` path) so generated actor/support classes autoload.
   - **One committed, runnable seed module** — `tests/<Ns>Test/Shared/Example/`, BOTH the green proof and the canonical copy-me template. Its `codeception.yml` MUST set `namespace: <Ns>Test\Shared\Example` and, on `LocatorHelper`, **`projectNamespaces: ['<Ns>', 'Pyz']`** — the PyzTest module configs hardcode `['Pyz']`; copying that blind makes a test resolve only `src/Pyz` and **silently never exercise your `src/<Ns>` overrides** (the single most important gotcha). Files:
     ```yaml
     # tests/<Ns>Test/Shared/Example/codeception.yml
     namespace: <Ns>Test\Shared\Example
     paths: { tests: ., data: _data, support: _support, output: _output }
     coverage:
         enabled: true
         remote: false
         whitelist: { include: ['../../../../src/*'] }
     suites:
         Example:
             path: Example
             actor: ExampleTester
             modules:
                 enabled:
                     - Asserts
                     - \PyzTest\Shared\Testify\Helper\Environment
                     - \SprykerTest\Shared\Testify\Helper\LocatorHelper:
                         projectNamespaces: ['<Ns>', 'Pyz']
     ```
     **Assert through the actor (`$i->assertX()`), NEVER static `PHPUnit\Framework\Assert::` — the Spryker sniffer rejects an unused `$i` parameter, and the Cest signature requires it.** A static `Assert::assertTrue(true)` with an unused `$i` fails `code:sniff:style`.
     ```php
     <?php // tests/<Ns>Test/Shared/Example/Example/ExampleCest.php
     declare(strict_types = 1);
     namespace <Ns>Test\Shared\Example\Example;
     use <Ns>Test\Shared\Example\ExampleTester;
     class ExampleCest
     {
         public function testCustomNamespaceTestInfrastructureIsRunnable(ExampleTester $i): void
         {
             $i->assertTrue(true);
         }
     }
     ```
     A smoke test (`assertTrue(true)`) only proves the *harness* runs — not that the namespace resolves. Ship a **second cest that actually asserts**, and make it fail on a real regression WITHOUT a circular check: `Config::get(KernelConstants::PROJECT_NAMESPACES)` inside a suite is **useless** — `LocatorHelper` overwrites that config from the suite's own `projectNamespaces:` YAML, so the assertion passes even with `<Ns>` unregistered in `config_default.php`. Assert what the suite CANNOT fake — composer autoload wiring (app-level, not stubbed), and (once the project has a real override) resolver **precedence**:
     ```php
     <?php // tests/<Ns>Test/Shared/Example/Example/ProjectNamespaceCest.php
     declare(strict_types = 1);
     namespace <Ns>Test\Shared\Example\Example;
     use <Ns>Test\Shared\Example\ExampleTester;
     class ProjectNamespaceCest
     {
         public function testComposerAutoloadsTheProjectNamespace(ExampleTester $i): void
         {
             $composer = json_decode((string)file_get_contents(APPLICATION_ROOT_DIR . '/composer.json'), true);
             $i->assertSame('src/<Ns>/', $composer['autoload']['psr-4']['<Ns>\\'] ?? null);
             $i->assertSame('tests/<Ns>Test/', $composer['autoload-dev']['psr-4']['<Ns>Test\\'] ?? null);
         }
         // Once a real override exists, add a precedence test (the resolver CANNOT be faked by the suite):
         //   (new \Spryker\Zed\Kernel\ClassResolver\Config\BundleConfigResolver())
         //       ->resolve(\Pyz\Zed\<Module>\<Module>Config::class)  →  assertInstanceOf(\<Ns>\Zed\<Module>\<Module>Config::class)
         // and assert the override extends the *Pyz* class (not core), plus its payload (e.g. store→warehouse map).
     }
     ```
     **Commit the cests CS-fixed** (`code:sniff:style` runs in CI) — and note `codecept build` regenerates the actor, which can re-break CS; re-run the fixer after build if the actor is committed.
   - **Prove it green — POST-boot** (codeception needs the `composer install` the first boot performs; this step runs pre-boot, so `vendor/bin/codecept` does not exist yet). The run is verified as the tail of the first boot — **boot-and-verify** runs `docker/sdk cli vendor/bin/codecept build` then `docker/sdk cli "vendor/bin/codecept run -c tests/<Ns>Test/Shared/Example Example"` and expects `OK`. Infrastructure is not "created" until that passes. Every real per-module config a developer later adds copies this seed's `namespace` + `projectNamespaces` shape.
5. **Frontend build** — `frontend/settings.js`: **inspect the file's actual shape first — it comes in two forms, and this demoshop ships the second.** (a) *If it has a `projectNamespaces` array* — add `./src/<Ns>/Yves` to it (component finder + stylelint scan both spread it). (b) *If it has NO `projectNamespaces`* (this clone: a single `paths.project = './src/Pyz/Yves'` string, and the finder `dirs` arrays spread `paths.project` individually) — additively append `join(context, './src/<Ns>/Yves')` to the `componentEntryPoints.dirs` and `shopUiEntryPoints.dirs` arrays (leave `paths.project` as-is; you're adding a second dir, not replacing). Match the file's real structure — don't force the `projectNamespaces` shape onto a file that doesn't use it. `tsconfig.base.json` / `tsconfig.yves.json`: add `./src/<Ns>/Yves/**/*` to the `include` array (and the `Zed` path in `tsconfig.base.json`). Leave `tsconfig.yves.json`'s `compilerOptions.paths` unchanged — the per-module aliases (`src/ShopUi/*`, `src/CompanyWidget/*`, `src/ConfigurableBundleWidget/*`, `src/ProductImageWidget/*`) stay pointed at `./src/Pyz/Yves/<Module>/Theme/default/*` only. Do not prepend `./src/<Ns>/Yves/...` to them: the frontend build resolves each alias to its first entry, so with `src/<Ns>/Yves` still an empty skeleton at this point, every `src/ShopUi/*` and `~src/ShopUi/*` import resolves to an empty directory and `frontend:yves:build` aborts. The namespace's Yves components are wired through the `componentEntryPoints.dirs`/`shopUiEntryPoints.dirs` additions in `frontend/settings.js` (above), not through these aliases. `package.json`: extend `yves:lint`/`yves:lint:fix` globs to `./src/{Pyz,<Ns>}/Yves/...`. `frontend/libs/stylelint.mjs`: **verify its real scss-path source** — if it derives from `projectNamespaces`, shape (a) covers `<Ns>`; if it derives from `paths.project` or its own dirs list (shape b), add `<Ns>` there the same additive way. Leave the Merchant Portal Angular app-shell (`angular.json`, `tsconfig.mp.json`) Pyz-anchored — no new theme.
6. **PHP code style (`phpcs.xml`) — make the namespace-aware sniffs know `<Ns>`.** `<file>src/</file>` already scans `src/<Ns>/`, so the *general* sniffs cover the custom namespace with **no path edit**. Two namespace-specific points:
   - **`Spryker.MethodAnnotation.*` rules** (Factory / Config / Facade / Repository / EntityManager / QueryContainer) carry a `<property name="namespaces" type="string" value="Pyz,SprykerEco,…"/>` comma-list that tells the sniff where to find and annotate those classes. **If the clone's `phpcs.xml` has them, add `<Ns>` to each list** (anchored edit to the string, e.g. `value="<Ns>,Pyz,SprykerEco,…"`) so the project's `*Factory`/`*Config`/`*Facade`/… get correct `@method` annotations validated.
   - **Leave `Spryker.Namespaces.SprykerNamespace` `namespace="Pyz"` as-is** — that property names the base bootstrap namespace (Pyz stays in `PROJECT_NAMESPACES` as the resolver fallback); the custom namespace belongs in the `MethodAnnotation` lists above, not here. Don't switch it to `<Ns>`.
   - **Discover what the clone actually ships — don't assume.** Some clones ship a **leaner** `phpcs.xml` with **no `MethodAnnotation.*` rules at all** (the current base clone is one — it has only `SprykerNamespace`); then there is nothing to extend. If the project wants `@method` annotation checks on its custom-namespace classes, add the six rules following the same pattern; otherwise flag it as an optional follow-up and move on — it is not a boot or build blocker. Apply the same grep-for-`Pyz`-and-add-`<Ns>` check to any other lint/analysis config that hardcodes a namespace list (`phpstan.neon`, architecture rulesets).
   - Anchored, format-preserving Edit — never restructure the XML.
7. **eslint gap (E4.5 — real pre-existing demoshop bug):** `eslint.config.mjs` — the Yves-TS block's `files` glob is a vendor-package path shape that matches **no** project code (Pyz included), so project Yves `.ts` is silently unlinted. Add `'src/{Pyz,<Ns>}/Yves/**/*.ts'` to that block's `files`. **Warn:** this surfaces a pre-existing violation backlog in existing Pyz Yves TS — either fix it or the lint gate flips red; flag as a follow-up.
8. **architecture-sniffer (E4.5):** no project command runs it today. If arch-sniffing is wanted, add a composer script pointing at `src/`. Optional; flag rather than assume.

## Verify (static; runtime proof is boot-and-verify's job)

**Namespace registration is complete only when a Grep for `PROJECT_NAMESPACE` across ALL of `config/Shared/config_default*.php` (base + every `-<env>` overlay) shows `<Ns>` in every assignment** — one overlay still assigning `['Pyz']` silently un-registers the namespace in that environment (the docker.dev/ci clobber above). `php -l` the edited PHP (incl. the seed `ExampleCest.php`); **JSON-validate `composer.json`/`tsconfig*`/`package.json` with php** (allowlisted: `php -r 'json_decode(file_get_contents("composer.json")); echo json_last_error_msg();'`) — not `node`. Only the **JS** files (`frontend/settings.js`, `*.mjs`) need `node --check`; `node` isn't in the base allowlist, so either recommend adding `node:*` or treat the JS syntax-check as optional (it's belt-and-suspenders — the boot's frontend build is the real proof). The **`codecept build`/`run` green proof for the seed module is post-boot** (needs `composer install`) — carried by boot-and-verify, not here. Update the `configure-codebase` step; note the eslint backlog follow-up if surfaced.

## Dev-loop reference (verified edit→effect, E4 — pass to the team)

- Class override (`src/<Ns>/**/*.php`): add/remove/rename → `docker/sdk console cache:class-resolver:build` (method-body edits need no rebuild).
- Transfer (`src/<Ns>/Shared/*/Transfer/*.transfer.xml`): `transfer:generate` → `src/Generated/`. Propel schema (`src/<Ns>/Zed/*/Persistence/Propel/Schema/*.schema.xml`): `propel:schema:copy` → `propel:model:build` → `propel:diff`. Both discover `src/<Ns>` by path glob — no namespace coupling.
- Tests: a new module copies the `tests/<Ns>Test/Shared/Example` seed (its `codeception.yml` with `namespace: <Ns>Test\...` + `projectNamespaces: ['<Ns>','Pyz']`, actor, cest) → `vendor/bin/codecept build` → `codecept run -c <suite-dir> <Suite>`. Never copy a PyzTest config's `projectNamespaces: ['Pyz']` verbatim — set `['<Ns>','Pyz']` or the test won't see `src/<Ns>`.
- macOS/OrbStack bind-mount has a sync lag both directions — re-check generated/removed files a beat later before asserting.
