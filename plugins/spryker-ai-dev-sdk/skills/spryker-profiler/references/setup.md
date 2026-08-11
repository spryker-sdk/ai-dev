# Enabling and configuring the Spryker WebProfiler

Read this when the profiler records nothing, when a metric you need is missing from the
output, or when someone asks how to turn profiling on for an application that has none.

Official docs (fetch these if something here disagrees with the installed version — the
project's module versions are the authority, not this file):

- [Zed](https://docs.spryker.com/docs/dg/dev/integrate-and-configure/integrate-development-tools/integrate-web-profiler-for-zed)
- [Backend Gateway](https://docs.spryker.com/docs/dg/dev/integrate-and-configure/integrate-development-tools/integrate-web-profiler-for-backend-gateway)
- [Glue](https://docs.spryker.com/docs/dg/dev/integrate-and-configure/integrate-development-tools/integrate-web-profiler-for-glue)
- [Yves widget](https://docs.spryker.com/docs/dg/dev/integrate-and-configure/integrate-development-tools/integrate-web-profiler-widget-for-yves)
- [Profiler module (traces)](https://docs.spryker.com/docs/dg/dev/integrate-and-configure/Integrate-profiler-module)

## The mental model

Three independent layers must all be present before a number appears in the output. When
data is missing, work down this list — it is almost always one of them:

1. **The switch** — `IS_WEB_PROFILER_ENABLED` must be true. One flag, all applications.
2. **The application plugin** — each application registers `WebProfilerApplicationPlugin`
   separately. Without it, that application records nothing at all.
3. **The data collectors** — each metric (SQL, Redis, Elasticsearch, Zed calls, external
   HTTP) is a separate plugin in that application's `WebProfilerDependencyProvider`.
   A missing collector is why the reader prints `collector: "absent"`.

This layering explains the reader's most confusing output: an application can be fully
profiled and still report nothing for a metric, because layer 3 is per-metric.

## 1. The switch

```php
// config/Shared/config_local.php
use Spryker\Shared\WebProfiler\WebProfilerConstants;

$config[WebProfilerConstants::IS_WEB_PROFILER_ENABLED] = true;
```

It defaults to **disabled**, which is correct — profiling every request costs time and
disk, and the toolbar exposes internals. Never enable it in production.

**`config_local.php` is gitignored.** The flag lives on one machine only, so "the
profiler works for me" does not mean it works for a colleague or on a fresh checkout.
If someone reports no data, check this first.

Optional: `WebProfilerConstants::PROFILER_CACHE_DIRECTORY` moves the storage directory.
Changing it also changes where the reader must look, so pass `--dir` to match.

## 2. Application plugins — the per-application part

Applications are wired independently, which is why Yves and Back Office write to
different directories and expose different metrics.

**Zed** (`Pyz\Zed\Application\ApplicationDependencyProvider`) has four separate plugin
stacks, and each needs the plugin added on its own — enabling Back Office does nothing
for the Backend API:

| Method | Application |
|---|---|
| `getApplicationPlugins()` | Zed |
| `getBackofficeApplicationPlugins()` | Back Office |
| `getBackendGatewayApplicationPlugins()` | Backend Gateway |
| `getBackendApiApplicationPlugins()` | Backend API |

```php
use Spryker\Zed\WebProfiler\Communication\Plugin\Application\WebProfilerApplicationPlugin;

protected function getBackofficeApplicationPlugins(): array
{
    $plugins = [/* ... */];

    if (class_exists(WebProfilerApplicationPlugin::class)) {
        $plugins[] = new WebProfilerApplicationPlugin();
    }

    return $plugins;
}
```

The `class_exists()` guard matters: `spryker/web-profiler` is a `require-dev` package, so
the class is absent in production builds. Registering it unconditionally breaks
deployment. Follow this pattern everywhere.

**Glue** uses `Spryker\Glue\WebProfiler\Plugin\Application\WebProfilerApplicationPlugin`
in `GlueApplicationDependencyProvider` / `GlueBackendApiApplicationDependencyProvider`.

**Yves** uses the separate `spryker-shop/web-profiler-widget` module rather than a
`WebProfilerApplicationPlugin`.

## 3. Data collectors — the per-metric part

Each application has its own `WebProfilerDependencyProvider` listing collectors. To make
a missing metric appear, add its plugin there and clear the cache.

| Metric | Collector plugin (namespace varies by application) |
|---|---|
| SQL queries | `WebProfilerPropelDataCollectorPlugin` |
| Redis / key-value | `WebProfilerRedisDataCollectorPlugin` |
| Elasticsearch | `WebProfilerElasticsearchDataCollectorPlugin` |
| Yves→Zed calls | `WebProfilerZedRequestDataCollectorPlugin` |
| External HTTP | `WebProfilerExternalHttpDataCollectorPlugin` |
| Time / memory | `WebProfilerTimeDataCollectorPlugin`, `WebProfilerMemoryDataCollectorPlugin` |

Collectors are wrapped in `class_exists()` for the same reason as above.

**Function-level traces** are a separate module (`spryker/profiler`): add
`WebProfilerProfilerDataCollectorPlugin` to the collector list *and*
`ProfilerRequestEventDispatcherPlugin` to that application's
`EventDispatcherDependencyProvider`. Without the dispatcher plugin the collector renders
empty — a common and confusing half-configuration.

## How this project is currently wired

Verify rather than trust this section; it drifts. `grep -rn "WebProfilerApplicationPlugin" src/`
is the fastest check.

| Application | Profiled | Storage directory | Notable collectors |
|---|---|---|---|
| Yves | yes (widget) | `data/cache/codeBucket/profiler` | Redis, Elasticsearch, ZedRequest, external HTTP — **no Propel** |
| Zed / Back Office / Backend Gateway / Backend API | yes | `data/tmp/profiler` | Propel, events, audit log — **no Redis/ZedRequest** |
| Glue (storefront + backend) | yes | `data/cache/codeBucket/profiler` | the fullest set: Propel, Redis, Elasticsearch, ZedRequest, external HTTP |
| Merchant Portal | yes — registers the plugin in its own `MerchantPortalApplicationDependencyProvider` | `data/tmp/profiler` | uses the Zed collector list |

Two consequences worth internalising:

- **Yves cannot report SQL counts.** No Propel collector is registered, and that is
  deliberate — Yves should read from Redis/Elasticsearch, never the database. So
  `queries: 0` there means *not measured*. If you genuinely need to prove Yves issues no
  SQL, count at the database instead (`SHOW GLOBAL STATUS LIKE 'Questions'` bracketing
  the request, with an idle control for background noise) rather than citing the absent
  collector.
- **Back Office cannot report Redis or Zed-request counts** for the same structural
  reason.

## Segmented SQL collection — attributing queries to a code path

Counts tell you a request runs 261 queries. They do not tell you *which part* of the
request caused them. Segments close that gap: wrap a code path and its queries are
counted separately.

[Docs: Using segmented SQL collection](https://docs.spryker.com/docs/dg/dev/integrate-and-configure/integrate-development-tools/integrate-web-profiler-for-zed#using-segmented-sql-collection)

```php
use Spryker\Shared\Propel\Logger\PropelInMemoryLogger;

PropelInMemoryLogger::startSegment('order-validation');
try {
    $this->validateOrder($orderTransfer);
} finally {
    PropelInMemoryLogger::endSegment();
}
```

The reader surfaces these under `database.segments`, keyed by segment name, each with its
own `queries` / `unique` / `duplicates`. The key is omitted entirely when nothing is
instrumented — so its absence means "no segments defined", not "no queries".

Practical notes, some of which the docs do not spell out:

- **Always pair the calls, preferably with `try`/`finally`.** `endSegment()` clears the
  current key; if an exception skips it, every subsequent query in the request is still
  attributed to your segment.
- **Segmented queries leave the main log.** `info()` routes a query into the segment
  *instead of* the general list, so segmented queries do not appear in `--verbose`
  top-repeated output. The totals stay correct because the collector sums both, but do
  not expect a segmented query to also show up in the main breakdown.
- **The logger is static and process-wide.** Segments are not nested and not
  request-scoped by themselves — a segment left open leaks into later code.
- **This is temporary instrumentation.** Add segments while hunting a bottleneck, then
  remove them. They are debugging scaffolding, not permanent code.

Use segments when a request has a high count and you need to know which of several
plausible code paths owns it — a calculator plugin stack, a form data provider, an
expander chain. That turns "this page is heavy" into "this specific step is heavy".

## External HTTP calls are opt-in

[Docs: External HTTP requests](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/external-http-requests)

Unlike SQL and Redis, external HTTP calls are **not captured automatically**. The
collector only sees calls that the calling code explicitly logs via
`ExternalHttpInMemoryLoggerTrait`:

```php
use Spryker\Shared\Http\Logger\ExternalHttpInMemoryLoggerTrait;

class MyApiClient
{
    use ExternalHttpInMemoryLoggerTrait;

    public function sendSomeData(string $url, array $data): ?array
    {
        $responseData = null;

        try {
            $response = $this->httpClient->request('POST', $url, ['json' => $data]);
            $responseData = json_decode($response->getBody()->getContents(), true);
        } finally {
            $this->getExternalHttpInMemoryLogger()->log('POST', $url, $data, $responseData);
        }

        return $responseData;
    }
}
```

**This is the reader's most misleading metric.** `external_http: 0` with the collector
present means "no *instrumented* calls" — a Guzzle client that never uses the trait is
invisible. Before concluding a slow request makes no external calls, check whether the
client instruments itself; if it does not, the profiler cannot answer the question and
you need logs, a network capture, or added instrumentation.

What to do with calls you do find, per the performance guidelines: never block a page
render or checkout on them, set explicit per-request timeouts, run independent calls
concurrently with Guzzle promises, and guard repeated failures with a circuit breaker.

## After changing any of this

```bash
docker/sdk cli console cache:empty-all
```

Plugin stacks are cached, so edits appear to do nothing until the cache is cleared. Then
reproduce a request and confirm with `--list` that a profile with a fresh `age` exists
before concluding the change failed.

## Console commands and workers are never profiled

The WebProfiler is request-scoped. Console commands, queue workers, and cron jobs record
nothing regardless of configuration — for those, use Xdebug profiling or explicit timing
instrumentation instead.
