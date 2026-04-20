---
name: facade-method-signatures
description: Use when adding a new method to a Facade. Before specifying the signature, read the existing *FacadeInterface.php to determine the correct transfer type, return type, and naming conventions — each module's facade uses its own transfer objects, not the caller's.
---

**Architecture rule**
When adding a new method to a facade, read the existing `*FacadeInterface.php` first to determine the correct transfer type, return type, and naming conventions.

## Why this matters

Each module's facade boundary uses **that module's own transfer objects**, not the caller's transfers. Getting this wrong causes type mismatches across the entire call chain.

**Example:** Adding `updateMerchantProduct` to `MerchantProductFacade` → the existing methods use `MerchantProductTransfer`, NOT `ProductAbstractTransfer`. The plugin calling the facade is responsible for mapping between the two.

## What to check before specifying a facade method

Open the existing `*FacadeInterface.php` and read a similar method to determine:
- Which transfer type the facade boundary uses
- Return type conventions (does it return the transfer, void, or bool?)
- Naming pattern (create*, update*, find*, get*, delete*)
