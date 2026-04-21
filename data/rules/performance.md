---
name: performance
description: Use when writing or reviewing performance-sensitive Spryker code — storefront reads, database writes, batch processing, or key-value storage access. Enforces architecture boundaries, batching patterns, and efficient Redis/Valkey usage.
paths: "**/*.{php,js,ts,jsx,tsx,twig}"
---

**Performance rule**
Storefront reads MUST use the key-value store or Elasticsearch. Database writes MUST be batched. Redis/Valkey reads MUST be pipelined. Never compute storefront data on request.

## Architecture

- Yves MUST read from Redis/Valkey or Elasticsearch — never call the Zed database directly at runtime
- Zed calls from Yves are only allowed for write operations or back-office use
- All cross-layer communication MUST use transfer objects — never raw arrays or entities
- OPcache and Twig cache MUST be enabled in production environments

## Batch processing (Propel)

- Never call `save()` or `delete()` inside a loop — use bulk/batch operations instead
- Use `SimpleArrayFormatter` when reading large result sets to avoid full Propel object hydration
- Process large datasets in configurable chunks (100-1000 records) to prevent memory exhaustion
- Never call `count()` inside a loop — pre-fetch counts or use iterators
- Explicitly free memory in long-running processes by unsetting large variables

## Key-value storage (Redis / Valkey)

- Use `mget` or pipelining for multi-key reads — never call `get()` in a loop
- Pre-compute and push storefront data to the key-value store via Publish & Synchronize (P&S)
- Bypassing P&S for storefront data is an anti-pattern unless real-time accuracy is explicitly required
- Store only compact, minimal data structures — avoid redundant or deeply nested values
- Set TTLs where staleness is acceptable to prevent unbounded key growth
- Use dedicated Redis databases or key namespacing per domain (sessions, storage, queue)
