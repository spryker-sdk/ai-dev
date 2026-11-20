# Data classes and the hand-off to project-data

Every data row (`glossary`, `data-row`, `data-import`) gets a `data_class` before the install. The
parser sets it for translations and import configuration (`feature-data`) and for rows in a demo
data section (`demo-data`). Set the others by this test:

| `data_class` | Test | Examples | What happens |
|---|---|---|---|
| `feature-data` | The feature fails, or shows raw glossary keys, with zero rows. | Glossary keys, permissions, ACL rules, type or status rows that have no Back Office create page, the entries in `data/import/*.yml`. | Always installed. |
| `project-content` | The feature needs at least one row, but the values are a project decision. | Service types, asset types, CMS slots for the feature. | Ask the developer for the values, or use the reference values as placeholders and list them for `curate-golive-data`. |
| `demo-data` | The feature works without the rows. | Sample assets, inquiries, orders. | Only when the developer chose demo data at gate #1. Otherwise `skip:demo data declined`. |

When a row could be two classes, take the stricter one (`feature-data` before `project-content`
before `demo-data`) and write the reason in `note`.

## What this skill writes, and what project-data writes

| Part | Owner |
|---|---|
| Rows in `data/import/**` CSV files | `project-data` |
| Entries in the data import configuration (`data/import/**/*.yml`) | `project-data` |
| The importer plugin registration (`DataImportDependencyProvider`) and console commands (`ConsoleDependencyProvider`) | this skill |
| A new importer module | `data-import` skill |
| The check that the rows arrived | this skill |

## The hand-off

Write one file per guide section, `$STATE/data-handoff-<section-slug>.md`, and give it to
`project-data` (`/project-data`, or `/spryker-ai-dev-sdk:project-data` on a plugin install):

```markdown
# Data hand-off: <feature> — <guide section>

Fixed row list. Add exactly these rows; change no other row.

| Row | File | data_class | Rows to add | Source | Importer |
|---|---|---|---|---|---|
| R018 | data/import/common/common/glossary.csv | feature-data | <n> keys, per project locale | guide lines <from>–<to> | data:import glossary |
| R041 | data/import/common/common/ssp_asset.csv | demo-data | <n> rows | reference data/import/common/common/ssp_asset.csv | data:import:ssp-asset |

Import config entries: <data/import/... .yml and the entry to add, or "none">.
Locales and stores: <the project's locales and stores>.
```

- `Rows to add` copies from the guide snippet or from the reference file. A `project-content` row
  carries the values the developer gave, or says `placeholder` for each one.
- `project-data` writes and applies the rows with its apply ladder. Then check the result: the rows
  are in the database and the feature shows them. Set the checklist rows to `done` only after that
  check.
- Every `feature-data` and `project-content` row is also a release-step candidate: existing
  environments do not import it on deploy ([release-steps.md](release-steps.md)).
