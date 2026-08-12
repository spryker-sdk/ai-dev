---
name: performance
description: Use when writing or reviewing performance-sensitive Spryker code — storefront reads, Propel queries and writes, key-value/search access, Twig templates, external HTTP calls, and batch processing. Enforces architecture boundaries, bulk-over-loop patterns, and non-blocking request handling.
paths: "**/*.{php,twig}"
---

**Performance rule**
Never do per-item work in a loop — bulk load, then map. Never block a request on the database, an external service, or uncached computation. Storefront reads come from the key-value store or search, pre-computed by Publish & Synchronize.

## Architecture

- Yves MUST read from Redis/Valkey or Elasticsearch — never query the Zed database at runtime
- Zed calls from Yves are allowed only for writes or back-office flows
- Pre-compute storefront data via P&S; bypassing it is an anti-pattern unless real-time accuracy is required
- Cross-layer communication MUST use transfer objects — never raw arrays or entities
- Keep the Quote calculator plugin stack cheap: it runs on every cart change and checkout step

## Bulk over loop (applies to every I/O)

The core anti-pattern: one query, storage read, or API call **per item**. Always collect the keys, load once, build a lookup map, then iterate.

- Database: `filterBySku_In($skus)` once — never a query inside `foreach`
- Storage: `getMulti($keys)` / pipelining — never `get()` inside `foreach`
- External APIs: one batched call — never one call per cart item
- Propel joins: `joinWithSpyX()` (hydrates in one query), not `joinSpyX()` (N+1)

## Propel writes and batch processing

- Never call `save()` or `delete()` inside a loop — use `ActiveRecordBatchProcessorTrait` (`persist()`/`remove()` + `commit()`)
- Read large result sets with `SimpleArrayFormatter` to skip full object hydration
- Process large datasets in configurable chunks (100–1000) and unset large variables between chunks
- Never call `count()` inside a loop — pre-fetch counts or use iterators
- Verify new query paths are index-backed with `EXPLAIN` before merging

## Key-value storage (Redis / Valkey)

- Budget storage operations per request; hundreds of reads per page is a bug, not a tuning problem
- Never use wildcard key scans at runtime
- Keys MUST be specific and include context (store, locale, tenant) to avoid data leakage
- Store compact, minimal structures; set TTLs where staleness is acceptable
- Namespace by domain (sessions, storage, queue)

## Search (Elasticsearch / OpenSearch)

- Never deep-paginate with `from` beyond `max_result_window` — use `search_after` with an explicit `sort`
- Request only the fields needed; do not fetch whole documents to read one attribute

## Twig

- Assign repeated nested access to a variable: `{% set customer = data.foo.bar %}` instead of re-walking `data.foo.bar.*`
- Prepare data in PHP — no lookups, filtering, or business logic in templates
- Cache expensive blocks with an explicit cache key and TTL
- Twig cache and OPcache MUST be enabled in production

## External HTTP and long-running work

- Never block a page render or checkout on a third-party call — return the view and load data from an async endpoint
- Push slow integrations (ERP, PIM sync) to a queue and reconcile via status, don't await them inline
- Always set explicit per-request timeouts; run independent calls concurrently (Guzzle promises) with a concurrency cap
- Guard repeated failures with a circuit breaker so an unresponsive service can't cascade
