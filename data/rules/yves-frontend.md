---
name: yves-frontend
description: Use when writing, reviewing, or overriding Yves storefront frontend code — component or view Twig, SCSS, and TypeScript under a Yves module's Theme directory. Enforces project-level overrides of core components, the Twig/BEM/JS-hook conventions no tool checks, manual accessibility review, and honest validation with the project's npm lint scripts.
paths: "src/**/Yves/**/Theme/**/*.{ts,js,scss,twig}"
---

**Frontend rule**
Yves storefront code MUST be customized on project level (`src/Pyz/Yves/{Module}/Theme/...`) by extending core from `vendor/spryker-shop` / `vendor/spryker-feature` — never by editing `vendor/`. It MUST pass the project's npm lint scripts, and the parts no tool checks — Twig conventions and accessibility — MUST be reviewed by hand.

## Overriding core

- Mirror the core relative path: `vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/components/{tier}/{name}/` → `src/Pyz/Yves/{Module}/Theme/default/components/{tier}/{name}/`. The project file wins. If that folder already exists, edit its files — never replace an existing project override.
- Extend the core template and override only the blocks you change: `{% extends molecule('{name}', '@SprykerShop:{Module}') %}` (`@SprykerFeature:{Module}` for feature modules). A plain `'{Module}'` resolves to the project override and extends itself.
- The builder keys entry points by `{tier}/{name}`, and the project entry replaces the core one. A Twig-only override ships no `index.ts`. A project `index.ts` MUST keep every import of the core `index.ts` and re-register the TS class (core class via its alias, e.g. `ShopUi/components/...`) under the Twig `config.tag`. New component names MUST be unique per tier across all modules.
- Import core TS through the module aliases in `tsconfig.yves.json` `paths` (`{Module}/components/...`); add a missing alias there — never a relative path into `vendor/`.

## Review-only conventions (no linter covers Twig)

- Components extend `model('component')` and declare `config` / `data` / `attributes`. A component with a TS class MUST set `config.tag` equal to `config.name`.
- Structural/BEM classes use `config.name`; JS hooks use `config.jsName` (`js-{name}__element`). Never style a `js-` class, never query a styling class from TS.
- Mandatory `data` keys use `required`; new keys on an existing component MUST be optional with a default — other templates and overrides already include it.
- Pass data with `with { data: {...} } only`; pass the module as the second argument of `atom()`/`molecule()`/`organism()` for anything outside ShopUi.
- `| trans` takes a glossary key (`cart.item.add`), never an English sentence; ship the glossary rows in the same change.
- Wrap overridable regions in named `{% block %}`s and hoist reused class names into `{% set %}` variables.
- Test hooks via `{{ qa('{id}') }}`, not hand-written `data-qa`.
- Accessibility has no tooling: use semantic elements (`<button>`, `<nav>`, `<label>`), accessible names on interactive elements, keyboard operability and sensible focus order; never colour as the only signal.
- Import styles with the explicit extension: `import './{name}.scss';`.
- Never silence a rule to reach green. `eslint-disable` / `stylelint-disable` need a comment explaining why the rule cannot apply.

## Validation

- Checks (the CI `js-validation` job runs them on the host with the Node version from `package.json` `engines`; `docker/sdk cli npm run ...` also works): `npm run yves:stylelint`, `npm run yves:lint`, `npm run formatter`.
- Autofix: `npm run formatter:fix`; `npm run yves:stylelint -- --fix`, single file `-- -p <absolute-path.scss>`. `npm run yves:lint -- --fix` is silently ignored — the runner does not forward arguments.
- `npm run yves:lint` exiting 0 is NOT evidence for project TS. The packaged ESLint config may not match the project layout and then ignores `src/Pyz/Yves/**/*.ts` (`File ignored because no matching configuration was supplied`). Check coverage of your file with `--print-config` before citing ESLint.
- `tsc` is advisory, never a gate — no script, no CI job, pre-existing errors on a clean checkout. Run `npx tsc --noEmit -p tsconfig.yves.json 2>&1 | grep '^src/.*{your-file}'` and fix only what points at code you touched.
- A successful `npm run yves` build is NOT verification — webpack transpiles without type-checking. Render the page in the running shop.
- `yves:*` covers only the builder's project source (`src/Pyz/Yves/**/Theme/**`). Do not "fix" Zed or Merchant Portal assets as part of a storefront task.
- For changed-files-only checks use the `static-validation` skill; for component scaffolding, SCSS, TypeScript and Widget depth use the `yves-atomic-frontend` skill.
