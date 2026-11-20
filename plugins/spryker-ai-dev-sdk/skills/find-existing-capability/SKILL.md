---
name: find-existing-capability
description: >
  The reuse gate for Spryker feature work. Use at the start of any feature work in a Spryker
  project, BEFORE designing or building a new module: "build / add / implement X", "we need a
  system for X", "create a new module for X", scoping a PRD, "is there something in Spryker for
  X", "does Spryker already have X", "extend X", and when a customer requirement list or briefing
  has to be mapped to what Spryker already offers. Searches the Spryker capability map (one card
  per shipped capability, with its modules, feature metapackage, extension points and install
  guide) with the searchSprykerCapabilities and getSprykerCapability tools, checks what the
  project's composer.lock already has, and returns a per-capability verdict: extend it (installed),
  install the missing part, install the feature first, build only the gap (partial card), or build
  new (no relevant hit). Report-only: it never installs or edits anything; the user decides.
---

# Find Existing Capability - the reuse gate

Before anything new is designed, find out whether Spryker already ships it. Agents in Spryker projects keep building modules for things a package already provides (a project built "budgets" while `PurchasingControl` ships them). Searching `vendor/` only finds what is installed and only what you already suspect; the **capability map** covers every shipped capability, installed or not.

The map is 590 cards, one per capability: what it does, its modules, its feature metapackage (`spryker-feature/<name>`), public surface, extension points, data model, configuration, data flow, storefront surface, related cards, an "Extend, don't duplicate" line and an Install section. It describes the **latest Spryker release line**. Planned (unreleased) capabilities are not in it; `partial` cards are, and say what is missing.

**This skill reports and recommends. It never installs, edits or builds anything.** The user decides what happens next.

## When to run, when to skip

Run when the work **adds a capability** (a new module, feature, integration or behaviour area) or when the question is "does Spryker already do X". Skip when the request changes one specific, already-identified existing behaviour (fix this plugin, change that label, tweak this price rule) - that is `spryker-customization` or `spryker-bugfix` directly.

## Required tools

Match tools by **tool name** (server names vary by install; some clients namespace them as `mcp__<server>__<tool>`). Both ship with the Spryker tooling MCP server.

| Tool | Purpose |
|---|---|
| `searchSprykerCapabilities(query, limit = 10)` | Ranked capability search. `query` is space-separated domain terms, 3+ characters each (short terms such as `sso` or `oms` match whole words only). Returns hits with `slug`, `capability`, `status`, `matched`, `installStatus` (`installed`, `partially_installed`, `not_installed`, or `unknown` without `composer.lock`), `features`, `missingPackages`, `missingOptionalPackages` (API packages), `composerRequire`, `extend`, `url`, plus `source` (`stale` flag) and a `note`. |
| `getSprykerCapability(slug)` | The full card: `synonyms`, `features[]`, `modules[]` (package, path, installed, version), `composerRequire` and `sections` ("What it does", "Public surface (entry points)", "Extension points", "Data model", "Extend, don't duplicate", "Install", "Configuration", "Data flow", "Storefront surface", "Related cards"). |
| `getSprykerModuleMap` | Exact classes, facades and plugin interfaces of an installed module. |
| `getSprykerModules` | Which modules the project has, and the exact module names. |
| `searchAlgoliaDocumentation` | Official docs search, for the "no relevant hit" cross-check. |

A tool error (for example "Unknown capability", "The query needs at least one term", or the map cannot be loaded) is a failure, not an empty result: report its message and use the fallback below.

**If the capability tools are not available**, first tell the two causes apart. The server is **not registered** when no `ai-dev:mcp-server` entry exists in the project's MCP config files (`.mcp.json`, `.vscode/mcp.json`) and the harness's MCP server list does not show it. It is **registered but not connected** when an entry exists and the tools are still missing.

