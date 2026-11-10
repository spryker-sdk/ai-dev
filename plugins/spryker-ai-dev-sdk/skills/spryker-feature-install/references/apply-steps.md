# Applying checklist rows

Apply each row by its decision, **in the project layer only**: the project's namespace directories
in `src/` (from `composer.json` `autoload.psr-4`; when a custom namespace comes before `Pyz` in
`KernelConstants::PROJECT_NAMESPACES`, new and overriding code goes there, and extends the `Pyz`
counterpart when one exists), plus `config/`, `data/import/` and the deploy files. Never `vendor/`,
`src/Generated/` or `src/Orm/`. The `spryker-customization` project-layer rules apply; its `rules/`
files (plugins, dependency providers, transfers) define the conventions.

## Per row kind

| Kind | How |
|---|---|
| `config` | Read the existing file first. Add the keys in the surrounding style; merge into an existing value (reconcile rule 2). No real secrets. |
| `plugin`, `route`, `widget`, `queue` | **Read the current plugin stack first.** Insert at the position the decision names (the reference order, else the guide's). Follow the project's own pattern for stack overrides: `parent::` plus a positional insert anchored on a named neighbour class, never a numeric index. A plugin the project already registers is `already-present`. |
| `plugin` with a `@deprecated` vendor class | Check every plugin class in `vendor/` for `@deprecated`. Use the named replacement; skip a deprecated plugin that has none. Record either case in the row's `note`. |
| `widget` | Register the widgets in `ShopApplicationDependencyProvider::getGlobalWidgets()`. Registration alone renders nothing in templates the project overrides: placing them is the storefront step. |
| `transfer` | Project layer; check fields with `getTransferStructureByName`. |
| `schema`, `event` | Project layer; follow the `propel-schema` conventions. |
| `navigation`, `route` | Add the entry; do not reorder existing ones. |
| `glossary` | Add the keys for every configured locale (through the `project-data` hand-off). Guides often have no glossary step, so also run `php $FI/glossary-key-check.php --module=<Yves modules of the feature>`: it lists the `'key' \| trans` keys of those modules that no `data/import/**/glossary*.csv` row defines, per locale. Add a `glossary` row with `data_class: feature-data` for each missing key; a missing key renders raw on the page. |
| `data-row`, `data-import` | Through the `project-data` hand-off ([data-classes.md](data-classes.md)). The importer plugin and console command registration stays here. |
| `deploy` | A new service or environment variable in a deploy file usually needs `docker/sdk up`, which is a rebuild: ask the developer. Never `docker/sdk reset` or `clean-data`. |
| `console` | Through `spryker-refresher` and the guide's own setup commands (below). |

When a row conflicts with something the project already built (a plugin already registered, a field
already present, a value the project set differently), stop on that row, show the conflict and let
the developer choose. Do not overwrite.

## Storefront API resources (API Platform)

When a module ships `resources/api/storefront/*.resource.yml` and the project generates API Platform
resources (`src/Generated/Api/Storefront` exists; `config/GlueStorefront` alone is no proof), the
guide's `GlueApplicationDependencyProvider` route and relationship plugin rows do not apply: set
`skip:API Platform resource`. Keep the attribute-mapper and strategy plugin stacks: the resource
mapper reads them through `#[Plugins(dependencyProviderMethod: ...)]`. Code generation creates the
resource.

## Codegen and setup after each section

Invoke `spryker-refresher` with the files the section changed. It owns the file-pattern to command
mapping. Then:

- **Glue applications are resolved, not assumed.** List `public/Glue*/index.php` and cross-check
  the `entry-point: Glue*` values in the active deploy file. Run `vendor/bin/glue <command>` for
  `public/Glue` and `GLUE_APPLICATION=GLUE_BACKEND vendor/bin/glue <command>` for
  `public/GlueBackend`. Use `GLUE_APPLICATION=GLUE_STOREFRONT` only when
  `public/GlueStorefront/index.php` exists and an entry point uses it. A recipe step for an
  application that does not exist is `skip:no such Glue application`.
- Run the section's other `console` rows (a search setup, a feature importer) after you check that
  each command exists: `docker/sdk console list`.
- Run the Yves and Zed frontend builds only when the section touched the frontend.

A failing command: show its output and hand it to the `spryker-issue-diagnoser` agent before a
retry. Never skip a failing row to look green.
