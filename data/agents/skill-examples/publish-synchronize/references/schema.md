# Schema Reference — Event Behavior & Storage/Search Tables

## Event Behavior (Source Table)

Add to the Propel schema of the **source** table (the table whose changes you want to publish):

```xml
<behavior name="event">
    <parameter name="spy_{entity}_all" column="*"/>
</behavior>
```

`parameter` attributes:
- `name` — unique identifier within the schema file
- `column` — column to watch; use `*` for all columns
- `value` / `operator` — optional; filter by value using any PHP comparison operator (`===`, `!=`, `<`, `>`, `<=`, `>=`, `<>`)

Examples:

```xml
<!-- Trigger only when quantity becomes 0 -->
<parameter name="spy_stock_item_quantity" column="quantity" value="0" operator="==="/>

<!-- Trigger when any column changes -->
<parameter name="spy_store_all" column="*"/>
```

After adding: `docker/sdk cli vendor/bin/console propel:install`

This auto-triggers `Entity.{table_name}.create`, `Entity.{table_name}.update`, `Entity.{table_name}.delete` on Propel entity save/delete.

---

## Storage Mirror Table (Redis)

```xml
<!-- src/Pyz/Zed/{Module}/Persistence/Propel/Schema/{module}_storage.schema.xml -->
<table name="{entity}_storage" idMethod="native" allowPkInsert="true" phpName="{Entity}Storage">
    <column name="id_{entity}_storage" type="INTEGER" required="true" primaryKey="true" autoIncrement="true"/>
    <column name="fk_{entity}"         type="INTEGER" required="true"/>
    <column name="store"               type="VARCHAR" size="128"/>
    <column name="locale"              type="VARCHAR" size="16"/>
    <column name="key"                 type="VARCHAR" size="255"/>
    <column name="data"                type="LONGVARCHAR"/>
    <column name="is_send_sync_message" type="BOOLEAN" default="false"/>
    <column name="synchronized_at"     type="TIMESTAMP"/>
    <behavior name="synchronization">
        <parameter name="resource"          value="{entity}"/>
        <parameter name="store"             value="true"/>
        <parameter name="locale"            value="true"/>
        <parameter name="key_suffix_column" value="key"/>
        <parameter name="queue_group"       value="sync.storage.{entity}"/>
    </behavior>
    <behavior name="timestampable"/>
</table>
```

## Search Mirror Table (Elasticsearch)

```xml
<table name="{entity}_search" idMethod="native" allowPkInsert="true" phpName="{Entity}Search">
    <column name="id_{entity}_search"  type="INTEGER" required="true" primaryKey="true" autoIncrement="true"/>
    <column name="fk_{entity}"         type="INTEGER" required="true"/>
    <column name="store"               type="VARCHAR" size="128"/>
    <column name="locale"              type="VARCHAR" size="16"/>
    <column name="key"                 type="VARCHAR" size="255"/>
    <column name="data"                type="LONGVARCHAR"/>
    <column name="is_send_sync_message" type="BOOLEAN" default="false"/>
    <column name="synchronized_at"     type="TIMESTAMP"/>
    <behavior name="synchronization">
        <parameter name="resource"          value="{entity}"/>
        <parameter name="store"             value="true"/>
        <parameter name="locale"            value="true"/>
        <parameter name="key_suffix_column" value="key"/>
        <parameter name="queue_group"       value="sync.search.{entity}"/>
        <parameter name="params"            value='{"type": "page"}'/>
    </behavior>
    <behavior name="timestampable"/>
</table>
```

## Synchronization Behavior Parameters

| Parameter | Description |
|---|---|
| `resource` | Storage/Search namespace — used as Redis key prefix or ES index |
| `store` | `true` if entity is store-specific |
| `locale` | `true` if entity is locale-specific |
| `key_suffix_column` | Column appended to the key to ensure uniqueness |
| `queue_group` | Queue messages are sent to after `save()` |
| `params` | Search-only — ES mapping parameters |
| `queue_pool` | Required when `store = false` — set to the synchronization pool name |

> `data` and `key` columns are added automatically by the behavior. Define them explicitly only if you need to override type or size.

## Shared Config — Event Name Constants

```php
// src/Pyz/Shared/{Module}/{Module}Config.php
namespace Pyz\Shared\{Module};

interface {Module}Config
{
    /** @var string */
    public const ENTITY_SPY_{ENTITY}_CREATE = 'Entity.spy_{entity}.create';

    /** @var string */
    public const ENTITY_SPY_{ENTITY}_UPDATE = 'Entity.spy_{entity}.update';

    /** @var string */
    public const ENTITY_SPY_{ENTITY}_DELETE = 'Entity.spy_{entity}.delete';

    /** @var string */
    public const {ENTITY}_RESOURCE_NAME = '{entity}';

    /** @var string */
    public const {ENTITY}_SYNC_STORAGE_QUEUE = 'sync.storage.{entity}';

    /** @var string */
    public const {ENTITY}_SYNC_STORAGE_ERROR_QUEUE = 'sync.storage.{entity}.error';

    /** @var string */
    public const PUBLISH_{ENTITY}_WRITE = 'Publish{Entity}Write';
}
```

## Transfer XML

```xml
<!-- src/Pyz/Shared/{Module}/Transfer/{module}.transfer.xml -->
<?xml version="1.0"?>
<transfers xmlns="spryker:transfer-01"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="spryker:transfer-01 http://static.spryker.com/transfer-01.xsd">

    <transfer name="{Entity}Storage">
        <property name="id{Entity}" type="int"/>
        <!-- Add domain-specific fields -->
    </transfer>

</transfers>
```

Generate: `docker/sdk cli vendor/bin/console transfer:generate`
