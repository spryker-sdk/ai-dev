---
name: brand-project
description: "Use when applying or changing a Spryker project's brand identity — project name, dev domain, and docker namespace at project start (a pre-boot wizard step), or a standalone repeatable rebrand any time: 'change the palette', 'apply a new brand colour', 'set the storefront logo', 'rebrand the shop'. Works pre- or post-boot."
---

# brand-project

Goal: the shop presents as the customer's brand, not Spryker's. Judgment edits on a few files. Read `.ai-dev/project-setup.md` → `project` (name, dev_domain, brand_colors).

## Identity

- `composer.json`: `name` → `<vendor>/<project-slug>`, `description` → the project.
- `README.md`: title → the project.
- `deploy.dev.yml`: `namespace:` → `<project-slug>_dev` (docker container/volume prefix; lower_snake). Endpoint hosts: replace the `spryker.local` base domain with `project.dev_domain` across every host (`yves.<region>.<domain>`, `backoffice.<region>.<domain>`, …). Anchored edits, preserve formatting. (Region-token part of the host is `define-stores`' job; here only the base domain + docker namespace.)

> **This namespace also names the Docker VOLUMES.** A namespace already used by a prior run *on this machine* collides on those volumes: `docker/sdk up` drops and recreates the database but **REUSES existing named volumes** → fresh DB but stale KV / search / broker read models carrying another project's stores, locales and IDs. That is a false-green generator — freshly-written rows read correct while foreign store data lurks in the read models. Pre-flight now checks `docker volume ls`; on a `<namespace>_*` hit the clean fix is **changing the project name/namespace** (new volume names = clean environment, destroys nothing, old volumes stay for inspection) — it beats `clean-data`.

## Theming (verified E2/E3 — defaults flow via configuration:sync, zero DB rows)

The demoshop ships `data/configuration/shop_ui.configuration.yml` (a Configuration Management feature file: theme colour settings, scoped global/store). Derive a palette from `project.brand_colors.primary` (+ optional accent) and write it into the **`default_value`** fields:

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

**Contrast, not a fixed formula, for text/icon roles.** A fixed `darken(~20%)` works for a dark primary but FAILS for light/warm hues (real regression: primary `#F0B323` amber → `darken 20%` = `#c08f1c` = 2.9:1 on white, below AA; used `#8a6714` = 5.2:1 instead). Any role rendered as text/icon on white (`text_brand`, `icon_brand`) must be darkened until it reaches ≥ 4.5:1 — and if the primary is used as a **button background with a light label**, check the label's contrast against it and flag to the developer if the brand colour can't carry white text.

**BO/MP** (E3 — same mechanism, **create-if-absent**): the base clone ships **no BO/MP theming yml** — `gui`/`zed_ui` configuration files exist only in vendor (`data/configuration/` does carry other, non-theming ymls — `ai_commerce`, `ai_vendor`, `availability_widget` — leave those alone). Create project `data/configuration/gui.configuration.yml` (`bo_main_color` = primary) and `zed_ui.configuration.yml` (`spy_primary_color` = primary) if absent (copying only the relevant settings from the vendor originals); if they already exist (e.g. a rebrand re-run), anchored-Edit the colour value in place. Sidenav colours left default.

**Custom logo (optional — developer-supplied, never fabricated).** Read `project.logo` (a path/URL to the developer's logo file). If a logo is provided, set it — placing the asset without wiring the config value leaves the shop on the Spryker fallback. Mechanism on this clone: `logo.twig` reads `configurationValue('theme:logos:logos:yves_logo_url')`; an empty value falls back to the shipped Spryker `build-with-logo` icon. The vendor defines `yves_logo_url` in `vendor/spryker-shop/shop-ui/resources/configuration/shop_ui.configuration.yml` as `type: file` with a `file_upload` block (storefront-media storage).

Steps:
1. Place the served asset under a served static path (e.g. `frontend/static/images/brand/<logo>` → `/assets/static/images/brand/<logo>`). Split a multi-variant specimen sheet into standalone files if needed; strip caption/editor noise; substitute any non-websafe wordmark font.
2. Add the `yves_logo_url` key to the project `data/configuration/shop_ui.configuration.yml`, setting its `default_value` to that served asset URL (same `configuration:sync` mechanism the colours use). Override only `default_value`; don't re-author it from the colour-setting shape (its vendor type is `file`, not a hex string).
3. Verify the render at boot (boot-and-verify's logo gate). Because the setting's vendor type is `file`, the `default_value`-URL route may not render; if the storefront header still shows the fallback, the gate performs the Back Office media upload (Configuration → Theme → Logos → `yves_logo_url`) instead. Either route ends with the project logo rendered.

If no logo is provided, leave it — the storefront keeps the Spryker fallback icon; flag that as go-live debt. Never generate a logo.

How it works (so you set expectations, don't over-verify): at install, `configuration:sync` merges these ymls and the `default_value`s (colours **and** the logo URL) reach the storefront with **no DB rows and no publish step**. A business admin can later override any value in Back Office → Configuration → Theme; "Use Default" returns to this brand palette. So this step ships *defaults*, not seeded values.

## Verify & close

YAML still valid; hex values match the constraint. Update the `brand-project` step. (Runtime colour check is boot-and-verify.)

## Standalone rebrand (colours only — repeatable, pre- or post-boot)

Changing an already-set-up project's palette is **this same skill with only the Theming section** — skip Identity entirely (name/domain/docker/README are settled by now). Take the new `primary` (+ optional `accent`), re-derive the table, apply it to the storefront + BO/MP ymls (create-if-absent, as above; only colour-hex `default_value`s change). Then make it visible:
- **Booted project:** `docker/sdk console configuration:sync` merges the ymls and the new `default_value`s reach the storefront and Back Office — no publish, no DB rows, no reset.
- **Pre-boot project:** nothing to run; the values flow at the next boot.

Business admins can still override any colour in Back Office → Configuration → Theme; "Use Default" returns to this palette. This ships **defaults**, not seeded values — so a re-run overrides only what has not been manually overridden. Repeatable any number of times.
