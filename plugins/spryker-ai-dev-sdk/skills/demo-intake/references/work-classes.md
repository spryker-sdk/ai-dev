# Work classes — what kind of work was asked for, and who owns it

## Contents

- [The classes at a glance](#the-classes-at-a-glance)
- [1 · Setup](#1--setup)
- [2 · Data](#2--data)
- [3 · Design / look and feel](#3--design--look-and-feel)
- [4 · Customization](#4--customization)
- [5 · already works — not a class](#5--already-works--not-a-class)
- [When one request spans several classes](#when-one-request-spans-several-classes)

The authority for classifying a request. `demo-intake` §2 and `demo-prep-wizard` phase 2 both route from
this file; any other skill that has to decide "is this mine?" reads it here rather than keeping its own copy.

**Why it exists.** When the class of the work is not stated, a request is served by whichever skill is
already open. For example, a request to make the shop look like the customer's site can end up as a new
Twig organism, PHP overrides in the project namespace and an importer change — rebuilds and a rollback
for a sizing change. A briefing that lists product hotspots, quick view and a bundle builder as setup
work turns a request to add content into a request to build new features.

## The classes at a glance

| class | one line | may write | owner |
|---|---|---|---|
| **Setup** | turning a clone into *this* project | `config/**`, `deploy*.yml`, `composer.json`, `.github/**`, `src/<Ns>/` for generated scaffolding only | `project-starter-wizard` + its step skills |
| **Data** | what the shop contains | `data/import/**` | `project-data`, `translate-content`, `harvest-source-materials` |
| **Design** | how it looks | `src/**/Theme/**`, `*.twig`, `*.scss`, `frontend/**`, and CMS content **values** | `match-reference-design`, with `yves-atomic-frontend` and `brand-project` underneath |
| **Customization** | how it **behaves** | `src/<Ns>/**/*.php`, behaviour keys in `config/Shared/config_default.php` | `spryker-customization` (+ `payment-template`, `propel-schema`, `data-import`) |
| *already works* | not a class — the platform does this today | nothing | nobody; it is a demo-script line |

---

## 1 · Setup

**What it means:** turning an unmodified demoshop clone into this project — namespace, branding identity,
stores, locales and currencies, services, CI, first boot.

**File scope:** `config/**`, `deploy*.yml`, `composer.json`, `.github/**`, and `src/<Ns>/` **only** for
scaffolding a skill generated.

**Owner:** `project-starter-wizard`, delegating to `configure-codebase`, `brand-project`, `define-stores`,
`configure-services`, `project-ci-generator`, `boot-and-verify`.

**How it is phrased:**

- "turn this demoshop into our project"
- "we need one European store, EUR, English only"
- "put their name and their red on it so it stops saying Spryker"

**Misrouted when:** setup work is being done one file at a time inside a build or a design loop instead of
through the wizard, so nothing lands in `.ai-dev/project-setup.md` and the next run cannot resume. Also when
a "set up the project" ask starts producing PHP — a store, a locale and a currency are configuration, and a
new plugin to serve them means the class was read wrong.

## 2 · Data

**What it means:** what the shop contains — catalogue, categories, CMS block and banner rows, navigation,
customers and companies, prices, stock, glossary.

**File scope:** `data/import/**`, and nothing else. A data request that needs a PHP file is not a data
request; re-classify it before writing the file.

**Owner:** `project-data`. `translate-content` for the glossary and locale coverage;
`harvest-source-materials` for gathering the source material a row will carry.

**How it is phrased:**

- "add their catalogue, here is the export"
- "we need a banner on the homepage saying 'Example headline'"
- "drop the demo customers, we only want their two companies"

**Misrouted when:** the answer is a new importer, an importer plugin or a schema change. Those are
customization. A CMS **row** is data; the Twig that renders it is design; an override that changes what the
importer does with the row is customization — three classes, three owners, one CSV.

## 3 · Design / look and feel

**What it means:** how the shop looks — templates, styles, block composition, navigation layout, imagery
placement, and the **values** inside CMS content. Adding content is design's input, not a new entity.

**File scope:** `src/**/Theme/**`, `*.twig`, `*.scss`, `frontend/**`, and CMS content values. New entities
are not design's to create.

**Owner:** `match-reference-design` owns the loop; `yves-atomic-frontend` owns component mechanics, the
build and the Twig cache; `brand-project` owns palette, logo and theme tokens.

**How it is phrased:**

- "make the top image bigger"
- "the landing page should look like their site"
- "this block looks ugly, can we swap it with the second one"

**Misrouted when:** a measurement change is served as a composition change, or a composition change as a
feature. "Make the top image bigger" is a value in a stylesheet, not a Twig organism, project-namespace
PHP and an importer change. The giveaway is the diff: if a look-and-feel ask
produced a `.php` file, the class was wrong before the first edit.

## 4 · Customization

**What it means:** changing how the shop **behaves** — new or overridden PHP (plugins, expanders, dependency
providers, config classes, importers), permissions, checkout / cart / payment logic, new modules.

**File scope:** `src/<Ns>/**/*.php` and behaviour keys in `config/Shared/config_default.php`.

**Owner:** `spryker-customization`, with `payment-template`, `propel-schema` and `data-import` for their
specialisms.

**How it is phrased:**

- "guests should be able to check out without an account" — on a **business** project, and on a
  `consumer` or `both` demo on the B2B clone too. There it is **one** customization row, routed as "a small
  working version" and done by `spryker-customization` under its demo preset, following a known recipe
  ([b2b-guest-checkout.md](../../spryker-customization/references/b2b-guest-checkout.md)). Guest access
  is part of it, not setup: the installer defaults in `CustomerAccessConfig::getContentAccessByType()` are a
  PHP class in the project namespace, and setup writes only configuration. The rest is Twig overrides, the
  secured pattern and one new Client permission plugin. The wizard's routing table is its only confirmation.
- "buyers over their budget need approval before the order goes through"
- "we need a new payment method for them"

**Misrouted when:** it is reached for because a request contained the word "add". Adding products, CMS rows,
categories or copy is **data**; adding a section to a page is **design**. Customization starts only where
behaviour changes. A question about why customization files are being added at all indicates that a data or design
request was answered with behaviour.

## 5 · *already works* — not a class

The platform does this today, out of the box. It is a **demo-script line and needs no work at all**: it is
named as already working and walked at the rehearsal, never written into a task list, a needs list or a
handoff.

**How it is phrased:** "buyers order on their company account", "browse by category and filter by
size", "see their contract price when logged in".

**Misrouted when:** it appears as work. Listing something the shop already does spends a build cycle on
what is already there, and it makes hotspots, quick view and a bundle builder read as setup work in a
briefing handoff.

**Before writing anything into a class, ask whether the platform already does it.** Check in the running shop
or in `vendor/spryker*`, not from memory.

---

## When one request spans several classes

Many requests do. **Split it, name the class of each part, say which parts this run will do, and
confirm. Never silently do the adjacent one** — unrequested work in an adjacent class has to be rolled back.

1. Break the request into items, in the requester's own words. Do not merge two asks into one line and do not
   tidy the wording.
2. Give each item exactly one class and its owning skill.
3. Mark each item in or out of scope for this run.
4. Show the table and get a "yes" before the first edit — outside `demo-prep-wizard`. Inside it, the
   wizard's one routing confirmation is that "yes"; nothing is confirmed twice.
5. An item you cannot place is a `?` in the class column and, outside the wizard, a question to the
   person who asked — never the class that happens to be convenient. Inside the wizard, resolve it
   yourself by re-reading the brief.

### The routing table

One row per requested item. This is the artifact that makes the classes distinguishable.

| # | item (the requester's words) | class | owner skill | this run |
|---|---|---|---|---|
| 1 | "make the landing page look like their site" | design | `match-reference-design` | yes |
| 2 | "add a seasonal banner" | data (the CMS row) + design (where it sits) | `project-data`, then `match-reference-design` | yes — row first |
| 3 | "let guests buy" (business project) | customization | `spryker-customization` | **no** — confirm scope and quality bar first |

The table reads: the look is a design loop against a spec; the banner is a CMS block row plus a
placement, so the row is `project-data`'s and the placement is the design loop's; and guest checkout changes
how the shop behaves, which is a customization run with its own intake, its own quality bar and its own
verification — and it stays out of a styling session. Left unnamed, item 3 turns a four-line stylesheet change into
two rebuilds and a rollback. **On a `consumer` or `both` demo the
same words are one customization row with a known recipe** — guest access (the `CustomerAccessConfig`
installer defaults) and guest checkout, both done by `spryker-customization` under the demo preset, shown as
"a small working version" and confirmed with the routing table and nothing more.

### The split rule in one line

**Do the parts you named, in the classes you named, and come back for the rest.** Writing the checkout
override alongside a finished design item makes the design change hard to review and adds a behaviour
change nobody asked for.
