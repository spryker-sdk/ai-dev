---
name: propel-schema
description: Use the skill to create, update, or review Propel schema definitions for Spryker modules.
---

# Propel Schema Generator

Create Propel schema definitions following Spryker conventions.

## Naming Conventions

- **Table prefix**: `spy_` for core/feature modules, `pyz_` for project-level modules
- **Primary key**: `id_{table_name}` — INTEGER, autoIncrement, required
- **Foreign key columns**: `fk_{referenced_table}` — INTEGER
- **Sequence**: `<id-method-parameter value="{table_name}_pk_seq"/>`
- **Foreign key names**: `{table}-fk_{column}`
- **Index names**: `{table}-{column}`
- **Unique constraint names**: `{table}-unique-{column}`
- **Column names**: snake_case only

## Column Types

| Use Case            | Type         |
|---------------------|--------------|
| IDs, counters       | INTEGER      |
| Short strings       | VARCHAR      |
| Long text / JSON    | LONGVARCHAR  |
| Flag                | BOOLEAN      |
| Fixed options       | ENUM         |
| UUID                | VARCHAR size="36" |
| Timestamps          | TIMESTAMP    |
| Date only           | DATE         |
| Decimal             | DECIMAL      |

## Behaviors

- `timestampable` — adds `created_at`, `updated_at`
- `uuid` — auto-generates UUID; requires `<parameter name="key_columns" value="id_{table}"/>`
- `event` — triggers sync events; requires `<parameter name="{table}_all" column="*"/>`
- `synchronization` — syncs data to Redis/Elasticsearch

## Architecture Rules

- Primary keys MUST be INTEGER with `id_{table_name}` pattern
- Every foreign key column MUST have a matching `<foreign-key>` element
- Every foreign key column SHOULD have an `<index>` element for performance
- Use `onDelete="CASCADE"` on foreign keys only when child rows should be deleted with parent
- Table and column names MUST be snake_case
- Always add `idMethod="native"` and `<id-method-parameter>` on tables with primary keys
- Only add indexes and behaviors that are explicitly requested or clearly needed

## XML Header

```xml
<?xml version="1.0"?>
<database xmlns="spryker:schema-01"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="spryker:schema-01 https://static.spryker.com/schema-01.xsd"
          name="zed"
          namespace="Orm\Zed\{Module}\Persistence"
          package="src.Orm.Zed.{Module}.Persistence">
```

---

## Example Usage

Create a Propel schema for a Company Catalog feature. A catalog belongs to a company and has a name, is_active flag, and timestamps. Use timestampable behavior. Do not add extra indexes or behaviors.

## Example Output

```xml
<?xml version="1.0"?>
<database xmlns="spryker:schema-01"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="spryker:schema-01 https://static.spryker.com/schema-01.xsd"
          name="zed"
          namespace="Orm\Zed\CompanyCatalog\Persistence"
          package="src.Orm.Zed.CompanyCatalog.Persistence">

    <table name="pyz_company_catalog" idMethod="native" allowPkInsert="true">
        <column name="id_company_catalog" required="true" type="INTEGER" autoIncrement="true" primaryKey="true"/>
        <column name="fk_company" required="true" type="INTEGER"/>
        <column name="name" required="true" type="VARCHAR" size="255"/>
        <column name="is_active" required="true" type="BOOLEAN" default="false"/>

        <behavior name="timestampable"/>

        <foreign-key name="pyz_company_catalog-fk_company" foreignTable="spy_company">
            <reference local="fk_company" foreign="id_company"/>
        </foreign-key>

        <index name="pyz_company_catalog-fk_company">
            <index-column name="fk_company"/>
        </index>

        <id-method-parameter value="pyz_company_catalog_pk_seq"/>
    </table>

</database>
```

---

## Many-to-Many Example

```xml
<!-- Junction table: catalog ↔ category -->
<table name="pyz_company_catalog_category" idMethod="native" allowPkInsert="true">
    <column name="id_company_catalog_category" required="true" type="INTEGER" autoIncrement="true" primaryKey="true"/>
    <column name="fk_company_catalog" required="true" type="INTEGER"/>
    <column name="fk_category" required="true" type="INTEGER"/>

    <foreign-key name="pyz_company_catalog_category-fk_company_catalog" foreignTable="pyz_company_catalog" onDelete="CASCADE">
        <reference local="fk_company_catalog" foreign="id_company_catalog"/>
    </foreign-key>
    <foreign-key name="pyz_company_catalog_category-fk_category" foreignTable="spy_category">
        <reference local="fk_category" foreign="id_category"/>
    </foreign-key>

    <unique name="pyz_company_catalog_category-unique-fk_company_catalog-fk_category">
        <unique-column name="fk_company_catalog"/>
        <unique-column name="fk_category"/>
    </unique>

    <index name="pyz_company_catalog_category-fk_company_catalog">
        <index-column name="fk_company_catalog"/>
    </index>
    <index name="pyz_company_catalog_category-fk_category">
        <index-column name="fk_category"/>
    </index>

    <id-method-parameter value="pyz_company_catalog_category_pk_seq"/>
</table>
```
