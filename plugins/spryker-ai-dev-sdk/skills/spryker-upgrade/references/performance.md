# Performance — what the upgrade does with each recommendation

Read this when `check-performance.php` reports a finding or a recommendation (Phase 3), before the
new-features gate (Phase 6), and before writing the "Performance recommendations" section of the
Phase 7 report.

Source: the Spryker [Yves widget performance best practices](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/yves-performance-best-practice.html)
and the [performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/performance-guidelines.html)
index.

## The rule

The Scope rule of SKILL.md decides every item: an upgrade changes versions, not architecture.

| Item | Kind | What the upgrade does |
|---|---|---|
| `spryker-shop/shop-ui` 1.103.0 removes `ProductGroupWidget` from the `groups` block of the `product-card`, `product-item` and `product-list-item` molecules | existing behaviour disappearing (damage) | restore the widget in the project templates, in Lane 2 |
| `ContentNavigationWidgetConfig::isNavigationCacheEnabled()` returning true, and `NAVIGATION_REVALIDATION_TIME_IN_SECONDS` | new capability | recommended in the report, offered at the Phase 6 gate, applied only on the developer's yes |
| The SOL-477 package set at its minimum versions | new capability | recommended in the report, offered at the Phase 6 gate, applied only on the developer's yes |
| Everything on the other guideline pages | recommendation | listed in the report; no automatic change |

`check-performance.php` exits 1 only for the `ProductGroupWidget` finding. Package versions and the
navigation cache never fail the run.

## `ProductGroupWidget` removed from the shop-ui molecules (damage)

The check fires when all of these hold:

- `spryker-shop/shop-ui` crossed 1.103.0 between the Phase 0 lock (`composer.lock.before`, or
  `--before-lock`) and the current lock;
- `spryker-shop/product-group-widget` was installed before the upgrade;
- the project uses product groups: a `data_entity: product-group` entry in a `data/import` manifest
  whose source has rows, or rows in `spy_product_abstract_group_storage` in
  `storage-search-counts-before.json`;
- no project template of the three molecules, in any project namespace, renders
  `{% widget 'ProductGroupWidget' … %}`. `ProductGroupColorWidget` and a
  `view(…, 'ProductGroupWidget')` reference do not count.

Before the upgrade the vendor templates rendered the product groups (colour swatches) on catalog,
search and product-list pages; after it they do not. Restore it in the project, never in `vendor/`:

1. For each of the three molecules, take the project template (create one that extends the vendor
   molecule when the project has none: `{% extends molecule('product-item', '@SprykerShop:ShopUi') %}`).
2. In its `groups` block, render the widget with the arguments the pre-upgrade vendor template used.
   Read them from the Phase 0 vendor copy (`twig-shadow-map.php` baseline) or the old package; typical:
   `{% widget 'ProductGroupWidget' args [data.abstractId] only %}{% endwidget %}` in `product-card` and
   `product-list-item`, `args [data.idProductAbstract]` in `product-item`.
3. Re-run `check-performance.php` (exit 0), build the assets, and have the verifier render a catalog
   page, a search result and a product list with grouped products. The swatches render as before.

## Navigation cache and the SOL-477 package set (new capabilities)

The report lists each SOL-477 package present in the lock with its minimum version:

| Package | Minimum |
|---|---|
| `spryker/navigation-storage` | 1.12.0 |
| `spryker/product-group-storage` | 1.6.0 |
| `spryker/product-storage` | 1.49.0 |
| `spryker/router` | 1.26.0 |
| `spryker/store-storage` | 1.3.0 |
| `spryker-shop/catalog-page` | 1.35.0 |
| `spryker-shop/cms-block-widget` | 2.4.0 |
| `spryker-shop/content-navigation-widget` | 1.6.0 |
| `spryker-shop/product-group-widget` | 1.12.0 |
| `spryker-shop/product-review-widget` | 1.18.0 |
| `spryker-shop/shop-application` | 1.17.0 |
| `spryker-shop/shop-ui` | 1.103.0 |

A package below its minimum after the release-group update stays where the release put it. Raising
it is a separate change offered at the Phase 6 gate; a package absent from the lock is not added.

The navigation cache needs `spryker-shop/content-navigation-widget` 1.6.0 or later. When the developer
accepts it at the gate, in its own commit:

1. `src/<Ns>/Yves/ContentNavigationWidget/ContentNavigationWidgetConfig.php` extends the
   `SprykerShop\Yves\ContentNavigationWidget\ContentNavigationWidgetConfig` and overrides
   `isNavigationCacheEnabled()` to return `true`.
2. `$config[ContentNavigationWidgetConstants::NAVIGATION_REVALIDATION_TIME_IN_SECONDS] = 3600;` in
   `config/Shared/config_default.php`, and `300` in `config/Shared/config_default-docker.dev.php`.
