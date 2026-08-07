---
name: brand-project
description: "Use when applying or changing a Spryker project's brand identity — project name, dev domain, and docker namespace at project start (a pre-boot wizard step), or a standalone repeatable rebrand any time: 'change the palette', 'apply a new brand colour', 'set the storefront logo', 'rebrand the shop'. Works pre- or post-boot."
---

# brand-project

Goal: the shop presents as the customer's brand, not Spryker's. Judgment edits on a few files. Read `.ai-dev/project-setup.md` → `project` (name, dev_domain, brand_colors). (Theming/logo failure-signature triage lives in the Known-traps catalog: `../project-starter-wizard/references/pitfalls.md`.)

**Two phases, split by the first boot:**
- **Identity** runs **pre-boot** (wizard step 3) — no `vendor/` and no DB needed.
- **Theming** runs **post-boot** — it needs `vendor/` (to mirror setting definitions in full), an initialized DB (`configuration:sync` compiles the settings map), and a running shop (to verify the rendered result). None of that exists pre-boot, so do not attempt theming at step 3; the wizard returns to it in the post-boot phase (boot-and-verify), the same way `configure-codebase` is split.

## Identity (pre-boot)

- `composer.json`: `name` → `<vendor>/<project-slug>`, `description` → the project.
- `README.md`: title → the project.
- `deploy.dev.yml`: `namespace:` → `<project-slug>_dev` (docker container/volume prefix; lower_snake). Endpoint hosts: replace the `spryker.local` base domain with `project.dev_domain` across every host (`yves.<region>.<domain>`, `backoffice.<region>.<domain>`, …). **Also sweep the kept `.github/deploy/*.yml` files** `project-ci-generator` listed under `## Required follow-ups` in `.ai-dev/project-setup.md` — they carry `*.<region>.spryker.local` hostnames for the old base domain and ci-generator does not own them. Anchored edits, preserve formatting. (Region-token part of the host is `define-stores`' job; here only the base domain + docker namespace.)

> **This namespace also names the Docker VOLUMES.** A namespace already used by a prior run *on this machine* collides on those volumes: `docker/sdk up` drops and recreates the database but **REUSES existing named volumes** → fresh DB but stale KV / search / broker read models carrying another project's stores, locales and IDs. That is a false-green generator — freshly-written rows read correct while foreign store data lurks in the read models. Pre-flight now checks `docker volume ls`; on a `<namespace>_*` hit, resolve it via **the wizard's ranked fix hierarchy** (developer removes their own stale volumes / override only the deploy `namespace:` / a different project name — never force a rename), all of which beat `clean-data`. Don't restate a single fix here — defer to that list so the developer keeps the choice.

**Logo asset** (if `project.logo` is provided): place the served file under a served static path (e.g. `frontend/static/images/brand/<logo>` → `/assets/static/images/brand/<logo>`). Split a multi-variant specimen sheet into standalone files if needed; strip caption/editor noise; substitute any non-websafe wordmark font. Only the asset is placed here — wiring the config value is a theming step (post-boot). Placing it now means the first boot builds it into `public/`. If no logo is provided, leave it; never generate one.

## Theming (post-boot)

Runs after the first boot — `vendor/` present, DB initialized. Not doable at step 3.

The demoshop ships `data/configuration/shop_ui.configuration.yml` (a Configuration Management feature file: theme colour settings, scoped global/store). The full list of colour setting keys + how to re-derive it lives in `../spryker-import-tools/references/demo-facts.md#colours`; the table below maps each to a palette role. Derive a palette from `project.brand_colors.primary` (+ optional accent) and write it into the **`default_value`** fields:

| shop_ui key | value |
|---|---|
| `background_brand_primary`, `border_brand` | primary |
| `background_brand_hover` | darken(primary, ~20%) |
| `text_brand`, `icon_brand` | darken(primary) until it hits **≥ 4.5:1 contrast on white** (WCAG AA for text) — NOT a fixed % |
| `background_brand_pressed` | darken(primary, ~40%) |
| `background_brand_subtle` | lighten(primary, ~92%) |
| `focus_ring`, `shadows_focus_color` | lighten(accent ∥ primary, ~75%) |
| `header_topbar`, `footer_newsletter` | dark neutral from the primary hue |
| others (`header_main`, `footer_main`, `header_nav`, `background_highlight`, …) | keep shipped defaults unless an accent is given |

Edit values in place (string-level per `key:`/`default_value:` pair) — never restructure the yml or drop comments. Only touch colour keys (hex, matching the file's `^#[0-9A-Fa-f]{6}$` constraint).

**Contrast, not a fixed formula, for text/icon roles.** A fixed `darken(~20%)` works for a dark primary but FAILS for light/warm hues (primary `#F0B323` amber → `darken 20%` = `#c08f1c` = 2.9:1 on white, below AA; used `#8a6714` = 5.2:1 instead). Any role rendered as text/icon on white (`text_brand`, `icon_brand`) must be darkened until it reaches ≥ 4.5:1 — and if the primary is used as a **button background with a light label**, check the label's contrast against it and flag to the developer if the brand colour can't carry white text.

**BO/MP** (same mechanism): the base clone ships no project-level BO/MP theming yml — `gui`/`zed_ui` configuration files exist only in vendor (`data/configuration/` does carry other, non-theming ymls — `ai_commerce`, `ai_vendor`, `availability_widget` — leave those alone). This step always creates project `data/configuration/gui.configuration.yml` (`bo_main_color` = primary) and `zed_ui.configuration.yml` (`spy_primary_color` = primary). Mirror the vendor tab/group/setting **in full** — every required field the schema demands (`name`, `type`, `scopes`, `enabled`, …; the parent tab also needs `icon`/`description`/`order`) — and change only the colour `default_value`. A partial definition (e.g. just `key` + `default_value`) fails `configuration:sync` schema validation and aborts the install. On a rebrand re-run, anchored-Edit the colour value in place. Sidenav colours left default.

**Custom logo wiring** (if the asset was placed in Identity). The asset alone leaves the shop on the Spryker fallback — the config value must be wired. Mechanism on this clone: `logo.twig` reads `configurationValue('theme:logos:logos:yves_logo_url')`; an empty value falls back to the shipped Spryker `build-with-logo` icon. The vendor defines `yves_logo_url` in `vendor/spryker-shop/shop-ui/resources/configuration/shop_ui.configuration.yml` as `type: file` with a `file_upload` block (storefront-media storage).

Steps:
1. Add the `yves_logo_url` setting to the project `data/configuration/shop_ui.configuration.yml`: copy the vendor setting definition **in full** — its `type: file`, the whole `file_upload` block, `scopes`, `enabled`, `secret`, `storefront`, plus the parent tab/group's required fields — and change only `default_value` to the served asset URL. A partial definition (just `key` + `default_value`) fails schema validation and aborts `configuration:sync`.
2. Give the rendered logo a size. `logo.twig`'s configured-logo branch renders a bare `<img>` with **no class**, while only the SVG-icon fallback branch is sized (`.icon--logo`) — so a correctly-configured logo can render at **0×0** with every server-side signal green. Add a `logo__image` class + explicit `width`/`height` in `logo.scss` (an `.scss` change re-applies via `docker/sdk up --assets`, per boot-and-verify's asset ladder).
3. Verify the render (boot-and-verify's logo gate — it measures the rendered box size, not just asset reachability). If the storefront header still shows the fallback, the gate performs the Back Office media upload (Configuration → Theme → Logos → `yves_logo_url`) as the alternate route. Either route ends with the project logo rendered.

Only the **horizontal lockup** has a config wiring point on a stock clone (`yves_logo_url`, and the BO/MP settings below). A **favicon** or **app-tile** variant has no project-level `<head>` template to attach to — that needs a post-boot Yves head-template override; mark it as such so it isn't silently produced-and-forgotten. If no logo was provided, the storefront keeps the Spryker fallback icon; flag that as go-live debt.

**BO/MP logos** (if a logo is provided). `theme:logos:logos:backoffice_logo_url` / `merchant_portal_logo` have no served-asset path that survives: `frontend/static/` is Yves-only, and the BO/MP asset dirs (`public/Backoffice/assets`, `public/MerchantPortal/assets`) are gitignored and regenerated by `frontend:zed:build`, so a dropped file is neither committable nor durable. Use a **base64 `data:` URI** as the setting's `default_value` (the Merchant Portal's own vendor default is an inlined base64 SVG). **Check the backdrop and match the tone:** the BO login + side navigation are dark (`bo_sidenav_color`), so the standard dark lockup is invisible there — supply a **reversed** variant; the MP chrome is light and takes the standard lockup. Known vendor gap: `.zed-logo-sm` (collapsed sidebar) reads `--zed-spryker-logo-small-url`, which no setting sets — that surface can't be branded through this mechanism; note it, don't chase it.

How it works: `default_value` in these ymls is the load-bearing mechanism — but it only takes effect once `configuration:sync` has run against an **initialized database**. Sync compiles `data/cache/configuration/settings-map.php`, which the Configuration client reads; the stock install recipe runs `configuration-sync` in the `build` section, *before* `setup:init-db`, so it processes nothing there and must run again after the DB exists. An empty `:root { }` in the rendered storefront is the tell that sync ran too early — re-run `configuration:sync` (see boot-and-verify's colour/logo gate). Populating `configuration_value.csv` is **not** a substitute: the shipped `configuration-value` importer stores a blank global-scope `scope_identifier` as `''` while the reader matches `IS NULL`, so imported global values never resolve — rely on `default_value`. A business admin can still override any value in Back Office → Configuration → Theme; "Use Default" returns to this palette. This step ships *defaults*, not seeded values.

## Verify & close

YAML still valid; hex values match the constraint. Update the `brand-project` step. (Runtime colour check is boot-and-verify.)

## Standalone rebrand (colours only — repeatable, pre- or post-boot)

Changing an already-set-up project's palette is **this same skill with only the Theming section** — skip Identity entirely (name/domain/docker/README are settled by now). Take the new `primary` (+ optional `accent`), re-derive the table, apply it to the storefront + BO/MP ymls (create-always, as above; only colour-hex `default_value`s change). Then make it visible:
- **Booted project:** `docker/sdk console configuration:sync` recompiles the settings map and the new `default_value`s reach the storefront and Back Office (verify the rendered `:root`, not a DB row) — no publish, no reset.
- **Pre-boot project:** nothing to run; the values flow at the next boot.

Business admins can still override any colour in Back Office → Configuration → Theme; "Use Default" returns to this palette. This ships **defaults**, not seeded values — so a re-run overrides only what has not been manually overridden. Repeatable any number of times.
