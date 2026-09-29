# yves-atomic-frontend

Create, extend, and override **Spryker Yves storefront frontend code in a project** — atomic components,
templates, views, and Widgets — across Twig, SCSS, and TypeScript. Core is read from `vendor/` and
extended into the project namespace (`src/{ProjectNamespace}/Yves` — the custom namespace listed first in
`KernelConstants::PROJECT_NAMESPACES`, otherwise `Pyz`), never edited. Assets in a custom namespace are built
only once it is registered in `frontend/yves.settings.mts` `paths.sources` and `tsconfig.yves.json` `include`.

It carries the mechanics a strong model won't guess: the builder keys entry points by `{tier}/{name}`
across all modules (a project `index.ts` replaces the core entry; same-name components shadow each
other), module aliases come from `tsconfig.yves.json`, the project's single-file SCSS convention, what
the installed ShopUi `Component` does and doesn't have, widget registration and `{% endwidget %}`, and
the lint blind spots (ESLint may not cover project TS, `--fix` is ignored, Twig and accessibility are
unchecked).

The always-loaded companion rule `yves-frontend` (`data/rules/yves-frontend.md`, installed as
`.claude/rules/yves-frontend.md`) holds the non-negotiables; this skill holds the workflow.

## When it triggers

Any Yves `Theme/` work: "create a new molecule", "override the product item", "extend the cart item
component", "create a widget", "why isn't my style applied", "the component isn't initializing",
Yves stylelint/eslint errors.

## Files

| File | Role |
|---|---|
| [`SKILL.md`](SKILL.md) | Paths, builder resolution rules, create-a-component walkthrough, override/extend + what to ship, widgets in brief, build + verify. |
| [`references/components.md`](references/components.md) | `Component` lifecycle and gotchas, extending core TS, SCSS tokens/helpers and core overrides, design tokens, Twig resolution details, Widget PHP + view + tag. |
| [`references/validation.md`](references/validation.md) | Where checks run, Stylelint, the ESLint coverage trap with a covering config, `tsc` as advisory, what no tool checks. |

## Packaging note

This skill ships in the `spryker-ai-dev-sdk` plugin under `vendor/spryker-sdk/ai-dev/…`, which is
Composer-managed — `composer update spryker-sdk/ai-dev` may overwrite it. The durable home for edits
is the plugin's own repository (`github.com/spryker-sdk/ai-dev`).