3. Re-run `check-performance.php`: `navigationCache.enabled` is true and the constant is listed per
   file.

Declined or deferred items go into the report with the reason.

## The other performance guideline pages (recommendations only)

The report's "Performance recommendations" section can name what a page's check finds in the
project. Nothing here is changed by the upgrade; each item is the developer's decision, outside the
upgrade's scope. Links are under `https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/`.

| Page | What it covers | What can be checked in the project |
|---|---|---|
| [Keeping dependencies updated](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/keeping-dependencies-updated.html) | module versions that carry performance fixes | the listed modules' versions in `composer.lock` against the page's minimums |
| [Monitoring](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/monitoring.html) | an active, configured APM (New Relic or OpenTelemetry) | APM packages in the lock and their monitoring plugins wired |
| [APM — New Relic based troubleshooting](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/apm-newrelic-based-troubleshooting.html) | reading APM metrics and traces | nothing static; a procedure for a running system |
| [General performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/general-performance-guidelines.html) | server-side execution time | `optimize-autoloader` / `classmap-authoritative` in `composer.json`; debug and web profiler off in production config; `RabbitMqEnv::RABBITMQ_ENABLE_RUNTIME_SETTING_UP`; `QueueConstants::QUEUE_WORKER_WAIT_LIMIT_ENABLED`; `ZedNavigationConstants::ZED_NAVIGATION_CACHE_ENABLED`; `isRoutingCacheEnabled()`; `KernelConstants::RESOLVABLE_CLASS_NAMES_CACHE_ENABLED` |
| [Architecture performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/architecture-performance-guidelines.html) | bulk operations, storage calls in loops, RPC, publish and sync | project code calling storage or Zed in loops; Redis `KEYS` usage in `src/` |
| [Frontend performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/front-end-performance-guidelines.html) | asset size and loading | the asset build output and lazy-loaded components |
| [Twig performance best practices](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/twig-performance-best-practices.html) | Twig compiler, path cache, warmup, block cache | `TwigConstants::YVES_TWIG_OPTIONS` / `ZED_TWIG_OPTIONS` cache settings; Twig cache plugins wired |
| [Bot control](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/bot-control.html) | honest and malicious bot traffic | `robots.txt` and the deploy file's auth/whitelist settings |
| [Batch processing of Propel entities](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/performance-guidelines-batch-processing-propel-entities.html) | `ActiveRecordBatchProcessorTrait` | project writers saving entities one by one in loops |
| [Database performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/database-performance-guidelines.html) | indexes, queries, data volume, ID overflow | project schema XML indexes on filtered columns; unbounded project queries |
| [Key-value storage performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/key-value-storage-performance-guidelines.html) | operations per request, admin commands at runtime | `KEYS`/`FLUSH*` in project runtime code; storage reads in loops |
| [External HTTP requests](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/external-http-requests.html) | external calls during requests | HTTP clients called from Yves/Glue request paths in `src/` |
| [Search performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/search-performance-guidelines.html) | aggregations, pagination, query structure | project query expanders and global aggregations; pagination limits in `CatalogConfig` |
| [Infrastructure and worker configuration](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/infrastructure-worker-configuration-guidelines.html) | nginx buffers, workers for many stores | buffer settings and worker setup in `deploy.*.yml` |
| [CDN and traffic management integration](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/cdn-and-traffic-management-integration.html) | compression between CDN and origin | nothing in code; the CDN configuration |
| [Split publish queues](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/split-queues-performance.html) | per-module publish queues | `PublisherConfig::PUBLISH_QUEUE` use and the queue configuration in `RabbitMqConfig` |
| [Custom code performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/custom-code-performance-guidelines.html) | caching, background jobs, Quote calculator stack | project calculator plugins in `CalculationDependencyProvider`; heavy work in controllers |
| [KV storage deduplication](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/kv-storage-deduplication.html) | duplicated URL and product abstract data in storage | `isProductAbstractStorageUnifiedEnabled()` / `isUrlLocaleMapStorageEnabled()` overrides |
| [Search index deduplication](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/search-index-deduplication.html) | product concrete documents in the search index | product concrete search plugins wired in the catalog and search dependency providers |
| Elastic computing: [RAM-aware batch processing](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/elastic-computing/ram-aware-batch-processing.html), [storage caching for primary-replica setups](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/elastic-computing/storage-caching-for-primary-replica-db-setups.html), [New Relic grouping by queue names](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/elastic-computing/new-relic-transaction-grouping-by-queue-names.html) | resource-aware processing and replica setups | database replica settings (`SPRYKER_DB_REPLICAS`) and the queue worker configuration |

Each recommendation in the report states the page, what was found, and that applying it is a
separate change outside the upgrade.
