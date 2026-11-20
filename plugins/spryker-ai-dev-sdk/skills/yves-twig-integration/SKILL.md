---
name: yves-twig-integration
description: >
  Optional storefront step after a Spryker feature install or upgrade: suggests where the
  feature's Yves widgets and components belong in the project's own Twig templates, using the
  vendor templates as the reference for what goes where and the project's design for how it looks,
  and applies only the placements the developer picks. Integration guides wire the backend
  (plugins, config, schema) but leave Yves templates to the project, and a project that overrides a
  vendor template loses every widget call inside it, so a registered widget renders nothing. Use
  when spryker-feature-install or spryker-upgrade offers the storefront step, or when the user asks
  "place the X widget", "why is widget X not shown", "integrate the feature into our storefront",
  "which widgets does our shop not render". Never applies anything without the developer's choice.
---

# Yves Twig Integration

Integration guides stop at the backend: they register a widget plugin, but they do not place `{% widget 'X' %}` into the project's templates. The vendor templates do place it, and that only reaches the page while the project uses the vendor template. A project that overrides the template (page layouts, the header, the footer and the product item are the usual ones) renders its own copy, and the call is gone. The widget is registered, the feature is "installed", and the shopper sees nothing.

This skill closes that gap **as a suggestion**. A scanner compares the vendor templates with the templates the project actually renders and lists every place where the vendor puts a feature's widget or component and the project's override does not. The developer picks which suggestions to apply; the agent applies each one in the project's design, not the vendor's.

**Optional by design.** Projects drop widgets on purpose (no newsletter in the footer, no reviews on the product card). Present the suggestions, recommend, and apply nothing the developer did not pick. "None" is a valid answer and ends the skill.

## When it runs

- **After `spryker-feature-install`**, as its optional storefront step: scoped to the feature's Yves packages.
- **After `spryker-upgrade`**, as its optional storefront lane: the `diff` mode lists only the gaps the upgrade added (a vendor template gained a widget call, a new widget was registered), not the omissions the project lived with before.
- **Standalone**: "why is the X widget not shown", "which widgets does our storefront not render", "place the X widget". Unscoped, the scan covers every registered widget.

Not for building new widgets or restyling components (`yves-atomic-frontend`), matching a reference site (`match-reference-design`), or the backend wiring of a feature (`spryker-feature-install`).

## Locate the scanner

`<skill-dir>/scripts/yves-integration-scan.php`, where `<skill-dir>` is the path reported as `Base directory for this skill:` when this skill loaded. `$YI` below stands for that scripts directory; substitute it inline as a literal path, run from the project root on the host (plain PHP, no Spryker boot, no container). It writes `report.json` (and `snapshot.json`) to `<project>/.ai-dev/yves-integration/`, created self-gitignoring. It exits 0 with or without suggestions; 2 means a usage error, no project root, or `diff` without a snapshot.

```bash
php $YI/yves-integration-scan.php --package=spryker-shop/wishlist-widget,spryker-shop/wishlist-page   # a feature's packages
php $YI/yves-integration-scan.php --module=WishlistWidget                                             # or Yves module names
php $YI/yves-integration-scan.php                                                                     # every registered widget
php $YI/yves-integration-scan.php snapshot                                                            # before an upgrade
php $YI/yves-integration-scan.php diff                                                                # after it: new gaps only
```

What it reports per widget or component (statuses, most actionable first):

| Status | Meaning | What you do |
|---|---|---|
| `missing` | Vendor templates call it, but every call sits in a template or block the project replaced. Each vendor call site is a suggestion: vendor file and line, block chain, snippet, neighbouring lines, the project file that replaced it, and whether that file `extends` the vendor template or `replaces` it. | Offer the placement. |
| `not_registered` | Vendor templates call the widget, but no project dependency provider registers its class. | The backend step is missing: registering it in `ShopApplicationDependencyProvider::getGlobalWidgets()` comes first (`spryker-feature-install` Step 5). A placement renders nothing until then. |
| `no_reference` | No vendor template calls it. | Nothing to copy. Say where the docs place it if they do (`searchAlgoliaDocumentation` "<widget name>"), or ask the developer. |
| `placed` | A template the project renders calls it. | Nothing to do. |

With `--package`/`--module`, the scope also covers the module's components that another module's vendor template includes (`molecule('x', 'Module')`), reported as `<tier>:<name>@<Module>`. In block chains, a `~` marks a block inside an `{% embed %}`: it belongs to the embedded component, so an override of the outer template cannot drop it.

A vendor call counts as placed only when its template is reachable: vendor `views/` and `templates/` are roots (a controller or widget renders them), a component is reachable when a reachable template, or a project template, includes it. A call in a vendor component that only a replaced vendor view included is `missing` with `overrideMode: unreachable` and `projectFile` set to the project template that replaced the including one (`null` when there is none). Twig comments (`{# ... #}`) are not placements. A template nothing references, or one reached only through a variable name (`atom(type)`), cannot be judged: it still counts as before and is listed in the item's `placedUndecided`.

