# Sources

Every source goes into `$STATE/sources/`. A source that is not found is recorded in `state.md` as
not found, with the place you looked. Never write a step from memory.

| Source | Use | Required |
|---|---|---|
| Integration guide (docs page + include) | The steps: the checklist rows | yes |
| Prerequisite and linked guides | Steps of features the project does not have yet | yes, when the checklist names them |
| `spryker-feature/<x>` `composer.json` at the tag | Which modules the feature package requires | yes |
| Reference demo shop at the tag | The plugin subset and order, merged config | yes |
| Reference demo shop history | The change that brought the feature in: the files the guide does not name | yes |
| Integrator manifests of the new modules | A machine-readable wiring list | no — a cross-check |

## The integration guide

The guide on docs.spryker.com is a page that wraps one include. The steps are in the include.

1. Find the page: the capability card's Install section (`getSprykerCapability`) names it. Without
   a card, use `searchAlgoliaDocumentation` (its `github_api_url` is the markdown), the
   `spryker-docs-research` skill, or a web search for `site:docs.spryker.com install the <feature>
   feature`. Integration guides are under
   `docs/pbc/all/<pbc>/latest/.../install-and-upgrade/install-features/` (Glue API guides under
   `.../install-glue-api/`).
2. Map the page URL to its source in `spryker/spryker-docs`: drop the host, replace `.html` with
   `.md`. Fetch the raw file into `$STATE/sources/docs/<same path>`:
   ```bash
   curl -fsSL --create-dirs -o $STATE/sources/docs/<path>.md https://raw.githubusercontent.com/spryker/spryker-docs/master/<path>.md
   ```
3. The page has one line `{% include pbc/all/install-features/latest/<name>.md %}`. Fetch
   `_includes/<that path>` into `$STATE/sources/docs/_includes/<that path>` the same way.
4. Fetch `_config.yml` into `$STATE/sources/docs/_config.yml`. Its `release_version` is the value of
   `{{page.release_tag}}` in the guide: the docs describe the latest release only. When the install
   release (Phase 1) is older, run the parser with `--release <install release>`. A guide step for
   a module version the install release does not have shows as `not in vendor/` in
   `check-feature-installed.php` after the composer step: skip it with that reason.
5. Run the parser with `--docs-root $STATE/sources/docs`.

A `linked-guide` row is a page the guide sends you to for steps (translations, frontend templates,
a sub-feature). Fetch it the same way, and append it to the checklist, or skip the row with a reason.

**No guide, or a guide that assumes a base feature that has none** (a marketplace guide that assumes
the base wiring; a card that says "no install guide found"): the reference demo shop's change for the
feature is then the main source (`find-feature-change.php`, Phase 4). Without one, take the
project-layer wiring from the module code in `vendor/` and the feature's other guides. Record the
source of every row in its `note`, and say in the report that no official guide was followed.

**Identifiers.** The docs contradict themselves on exact names. Confirm every class, constant and
transfer name against the installed packages in `vendor/` (read-only) or `getSprykerModuleMap`
before you write it.

## The `spryker-feature/<x>` package

```bash
gh api repos/spryker-feature/<x>/contents/composer.json?ref=<release> --jq .content | base64 -d > $STATE/sources/feature-composer.json
```

Two package types exist:

- **Metapackage** — `composer.json` only, `"type": "metapackage"`. Its `require` is the module list.
- **Code package** — `"type": "library"`, code in the `SprykerFeature\` namespace, often with its
  own `data/import/*.csv` and translations. Some of these packages also carry module-style tags
  next to the release tags. Use the release tag.

The guide's module table also lists transitive modules. The `require` block is the authority for
what the feature package itself pulls in.

## The reference demo shop

The reference is `spryker-shop/b2b-demo-marketplace`, for every project type. It is the demo shop
that the plugin supports; for a project of another type, reconcile rule 1 says which of its
decisions do not apply.

```bash
git clone --depth 1 --branch <release> https://github.com/spryker-shop/b2b-demo-marketplace.git $STATE/sources/reference
```

The release tag is the branch. Use the default branch's content only; demo-only extras (a separate
demo namespace or branch) are not the reference for a project.

## The reference demo shop history

```bash
git clone --bare https://github.com/spryker-shop/b2b-demo-marketplace.git $STATE/sources/reference-history.git
```

A full bare clone: a shallow or blob-filtered clone cannot search the history.
`find-feature-change.php` uses it in Phase 4. It takes the first commit that added the feature
package to `composer.json`, and the merge that brought that commit into the default branch. That
merge is the reference change: every file it changed is named by a checklist row, added as a
`reference-only` row, or listed as not a step (tests, generated code, lock files).

No label or link convention ties a pull request to a feature, so a title search can find nothing
or the wrong pull request. A feature that came through a long-lived branch is found at its merge
into the default branch. A feature that the default branch never required has no reference
change: the guide rows are then the only source.

## The integrator manifests (optional)

`spryker-sdk/integrator-manifests` has one `installer-manifest.json` per module version, under
`<Vendor>/<Module>/<version>/`:

```bash
gh api repos/spryker-sdk/integrator-manifests/contents/<Vendor>/<Module> --jq '.[].name'
gh api repos/spryker-sdk/integrator-manifests/contents/<Vendor>/<Module>/<version>/installer-manifest.json --jq .content | base64 -d
```

Use a manifest to check that the wiring is complete. Never run the integrator to apply it: it
applies every manifest of every module when the project has no `integrator.lock`, and it covers
wiring only (no data import, OMS, Twig, schema or deploy recipe).