- **Not registered:** say so once, in one line - the project has not run `ai-dev-setup` Step 3 (it registers the server; `.mcp.json` is its project-scoped option) - and go straight to the script fallback below. Do not start the stack or ask for a reload: there is nothing to reconnect. Offer `ai-dev-setup` at the end of the report as the way to get the tools in a new session.
- **Registered but not connected:** try to start the server the way `product-requirement-document` does - the underlying server backs the local app, so `script -q /dev/null docker/sdk run` (or `up`) brings it up - then ask the user to reload the MCP connection (the harness's MCP command) and wait for confirmation. After a second failed reconnect, use the fallback.

**Fallback.** Run the bundled script from the project root. It reads `index.json` (cached 24 h), searches it and prints, per hit, the fields the tool returns (`slug`, `capability`, `status`, `matched`, `installStatus` from `composer.lock`, `features`, `missingPackages`, `missingOptionalPackages`, `composerRequire`, `url`), so the rest of this skill applies unchanged:

```bash
php <skill dir>/scripts/capability-search.php "wishlist" "shopping list" "budget spending limit" [--limit=10] [--index=<mirror url or local path>] [--lock=<composer.lock>]
```

Each quoted argument is one query (Step 1). It prints a one-line summary, then one line of JSON; the mirror can also be set with the `CAPABILITY_MAP_INDEX_URL` environment variable. Exit code 2 is a usage error, 3 means the index could not be loaded. In place of `getSprykerCapability`, fetch the hit's `url` (the card).

If PHP is not available or the script cannot load the index, use the **web fallback**:

1. Fetch `https://spryker-capability-map.s3.eu-central-1.amazonaws.com/INDEX.md` with the harness's web fetch tool, or `curl -s`. It has one entry per card with its modules, features and synonyms.
2. Search it for each term and its synonyms yourself; note the candidate slugs.
3. Fetch each candidate card from `https://spryker-capability-map.s3.eu-central-1.amazonaws.com/<slug>.md`.
4. Compare the card's `vendor/spryker/<package>` modules and its `spryker-feature/<name>` against the project's `composer.lock` by hand (the built-in file read/search tools).

Say plainly in the report that you used a fallback and that **ranking is weaker** in that mode (the script scores by title, synonyms, modules and body text, simpler than the tool; the web fallback has no scoring and no computed install status), so a miss means less.

## Step 1 - Extract the capability nouns

From the request, pull the **capability nouns** (what the business calls the thing) and add the **synonyms** a Spryker doc or module would use. Examples: "budgets" also reads "spending limit", "cost center", "purchasing control"; "wishlist" also "shopping list"; "approval" also "workflow", "order confirmation"; "SSO" also "identity provider", "OAuth", "login".

Several short queries beat one long one. Build 3-6 queries of 1-3 terms each: the main noun, each synonym cluster, and the surface when it matters (`budget`, `spending limit cost center`, `approval company`, `glue checkout`). Skip filler words and terms under 3 characters except well-known acronyms.

For a requirement list or briefing, do this **per requirement row**, not once for the whole document.

## Step 2 - Search

Call `searchSprykerCapabilities` once per query. Merge the hits by `slug` and keep, per slug, which queries hit it and what `matched` says: a hit on `capability` (the title or synonyms) is strong, a hit on `modules` is good, a hit only on `body` is weak and must be judged by reading the card. Matching is stem- and substring-based, so check that the matched term means what you meant: "loyalty points" also hits `service-point` through `point`. Drop such hits instead of reporting them.

Relay the response's `note` and check `source.stale`: when `true`, the map was served from a stale cache; say so. A search with no hits on the first query is not the end - try the synonyms before moving on.

## Step 3 - Open the top hits

Call `getSprykerCapability` for each plausible hit (usually the top 1-3 per requirement). Read, in this order: "What it does" (does it really cover the need), "Extend, don't duplicate", "Extension points", "Install", and "Related cards" (a `[[slug]]` link may be the real match; open it). Check `status`: `shipped` or `partial`.

## Step 4 - Decide per hit

One verdict per capability, driven by the card's `status` and the computed `installStatus` (read from the project's `composer.lock`; "installed" means the packages are present, not that the project wired them in - confirm the registered plugins in the project layer before promising a behaviour works today).

