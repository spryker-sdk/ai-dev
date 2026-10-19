# Yves Frontend Validation

Commands and the non-negotiables are in `.claude/rules/yves-frontend.md`. This file holds the mechanics
behind them. Check `package.json` `scripts` first — `yves:lint:fix` and `yves:stylelint:fix` do not exist.

## Where checks run

CI runs `npm ci` then the scripts on the host with the Node version from `package.json` `engines`.
Locally, use a matching host Node or `docker/sdk cli npm run <script>`. A too-old host Node fails with
errors that look like builder bugs (e.g. `Named export 'program' not found`) — environment, not code.

Linters take their files from the builder's `./src/...` entries in `paths.sources` (default `./src/Pyz/Yves`,
plus any custom namespace registered in `frontend/yves.settings.mts`) — `**/Theme/**` only; vendor, Zed
and Merchant Portal are out of scope. For changed-files-only checks use the `static-validation` skill.
Run the checks on a clean checkout first to know the baseline.

## Stylelint

```bash
npm run yves:stylelint -- --fix -p <absolute/path.scss>   # drop --fix to check only
```

Config: `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/stylelint.config.mjs`
(`stylelint-config-standard-scss` + rules from `spryker-base-stylelint.mjs`; a project-root
`.stylelintrc.js` replaces it). Direct run on a too-old host Node:
`npx stylelint --config <that file> <path.scss>`. The ones that need structural fixes, not `--fix`:
`selector-max-class` 2, `selector-max-compound-selectors` 3, `selector-max-id` 1, `color-named` never.
Also: no `pt`, no vendor prefixes, `::` pseudo-elements.

## ESLint — two traps

1. `npm run yves:lint -- --fix` is silently ignored — the runner does not forward arguments.
2. `npm run yves:lint` may not lint project TS at all: the packaged config
   (`.../FrontendBuilder/libs/lint/eslint.config.mjs`) scopes its TS block to a pattern that may not
   match `src/{ProjectNamespace}/Yves/...` (`{ProjectNamespace}`: see `SKILL.md`), and unmatched files are skipped with exit 0. Check:

```bash
npx eslint --no-config-lookup \
  --config vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/eslint.config.mjs <path.ts>
# "File ignored because no matching configuration was supplied" → NOT linted
```

The runner prefers a project-root `eslint.config.yves.mjs`. If the packaged config doesn't cover the
project, lint touched files with a covering config kept **outside the repo** (CI stays unchanged):

```js
// /tmp/eslint.config.yves.mjs — <project> = absolute project root
import packaged from '<project>/vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/eslint.config.mjs';

const yvesTsBlock = packaged.find((block) => block.files?.some((pattern) => pattern.endsWith('/Yves/**/*.ts')));

export default [...packaged, { ...yvesTsBlock, basePath: '<project>', files: ['src/*/Yves/**/*.ts'] }];
```

```bash
npx eslint --no-config-lookup --config /tmp/eslint.config.yves.mjs [--fix] <path.ts>
```

`src/*/Yves` covers every project namespace. A custom namespace must also be in `tsconfig.yves.json`
`include` — otherwise ESLint fails with `"parserOptions.project" has been provided` and `tsc` below
silently reports nothing for the file.

Expect pre-existing violations in untouched files; fix only yours. Committing a covering config turns
them into CI failures — a separate project decision. Rules that bite and aren't autofixable:
`max-lines` 200 (split the component), `@typescript-eslint/no-magic-numbers` (hoist to
`protected readonly` fields — readonly initialisers are exempt), `no-console`, `camelcase`.

## TypeScript — advisory

No `tsc` script or CI job; Babel transpiles without type-checking. A clean whole-project run is usually
unreachable. Check only your file and ignore `TS2307` on aliases you didn't touch, `TS5097` (`.mts`) and
`TS2882` (extensionless style imports):

```bash
npx tsc --noEmit -p tsconfig.yves.json 2>&1 | grep '^src/.*<your-file-name>'
```

Anchor the grep to `src/`: extending a core class means the core file has the same name, and its
pre-existing errors (e.g. core `cart-items-list.ts` `TS2554`) are not yours.

`tsconfig.base.json` has `strict: false`, `noImplicitAny: false` — write explicit types.

## Not checked by any tool

Twig (no linter, Prettier skips it), accessibility, runtime rendering, browser support
(`.browserslistrc` only drives autoprefixer/Babel). Render the page (`spryker-runtime` skill) and cover
key flows with Cypress (`cypress-tests` skill).
