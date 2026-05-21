---
paths: "src/**/Communication/Table/*.php"
---

## Two Allowed Patterns

### Pattern A — Propel-backed table (data comes from DB via Propel query)

Constructor receives: `*Query`, optional services/config, `*ConditionsTransfer`.

```
protected function prepareQuery(): *Query        ← applies ConditionsTransfer filters to query
protected function prepareData(): array          ← calls runQuery($this->prepareQuery(), $config)
protected function formatRow(array $item): array ← maps raw DB row to display columns
protected function buildLinks(array $item): string ← generates action buttons
```

`prepareData()` body:
```php
$queryResults = $this->runQuery($this->prepareQuery(), $config);
$results = [];
foreach ($queryResults as $item) {
    $results[] = $this->formatRow($item);
}
return $results;
```

### Pattern B — Facade-backed table (data comes from external API or facade)

Constructor receives: facade interface, config, criteria transfer.

`prepareData()` MUST use `setSortCollection()` (NEVER `addSort()`), set `setPagination()`, call facade, then call `setTotal()`/`setFiltered()` from `getNbResults()`:

```php
$this->criteriaTransfer
    ->setSortCollection(new ArrayObject([
        (new SortTransfer())->setField('count')->setIsAscending($isAscending),
    ]))
    ->setPagination((new PaginationTransfer())->setLimit($limit)->setOffset($offset));

$collection = $this->facade->getMyCollection($this->criteriaTransfer);
$total = $collection->getPagination()->getNbResults();
$this->setTotal($total);
$this->setFiltered($total);
```

## Critical instructions

- `BASE_URL` constant MUST have a `@uses` annotation linking to the controller `indexAction()`
- All URL path constants MUST have `@uses` annotations linking to the referenced controller action
- `COL_*` and `BUTTON_*` constants MUST be `protected const string`
- Table MUST be instantiated in the factory, not in the controller
- HTML output columns (actions, status labels, images) MUST be registered via `setRawColumns()`
- Each table MUST have its own dedicated controller — one controller per table, never share a controller between multiple tables
- The dedicated controller provides the `index` action (renders the page with the form) and the `table` action (AJAX JSON endpoint for DataTables)
- Strictly enforce these rules and never suppress even on low confidence

## They are NOT allowed to

- Use `addSort()` in Pattern B — it appends to an `ArrayObject`, accumulating sort entries across AJAX requests (every pagination/sort AJAX call runs `prepareData()` again)
- Sort or paginate results in PHP — delegate to Propel (`runQuery`) or to the facade via criteria transfer
- Instantiate the table inside the controller — factory responsibility only
- Use Pattern B (facade) for Propel data sources, or Pattern A (runQuery) for external API data sources
