---
name: form-data-loading-performance
description: Use when designing or reviewing any form field populated from a database query (dropdowns, selects, choice fields). Before implementation, evaluate expected data volume and choose one of four strategies — configurable limit, async autocomplete, structurally bounded, or paginated picker.
paths: "src/**/Zed/**/Communication/Form/**/*.php,src/**/Yves/**/Form/**/*.php"
---

**Architecture rule**
Form fields populated from database queries MUST be evaluated for data volume before implementation. Never load unbounded datasets into a synchronous select.

## When this applies

Any time a form dropdown, select, or choice field is populated by a database query (e.g. merchant list, category list, product list, customer group list).

## STOP — ask the user which approach to use

Before implementing or planning, ask:

> "This feature loads [entity] records into a form dropdown. How many [entities] can exist in a typical installation? Please choose an approach:"

## Options

**Option A — Configurable limit** *(recommended for ≤ a few hundred records)*
- Fetch the first N active records using `PaginationTransfer`
- Expose the limit via `*Config::get*Limit(): int` with a safe default (e.g. 100)
- Pass config into the expander/model via constructor
- Add a test asserting the criteria uses the configured limit
- Note: "Projects with large datasets should override `get*Limit()` or switch to Option B"

**Option B — Async autocomplete** *(recommended for > a few hundred records)*
- Replace the static select with an async widget querying a Zed AJAX endpoint as the user types
- `spryker-form-select2combobox` supports async via `data-url`
- The form receives only the currently-selected item's ID+label on load — no full list
- The controller action accepts a `q` search param and returns paginated JSON results

**Option C — No limit** *(only if data is structurally bounded)*
- Valid only when the dataset is guaranteed small (e.g. fixed enum, ≤ 20 items always)
- Add a code comment stating the upper bound assumption explicitly

**Option D — Paginated table picker** *(admin flows with large catalogues)*
- Replace the inline select with a modal table with server-side pagination and search
- Uses an `AbstractListTable` subclass; a JS trigger writes the selected ID to the form field