| Condition | Verdict | What to report and propose |
|---|---|---|
| `installed` | **Extend it** | Name the card's extension points and plugin interfaces and its "Extend, don't duplicate" line. Call `getSprykerModuleMap` for the module(s) to get the exact classes. Grep the project namespace for what the project already built on it. Hand the implementation to `spryker-customization` as configure / extend work. |
| `partially_installed` | **Install the missing part** | List `missingPackages` and say whether they matter. API packages (`*-rest-api`, `*-storefront-api`, `*-backend-api`) are listed separately in `missingOptionalPackages` and are needed only when the project exposes that API. If a package that matters is missing, propose installing it through `spryker-feature-install`. |
| `not_installed` | **Install the feature, then extend it** | Propose the feature (name, the docs install guide URL from the card's Install section, the card's `composerRequire` line) via `spryker-feature-install`, then extending it. Do not propose building from scratch. When the card lists **several features**, they are alternatives (each ships some of the card's modules, e.g. `wishlist` and `marketplace-wishlist`) and `composerRequire` names the missing module packages instead: pick the feature that matches the project (marketplace or not, B2B or B2C) from the Install section. |
| `unknown` | **Check by hand** | The project has no readable `composer.lock`. Say so, and compare the card's packages with `composer.json` and `vendor/` yourself. |
| card `status` is `partial` | **Build only the gap** | Show what the card says is missing. Reuse the shipped part (install or extend per the rows above) and scope the new build to the gap only. |
| no relevant hit | **Building new is allowed - after the cross-check** | See below. |

**A miss is not proof of absence.** Before concluding that Spryker has nothing: run `searchAlgoliaDocumentation` (or the `spryker-docs-research` skill) with the synonyms, check `getSprykerModules` for a module the project already has under a different name, and grep the project namespace. Report "no capability-map hit, no docs hit, no project module" as what you found. **Never claim "Spryker has no X" from the map alone.**

**One request, several hits.** When two cards cover parts of the request, give each its own verdict and say how they combine. When a hit only partly matches (it does half of what was asked), say which half.

**Installed near-equivalent.** When the main verdict is install or build, and an **installed** card matches the need through its title or synonyms (`shopping-list`, with the synonyms "wish list" and "save for later", for a wishlist request), report it as **"may already cover this"** next to the main verdict, with the synonym that matched and what it would not do. The user may prefer the installed alternative to a new install.

**Leftover project wiring.** Whatever the verdict, grep the project namespace (`src/<Ns>/`, the `psr-4` prefix in `composer.json`; skip `src/Generated` and `src/Orm`) for the card's modules (module names, `Spryker\...\<Module>` namespaces) and its plugin classes, and list what is already wired: plugins in dependency providers, content types, route patterns, widgets. A project can carry wiring for a feature that is not installed (the project once had it, or a demo shop removed it partly), and that changes the install scope.

## Version drift

The map describes the latest release line. The project may be older. When a capability matters and a module's installed `version` (from the card's `modules[]`) looks old for the feature, read the module's code in `vendor/spryker/<package>` or call `getSprykerModuleMap` before relying on a card detail (an extension point, a Glue resource, a config key). When the verdict is an install, say that the project is older than the map, and hand it to `spryker-feature-install` anyway: its Phase 1 finds the release the feature needs and decides whether that takes an upgrade. Do not propose `spryker-upgrade` from this skill.

## Step 5 - Report

End with a verdict table and one **Reuse decision** block per capability that matters.

```markdown
| Requirement | Capability (card) | Verdict | Next step |
|---|---|---|---|
| Per-department spending limits | Budgets and cost centers (`purchasing-control`) | not installed - install, then extend | /spryker-feature-install spryker-feature/purchasing-control |
```

```markdown
## Reuse decision

- Capability: <card title>
- Verdict: extend (installed) | install missing part | install feature then extend | build only the gap | build new (no relevant hit)
- Evidence: <status, installStatus, matched terms, the card lines that settle it; fallback or stale source if applicable>
- May already cover this: <installed card matched through its title or synonyms, and what it lacks; omit when none>
- Existing wiring: <project classes, config and routes already tied to the card's modules and plugins; "none found" when the grep is empty>
- Next step: <extension points to use / `composerRequire` line + docs guide URL / the gap to build>
- Card: https://spryker-capability-map.s3.eu-central-1.amazonaws.com/<slug>.md
```

State what you did not verify (a version-drift check you skipped, a fallback search, an API package you assumed is not needed). Then stop and let the user choose. A PRD for something that ships is framed as "configure / extend / install X", not "build X".

## What you do not do

- Do not install, `composer require`, edit config or write code. Proposals go to `spryker-feature-install` or `spryker-customization` after the user agrees.
- Do not conclude "not shipped" from the map alone, and do not skip the cross-check on a miss.
- Do not trust a card's detail over the installed code when the project's version is old.
- Do not search once with one long sentence; run several short queries.
- Do not treat a `body`-only match as a verdict - open the card and judge it.
