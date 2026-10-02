# CMS content hygiene

The rules behind `SKILL.md` §10. Breaking one forces a higher rung of the cost ladder (§3) for a
problem the template could have solved.

- **Layout lives in the template or a section modifier — never inline in a CMS placeholder.**
  Full-bleed technique, section gaps, grid definition, aspect ratios: all template/SCSS. Inline
  `style=` or a `<style>` block inside a `placeholder.*.<locale>` cell never reaches the theme, cannot
  be reused by the next block, cannot be measured against the spec — and changing it later re-imports
  an insert-only entity, so a rung-1 problem costs rung 4.
- **A block template uses its shipped placeholders.** The block templates are
  `@CmsBlock/template/*` — `title_and_content_block`, `banner_block`, `banner_grid_column_block`,
  `section_block`, `jumbotron_block`, `navigation_block`, `product-cms-block`. Needing more content
  slots than a template offers means a **second block** (new key, new slot wiring), never a separator
  convention packed into one CSV cell.
- **`template_path` must start with `@CmsBlock/`.** A block pointed at a `@Cms/` *page* template
  renders nothing, silently — block templates are defined implicitly by `cms_block.csv`'s
  `template_name` / `template_path`, not by `cms_template.csv`.
- **Every new block key needs both wirings**: a `cms_block_store` row per store (else invisible while
  every import reads Successful) and a `cms_slot_block` row with an explicit `position`. The
  `cms-block-store` gate check covers this — run it before the import, not after the screenshot.
- **Locale vs store**: block *content* is per locale, block *visibility* is per store. Per-store
  content means a second block (`spryker-customization`).
