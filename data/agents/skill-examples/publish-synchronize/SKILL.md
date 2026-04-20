---
name: publish-synchronize
description: >
    Generate complete Spryker Publish and Synchronization (P&S) modules for Claude Code.
    Activate when the user asks to create, scaffold, or implement a Publish & Synchronize module,
  P&S publisher plugin, synchronization plugin, event listener, storage/search data writer,
  or any component of the Spryker P&S pipeline. Also triggers for: "publish and sync",
  "pub/sync", "P&S module", "storage writer", "publisher plugin", "synchronization plugin",
  "event trigger publisher", "Spryker queue worker", "data publishing module".
---

# Spryker Publish & Synchronize Module Generator

Source: https://docs.spryker.com/docs/dg/dev/backend-development/data-manipulation/data-publishing/implement-publish-and-synchronization.html

Generate all files for a complete, production-ready Spryker P&S module. Follow all steps in order.

## Architecture

```
Entity change (Propel event behavior)
  → event queue  (EventEntityTransfer messages)
    → Publisher plugin  →  writes to *_storage / *_search table
      → sync queue  (sync.storage.* / sync.search.*)
        → Synchronization plugin  →  Redis / Elasticsearch
```

## Required File Structure

```
src/Pyz/Zed/{Module}/
├── Business/
│   ├── {Module}Facade.php
│   ├── {Module}FacadeInterface.php
│   ├── {Module}BusinessFactory.php
│   └── Model/
│       └── {Entity}Writer.php
├── Communication/
│   └── Plugin/
│       ├── Publisher/
│       │   ├── {Entity}WritePublisherPlugin.php
│       │   ├── {Entity}DeletePublisherPlugin.php
│       │   └── {Entity}PublisherTriggerPlugin.php
│       └── Synchronization/
│           └── {Entity}SynchronizationDataPlugin.php
├── Persistence/
│   ├── {Module}Repository.php
│   ├── {Module}RepositoryInterface.php
│   ├── {Module}PersistenceFactory.php
│   └── Propel/Schema/
│       └── {module}_storage.schema.xml
└── {Module}DependencyProvider.php

src/Pyz/Shared/{Module}/
├── {Module}Config.php
└── Transfer/{module}.transfer.xml

src/Pyz/Client/{Module}/
├── {Module}Client.php
├── {Module}Factory.php
└── Storage/{Entity}Storage.php
```

## Steps

1. **Create module** — naming convention: suffix with `Storage` (Redis) or `Search` (Elasticsearch)
2. **Enable event behavior** on the source Propel table → see `references/schema.md`
3. **Create storage/search mirror table** with Synchronization behavior → see `references/schema.md`
4. **Create Publisher and Synchronization plugins** → see `references/plugins.md`
5. **Wire DependencyProviders and queues** → see `references/wiring.md`
6. **Validate** — update a Propel entity, check the mirror table, then Redis/ES

## Validation Commands

```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/console propel:install
docker/sdk cli vendor/bin/console publish:trigger-events -r {entity}
docker/sdk cli vendor/bin/console sync:data {entity}
docker/sdk cli vendor/bin/console queue:task:start event
docker/sdk cli vendor/bin/console queue:task:start sync.storage.{entity}
```

Check error queues in RabbitMQ UI — they are auto-created as `{queue_name}.error`.

## Key Conventions

| Rule | Detail |
|---|---|
| **Module naming** | Suffix `Storage` for Redis, `Search` for Elasticsearch |
| **Event naming** | `Entity.{table_name}.create` / `.update` / `.delete` |
| **Queue naming** | `sync.storage.*` for Redis, `sync.search.*` for Elasticsearch |
| **`hasStore()` + `queue_pool`** | If `hasStore() = true` → omit `queue_pool` in schema. If `hasStore() = false` → set `queue_pool` in schema and return pool name from `getSynchronizationQueuePoolName()` |
| **Bulk handling** | Always extract all IDs from `$eventTransfers` first, run a single query — never query per event |
| **Error handling** | Wrap per-entity logic in try/catch — log and continue, never throw (kills the queue worker) |
| **Re-publishing** | Implement `PublisherTriggerPluginInterface` to support `publish:trigger-events` command |
