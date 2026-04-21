---
name: transfer-object
description: Use when creating or modifying a transfer XML file. Enforces singular names with Transfer suffix, singular attribute names for array bundles, strict="true", and reserved suffixes (Entity, Attributes, ApiAttributes, BackendApiAttributes).
paths: "src/**/*.transfer.xml"
---

**Architecture rule**
Transfer Objects MUST be defined in XML with singular names, use singular attribute for array/collection properties, and use strict flag to enforce type checking.

Critical instructions:
- Transfer names MUST be singular (Product, not Products)
- Property names SHOULD describe the data they hold (use plural for collections: items, products)
- Array/collection properties MUST include singular="ItemName" attribute specifying the singular form
- All transfer definitions MUST use strict="true" attribute on the transfer element for type safety
- "Entity" suffix is RESERVED for Propel entities only
- "Attributes", "ApiAttributes", "BackendApiAttributes" suffixes are RESERVED for Glue resources
- Transfer definitions MUST be in Shared layer for cross-layer usage
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Use singular transfer names (e.g., <transfer name="Product" strict="true">)
- Define properties with proper types (int, string, bool, float, array, AnotherTransfer)
- Use singular attribute for array/collection properties (e.g., <property name="items" type="Item[]" singular="item"/>)
- Include strict="true" attribute on transfer element for type checking enforcement
- Place in src/Pyz/Shared/[Module]/Transfer/
- Use Collection suffix for transfer objects containing arrays (e.g., ShipmentTypeCollection)

They are NOT allowed to:
- Use "Entity" suffix (reserved for Propel)
- Use "Attributes", "ApiAttributes", "BackendApiAttributes" suffixes (reserved for Glue)
- Use plural transfer names (Products is wrong, use Product or ProductCollection instead)
- Define transfers outside Shared layer
- Skip singular attribute on array/collection properties
- Omit strict="true" flag on transfer definitions

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/backend-development/data-manipulation/data-ingestion/structural-preparations/creating-using-and-extending-the-transfer-objects.html)
- Ensures consistent data contracts across all layers
- Singular names prevent confusion (transfer represents one item)
- Reserved suffixes prevent naming conflicts with auto-generated classes
- Shared layer placement enables cross-layer data exchange
- Auto-generated from XML ensures type safety
