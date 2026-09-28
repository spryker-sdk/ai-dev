# Yves Frontend Validation

How to run the storefront checks, read their failures, and what each can fix automatically. Read
`package.json` `scripts` first — script names differ between projects and releases. The commands below
are the ones a standard Spryker project ships; `yves:lint:fix` and `yves:stylelint:fix` do **not**
exist.

## Where the checks run

The CI `js-validation` job runs them on the host after `npm ci`, with the Node version required by
`package.json` `engines`. Locally either run them on the host with a matching Node version, or inside
the container: `docker/sdk cli npm run <script>`. A host Node that is too old fails with errors that
look like builder bugs (e.g. `Named export 'program' not found`) — that is an environment problem, not
your code.

## The checks

| Check | Command | Autofix |
|---|---|---|
| Stylelint | `npm run yves:stylelint` | `npm run yves:stylelint -- --fix` |
| ESLint | `npm run yves:lint` | none — see the trap below |
| Prettier | `npm run formatter` | `npm run formatter:fix` |

Scope: both linters take their file set from the builder — the project source root
(`src/Pyz/Yves/**/Theme/**/*.{js,ts}` and `*.scss` by default). Vendor code, Zed assets and the
Merchant Portal (`mp:*` scripts) are not covered and must not be "fixed" as part of a storefront task.
For checks on changed files only, use the `static-validation` skill.

Establish the baseline before your change: run the checks on a clean checkout, so you can tell your
failures from pre-existing ones.

## Stylelint — safe autofix, single-file mode

```bash
npm run yves:stylelint -- -p <absolute/path.scss>        # check one file
npm run yves:stylelint -- --fix -p <absolute/path.scss>  # fix one file
```

An absolute path is safest (the script runs inside the `shop-ui` npm workspace). Autofix covers
whitespace, casing and units. Selector-budget rules and `color-named` need a structural fix.

## ESLint — two traps

**1. `npm run yves:lint -- --fix` silently does nothing.** The runner builds a fixed argument list and
does not forward yours; it still exits 0.

**2. `npm run yves:lint` may not lint project TS at all.** The packaged config
(`vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/eslint.config.mjs`)
scopes its TypeScript block to a path pattern that may not match `src/Pyz/Yves/...`. Unmatched files are
skipped with only a warning and the run exits 0. Check before citing ESLint as evidence:

```bash
CONFIG=vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/eslint.config.mjs
npx eslint --no-config-lookup --config "$CONFIG" <path.ts>
# "File ignored because no matching configuration was supplied" → your file is NOT linted
```

The runner prefers a project-root `eslint.config.yves.mjs` over the packaged config. If the packaged
config does not cover the project, lint your touched files with a covering config kept **outside the
repository** (so the CI gate is unchanged):

```js
// e.g. /tmp/eslint.config.yves.mjs — replace <project> with the absolute project root
import packaged from '<project>/vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/eslint.config.mjs';

const yvesTsBlock = packaged.find((block) => block.files?.some((pattern) => pattern.endsWith('/Yves/**/*.ts')));

export default [...packaged, { ...yvesTsBlock, basePath: '<project>', files: ['src/*/Yves/**/*.ts'] }];
```

```bash
npx eslint --no-config-lookup --config /tmp/eslint.config.yves.mjs <path.ts>          # check
npx eslint --no-config-lookup --config /tmp/eslint.config.yves.mjs --fix <path.ts>    # autofix
```

Expect pre-existing violations in untouched files once coverage is on; fix only the files you changed.
Committing a covering `eslint.config.yves.mjs` to the project root turns those into CI failures — that
is a separate, deliberate project change, not part of a feature task. `--no-config-lookup` is required
with `--config`.

The rules that bite (`max-lines`, `no-magic-numbers`, `no-console`, `camelcase`) are not autofixable —
see `typescript-patterns.md`.

## Prettier

`npm run formatter:fix` only reformats and is always safe. Settings live in `.prettierrc.json`
(typically `printWidth 120`, `tabWidth 4`, single quotes, trailing commas, semicolons). Twig is **not**
covered.

## TypeScript — advisory, not a gate

There is no `tsc` script and no `tsc` CI job, and webpack (Babel) transpiles without type-checking, so
type errors never fail a build. A clean whole-project `tsc` run is usually unreachable (missing type
packages for Storybook/Merchant Portal aliases, `.mts` imports). Check only your file:

```bash
npx tsc --noEmit -p tsconfig.yves.json 2>&1 | grep '<your-file-name>'
```

Ignore `TS2307` for aliases you didn't touch, `TS5097` (`.mts` imports) and `TS2882` (extensionless
style imports); investigate anything else pointing at your file. Never edit unrelated modules to lower
the count.

## Triage loop

1. Run the single relevant check, narrowed to one file where possible.
2. Read the rule name, file, line and column — the rule name tells you which convention you broke.
3. Fix the cause, not the line: `selector-max-compound-selectors` means the BEM structure is too deep;
   `max-lines` means the component should be split, not that comments should go.
4. Re-run the same check, then the ones that already passed — one tool's autofix can break another's.

Never silence a rule to get green. `eslint-disable`, `stylelint-disable` and `@ts-expect-error` need a
comment explaining why the rule cannot apply.

## What no tool checks

- **Twig** — no linter, no formatter. All Twig conventions are review-only.
- **Accessibility** — no axe/pa11y/Lighthouse. Review semantic elements, labels and accessible names,
  focus order, keyboard operability, contrast, and never colour as the only signal.
- **Runtime behaviour** — a successful `npm run yves` build proves nothing about rendering. Open the
  page in the running shop (`spryker-runtime` skill) and cover important flows with Cypress
  (`cypress-tests` skill).
- **Browser support** — `.browserslistrc` drives autoprefixer and Babel, but nothing verifies that an
  API you used exists in those targets.

## Before you finish

```bash
npm run yves:stylelint && npm run yves:lint && npm run formatter
```

All green (ESLint coverage of your files confirmed), `tsc` advisory checked for your files, the page
rendered, and the review-only items above checked by hand.