What it does not see: Twig functions that render content from CMS (`content_banner(...)`, CMS slots), widgets passed in variables, templates of a non-default vendor theme, single-argument includes (`atom('x')`), and templates a PHP class renders by name outside `views/` and `templates/`. Say so when it matters.

## Step 1 - Scope and scan

- From a feature install: the Yves packages of the feature - the card's `modules[]` from `getSprykerCapability` that live under `vendor/spryker-shop/` or have a `Yves/` directory, or the packages the feature metapackage required (`composer show spryker-feature/<name>`). Pass them as `--package`.
- From an upgrade: `snapshot` in Phase 0 before `composer update`, `diff` in the storefront lane after it, unscoped.
- Standalone: the widget's package or module, or unscoped.

Read `report.json`, not only the printed summary: the suggestions carry the snippets and context you need.

## Step 2 - Suggest, then wait

Group the `missing` items by project file (one page or component at a time is easier to judge) and present one table:

```markdown
| # | Widget / component | Where the vendor puts it | Project file (mode) | Recommendation |
|---|---|---|---|---|
| 1 | NewsletterSubscriptionWidget | footer, block body, inside `.box` | ShopUi/.../organisms/footer/footer.twig (replaces) | place - the project footer has a matching content column |
| 2 | DisplayProductAbstractReviewWidget | product item, block rating | ShopUi/.../molecules/product-item/product-item.twig (extends, redefines rating) | skip unless reviews are wanted on cards - the project removed the rating on purpose |
```

Base the recommendation on evidence: a project block that was emptied (`{% block rating %}{% endblock %}`) or a comment is a deliberate removal; a project copy that predates the widget, or a redesign that kept the surrounding region, is a candidate. List `not_registered` and `no_reference` items under the table with their next step.

Ask which numbers to apply (all, some, none). **Apply nothing before the answer.** In an unattended run (no developer to ask), stop here: the table is the result, and the caller's report carries it as a follow-up.

## Step 3 - Apply each picked placement in the project's design

Read [references/placement-rules.md](references/placement-rules.md) before the first edit. In short: **the vendor decides what and where, the project decides how it looks.**

1. **Read the vendor reference** in full - the snippet, its block chain and context, and the surrounding vendor template: the widget's arguments, the condition around it (`{% if widgetExists(...) %}`, a feature flag, a data check), and which neighbouring element it belongs to.
2. **Find the equivalent spot in the project file**, by meaning, never by line number:
   - `extends` and the project redefines the block: put the call inside the project's version of that block, where the vendor neighbours are in the project markup.
   - `replaces`: find the element that plays the vendor neighbour's role (the same data variable, the same child component, the same block name), and place it there.
   - No equivalent region (the page was redesigned): propose a spot with a reason and ask before writing.
3. **Write the call the vendor way, wrap it the project way**: same widget name, arguments (mapped to the variable names the project template defines) and `only`; the vendor guard kept; the vendor wrapper markup and classes not copied - use the wrapper, BEM element and spacing pattern the project file already uses for its siblings.
4. When the widget's own view does not fit the project's design, override **the widget's view** at project level (Twig-only override, project classes) following `yves-atomic-frontend` and the `yves-frontend` rule - never restyle a shared component for it, never edit `vendor/`.
5. Check the glossary keys the widget's view uses exist for every configured locale; a missing key renders as the raw key.

Edit only the project layer (`src/{ProjectNamespace}/Yves/...`). One placement at a time, then verify it before the next.

## Step 4 - Verify

- `docker/sdk console twig:cache:warmer` after a Twig change; `docker/sdk cli npm run yves` only when SCSS or TS changed.
- Render the page that uses the template (the template name usually names it: `page-layout-cart` is the cart, `product-item` appears on catalog and search pages, `footer` on every page), on desktop and mobile widths, with the data the widget needs (a logged-in customer for account widgets, a product with reviews for a review widget).
- Check that the widget renders, sits where the vendor puts it relative to its neighbours, and looks like the project (spacing, typography, buttons) rather than the vendor default; measure, do not guess (`match-reference-design` section 7 for how).
- Hand per-page checks to the `spryker-verifier` agent when the caller uses it.
- Re-run the scan with the same scope: the applied items read `placed`.

## Step 5 - Report

```markdown
## Storefront placement

- Scope: <packages or modules; upgrade diff or full scan>
- Applied: <# - widget - project file - page verified (evidence)>
- Declined by the developer: <#s>
- Not applied: <not_registered items and their backend step; no_reference items; unattended run>
- Not covered by the scan: <CMS-rendered content, custom themes, anything you judged by hand>
```

Leave the changes unstaged for review; never commit without the developer's confirmation.

## What you do not do

- Do not apply a placement the developer did not pick, and do not treat a `missing` item as a defect.
- Do not paste vendor markup or vendor classes into a project template; reuse the project's patterns.
- Do not place by line number, and do not replace a project override with a fresh copy of the vendor template.
- Do not edit `vendor/`, `src/Generated/` or build output.
- Do not place a `not_registered` widget before its registration exists.
