---
name: data-import
description: Use the skill to create, update, fix, or implement data import.
---

# Data Import Generator

I need to create a data import for my new entity '{ENTITY_NAME}'.
Main AC: Create a new Spryker module named {MODULE_NAME}.

Create a new module {MODULE_NAME} in Zed Layer:
1. Create the database schema:
    - Table: {TABLE_NAME}
    - Columns: {COLUMN_DEFINITIONS}.
      Include timestampable behavior.
2. Define Transfer objects in Shared layer (transfer.xml).

Create a new module {MODULE_NAME}DataImport in Zed Layer:
1. Create `{MODULE_NAME}DataImportConfig` extending `DataImportConfig`:
    - Define `IMPORT_TYPE_{ENTITY_NAME_UPPER}` constant.
    - Implement `get{ENTITY_NAME}DataImporterConfiguration(): DataImporterConfigurationTransfer`.
2. Create `{ENTITY_NAME}DataSetInterface` with CSV column key constants.
3. Implement the import step pipeline in Business layer:
    - `{ENTITY_NAME}RequiredFieldValidatorStep` — validates required CSV fields.
    - `{ENTITY_NAME}WriterStep` — reads DataSet, upserts entity via Propel query, saves.
    - If foreign keys needed: add `{Reference}To{Id}Step` resolver steps with internal ID cache.
4. Create `{MODULE_NAME}DataImportBusinessFactory` extending `DataImportBusinessFactory`:
    - `get{ENTITY_NAME}DataImport(): DataImporterInterface` — wires CSV importer + transaction-aware step broker with all steps.
5. Create `{MODULE_NAME}DataImportFacadeInterface` and `{MODULE_NAME}DataImportFacade`:
    - Method: `import{ENTITY_NAME}s(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterReportTransfer`
6. Create `{ENTITY_NAME}DataImportPlugin` in Communication/Plugin implementing `DataImportPluginInterface`:
    - `getImportType()` returns the IMPORT_TYPE constant value.
    - `import()` delegates to facade.
7. Register the plugin in `DataImportDependencyProvider::getDataImporterPlugins()`.
8. Add the import entry to the relevant `data/import/*.yml` file:
    ```yaml
    - data_entity: {import-type}
      source: data/import/common/common/{entity_name}.csv
    ```
9. Create the sample CSV file with headers matching DataSetInterface constants.

Use Spryker best practices. Use native PHP types. No extra DocBlocks. Use current branch.
Do not run propel:install or transfer:generate — write code only.

## Scope boundary: this skill does NOT own propagation (Publish & Synchronize)

This skill builds the import path — CSV → validator/writer steps → database rows. **How those rows
reach search or key-value storage is a separate, deliberate design decision that belongs to the
feature's technical plan** (the `spryker-customization` skill's §P&S section: is propagation needed
at all, which single mechanism, what transaction boundary). Never wire a publish into an
after-import hook as a side effect of building the import — that decision was made outside any
review once, and it shipped a synchronous publish that bypassed the queue while the declared event
behavior had no subscriber.

## Verification rule: a success message is not evidence of a write

The importer's console report (*"Successful, N imported DataSets"*) is **not** acceptable evidence —
a real run printed exactly that with zero rows written. Verify every import by the **row delta**:
state the expected count, then `SELECT COUNT(*)` on the target table before/after (through the DB,
not through the importer). A write path is proven by reading the written state through a different
mechanism than the one that wrote it.

## Example Usage

I need to create a data import for my new entity 'picker'.
Main AC: Create a new Spryker module named Picker.

Create a new module Picker in Zed Layer:
1. Create the database schema:
    - Table: pyz_picker
    - Columns:
        - id_picker (PK, auto-increment integer)
        - first_name (VARCHAR 255, required)
        - last_name (VARCHAR 255, required)
        - middle_name (VARCHAR 255, optional)
        - rating (INTEGER, optional)
        - date_of_birth (DATE, optional).
          Include timestampable behavior.
2. Define PickerTransfer in Shared/Transfer/picker.transfer.xml.

Create a new module PickerDataImport in Zed Layer:
1. `PickerDataImportConfig` with `IMPORT_TYPE_PICKER = 'picker'` and `getPickerDataImporterConfiguration()`.
2. `PickerDataSetInterface` — constants: `FIRST_NAME = 'first_name'`, `LAST_NAME = 'last_name'`, etc.
3. Step pipeline:
    - `PickerRequiredFieldValidatorStep` — validates `first_name`, `last_name`.
    - `PickerWriterStep` — upserts `SpyPickerQuery` using DataSet values.
4. `PickerDataImportBusinessFactory::getPickerDataImport()` — CSV importer + transaction-aware broker with validator → writer steps.
5. Facade method: `importPickers(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterReportTransfer`
6. `PickerDataImportPlugin` implementing `DataImportPluginInterface`.
7. Register plugin in `DataImportDependencyProvider`.
8. Add to `data/import/common/minimal.yml`:
    ```yaml
    - data_entity: picker
      source: data/import/common/common/picker.csv
    ```
9. Create `data/import/common/common/picker.csv`:
    ```
    first_name,last_name,middle_name,rating,date_of_birth
    John,Doe,,5,1990-01-15
    ```

## Example Output

```
PickerDataImport/
├── src/Pyz/Zed/PickerDataImport/
│   ├── PickerDataImportConfig.php
│   ├── PickerDataImportDependencyProvider.php
│   ├── Business/
│   │   ├── DataSet/
│   │   │   └── PickerDataSetInterface.php
│   │   ├── DataImport/
│   │   │   └── PickerDataImportStep/
│   │   │       ├── PickerRequiredFieldValidatorStep.php
│   │   │       └── PickerWriterStep.php
│   │   ├── PickerDataImportBusinessFactory.php
│   │   ├── PickerDataImportFacadeInterface.php
│   │   └── PickerDataImportFacade.php
│   └── Communication/
│       └── Plugin/
│           └── PickerDataImportPlugin.php
└── data/import/common/common/
    └── picker.csv
```
