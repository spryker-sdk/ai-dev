# Lanes 1–5 — resolution detail

Read the section for a lane when you enter it. SKILL.md holds the lane order, the gate and the rules
that close a lane; this file holds how to do the work inside it.

- [Lane 1 — Dead overrides and broken classes](#lane-1--dead-overrides-and-broken-classes)
- [Lane 2 — Shadowed frontend/presentation files](#lane-2--shadowed-frontendpresentation-files)
- [Lane 3 — Plugin stacks and deprecations](#lane-3--plugin-stacks-and-deprecations)
- [Lane 4 — Config constants and transfer definitions](#lane-4--config-constants-and-transfer-definitions)
- [Lane 5 — A dependency with no compatible release](#lane-5--a-dependency-with-no-compatible-release)

## Lane 1 — Dead overrides and broken classes

Inputs: `dead-overrides-report.json`, `typed-members-report.json`, PHPStan. Fix in this order, because
each unblocks the next.

**1a. Typed members** (`check-typed-members.php`) — the console will not start until these are gone.

- `CONSTANT`: add the parent's type, e.g. `public const FACADE_X` → `public const string FACADE_X`.
- `PROPERTY`: usually the redeclaration exists only to narrow a docblock type. Newer core often
  promotes it as a typed constructor property, which cannot be redeclared narrower — so delete the
  redeclaration and narrow locally at the usage site instead:

  ```php
  /** @var \Pyz\...\ProjectConfig $config */
  $config = $this->config;
  $config->projectOnlyMethod();
  ```

**1b. Constructor arity** (PHPStan `constructor invoked with N parameters, M required`) — reflection
cannot see this. Compare the project factory's `create*()` against core's and mirror the current
argument list including order; core both appends and reorders. Prefer delegating to `parent::` and
adding only the project's extra value, so future core additions flow through by themselves.

**1c. Signature changes** on overridden methods. When core narrows a return type to a bridge that
drops methods the project needs (e.g. a session client exposing only `set`/`remove`), keep core's
return type and register the raw dependency under a project key (`PYZ_*`) and expose it via a distinctly named
accessor, leaving core's contract intact. Check whether the project also overrides the registration
of that key — if so it may be handing core the wrong type.

**1d. Dead overrides.** For each OVERRIDE_ORPHANED / CLASS_BROKEN entry:

1. Check Lane 0 first: the migration guide for that module usually names the replacement. Otherwise
   diff the vendor class between versions (GitHub compare URL / composer cache).
2. Resolution preference order:
   - a. New extension point exists → move business logic into a project plugin, wire it, delete the
     override.
   - b. Logic moved to another method → re-anchor the override against the new structure.
   - c. No seam → override the (larger) calling method and mark it with an `upgrade-debt:` docblock
     (removal condition, vendor issue); tell the developer an extension-point request to Spryker is
     warranted.
   - d. New core already covers the business requirement → delete the override.
3. Every touched behaviour needs a test proving it survived — through the Facade or Client, per
   [testing.md](testing.md). Write it before porting if none exists.

## Lane 2 — Shadowed frontend/presentation files

Input: `twig-conflicts-report.json`. Batch the mechanical part first:

```bash
php $UP/merge-shadowed-files.php --dry-run   # classify
php $UP/merge-shadowed-files.php --apply     # write the clean merges
```

It sorts every conflict into CLEAN (applied), IDENTICAL (the override carried no customisation at
all — delete it), CONFLICTED (left untouched; the merged result is written beside the file as
`<file>.merge-conflict` for review) and REMOVED. Never commit the `.merge-conflict` files — they are
gitignored, but `git add -A` still stages them.

Expect the clean-merge rate to be low where the overrides restructured components rather than tweaking
them: no textual merge can carry the vendor change across a restructured component. Each needs a design
decision; record them in a worklist instead of forcing a textual merge.

1. VENDOR_FILE_CHANGED: run the report's three-way merge command
   (`git merge-file -p <project> <baseline> <vendor-new>`). Clean merge → review and apply; conflict
   markers → resolve semantically (vendor structural changes win, project business content wins).
   **Exception — a Twig override that extends the core file explicitly**
   (`{% extends molecule('<name>', '@SprykerShop:<Module>') %}`, `'@Spryker:<Module>/…'`) is not a full
   shadow: vendor changes outside its blocks already reach the page. Do not text-merge it; check that
   every block it overrides still exists in the new vendor template and that `parent()` calls still fit.
2. Components are triplets — if a `.twig` changed, check sibling `.scss`/`.ts` entries.
3. Small customizations: convert the full override to a block-level extension that names the core
   file explicitly (`{% extends molecule('<name>', '@SprykerShop:<Module>') %}` + `{% block %}`; Zed:
   `{% extends '@Spryker:<Module>/<path>.twig' %}`) — permanently shrinks the surface. A plain
   `molecule('<name>', '<Module>')` resolves to the override itself. See `yves-atomic-frontend` /
   `backoffice-frontend`.
4. VENDOR_FILE_REMOVED → changelog/guide for the rename; re-point or drop the override.
5. NEW_VENDOR_FILE (info) → check whether the overridden parent template must now include it.
6. Zed Presentation entries (Backoffice twig, OMS mail templates) follow the same merge flow.
7. CSS framework majors: run `check-legacy-css-classes.php` before rewriting any class (matrix #55).
8. Comments carried in by a merge: a Twig, TS or JS comment copied verbatim from the vendor
   counterpart into the override counts as an added comment for `check-added-comments.php`. Drop it
   from the override; the vendor file keeps it. An `upgrade-debt:` marker on a temporary shim stays
   allowed.
9. Rebuild: `script -q /dev/null docker/sdk cli npm run yves` (and `npm run zed` / `npm run mp:build` for
   Zed/MP entries) — zero webpack errors, then render the merged pages: the Yves build does no type-checking.

## Lane 3 — Plugin stacks and deprecations

Input: `plugin-usage-report.json`.

### MISSING vendor plugins (damage)

The replacement comes from the Lane 0 guide or the old class's `@deprecated` note (read it from the
composer-cache copy of the old package, since the class is gone from the new one). Rewire in the
position/order the guide specifies.

- When a whole module was removed in favour of a feature, check whether the new version registers the
  replacement widgets globally — the per-page widget-plugin lists that held the old ones may simply be
  deleted.
- Watch for empty override methods left behind: an override returning `[]` suppresses core's own
  defaults, so delete the method rather than leaving it empty.
- Carry over the extension points the removed module's dependency provider wired — core frequently
  registers nothing by default there, so those plugins are silently lost otherwise.

### PROJECT plugins on a removed interface (damage)

Test the behaviour through the Facade/Client first → port to the new interface (granularity often
changes: one plugin may become several strategy plugins) → wire → run the test.

### The deprecation analysis (input for gate #3)

Collect every deprecated item the project still uses:

- `check-plugin-usage.php` DEPRECATED entries (wired vendor plugins) and PORTING entries on a
  deprecated (not removed) interface;
- deprecated vendor classes and methods the project extends, overrides or calls — from PHPStan when
  the project has `phpstan/phpstan-deprecation-rules` enabled, otherwise from `@deprecated` on the
  vendor parents of Pyz classes (`check-dead-overrides.php` snapshot lists them) and on the vendor
  classes Pyz factories instantiate.

For each item, read both classes (the deprecated one and the named replacement) and fill one row:

| Item | Where it is used | Replacement | Difference | Possible consequences | Recommendation |
|---|---|---|---|---|---|
| `<OldPlugin>` | `<DependencyProvider>::<method>()` position N | `<NewPlugin>` | what the replacement does differently (inputs, outputs, when it runs) | logic change / different extension point / new config or data needed / ordering in the stack / none | swap / swap + config / port / keep for now |

How to fill the consequences column:

- **Same extension-point interface, single import, single registration** → a mechanical swap; say so.
- **Replacement already imported and registered** next to the deprecated one → the change is a
  deletion; substituting would duplicate the import and double-register (matrix #45).
- **Two deprecated plugins naming the same successor** → a consolidation across two lists; the
  developer picks which list keeps it (matrix #46).
- **Replacement on a different extension point** → it moves to another dependency-provider key and
  the semantics change; treat it as porting (matrix #47). Compare the two classes'
  `Extension`/`Dependency\Plugin` interfaces to tell "same" from "different".
- **Replacement needs configuration or data** (a new config method, a new import, a new glossary key,
  a queue) → name it.
- **Order matters** (expanders, pre-/post-save hooks, validators) → say where the replacement goes in
  the stack.
- **No replacement named** → whether the behaviour is still wanted is the developer's question.

Present the table at gate #3. Apply what the developer chose, one item at a time, lint every touched
file, and run the test that covers the behaviour. Items the developer deferred go into the report as
the open deprecation list, with the table row.

## Lane 4 — Config constants and transfer definitions

**Config** (`config-constants-report.json`): for each TYPE_MISSING / CONSTANT_MISSING the migration
guide names the replacement (typical pattern: `XConstants::FOO` moves to `XConfig::getFoo()` — then
the value belongs in a Pyz config class override, not `config_default.php`). Apply, and verify with
`script -q /dev/null docker/sdk cli vendor/bin/console config:convert-check || true` plus a console
boot smoke test (`... vendor/bin/console list >/dev/null`).

**Transfer XML.** `transfer:generate` refuses to merge a definition whose `strict` attribute differs
from any other definition of the same thing, and it reports one violation per run — so scan for all
of them at once instead of iterating. Check both levels, because they are separate failures:

```bash
# property level: <property name="x" strict="true"/>
# transfer level: <transfer name="X" strict="true">
```

For each project transfer/property that core also declares, match core's `strict` value. A strict
transfer generates typed constants (`public const string FOO = 'foo'`) rather than untyped ones — the
constants still exist, so `Transfer::FOO` references keep working.

## Lane 5 — A dependency with no compatible release

When `resolve-constraints.php` reports UNRESOLVED for a third-party/eco package, first establish why,
because it changes the options:

- scan its versions on packagist for one that allows the target majors;
- if none, check what it actually uses from the blocking module. If it only touches stable APIs, a
  constraint widening upstream is enough (that is a PR to them). If it references a class the new
  major removed, no constraint change can help — it needs upstream code work.

Present that finding with the options (drop / fork / wait) and let the developer choose. Never drop a
feature unilaterally — measure its footprint first (`grep -rl` the module name across `src/` and
`config/`) and report the file count, because that is what makes the decision.

If the decision is to drop: remove the package, its plugin registrations, the project module, and
every asset/twig/JS reference. Two cases need care: keep project functionality that merely hosted a vendor hook
(e.g. a form field whose only link to the dropped feature was a `template_path` attribute), and when a
removed partial was `{% embed %}`-ed around project markup, unwrap it rather than deleting the block,
or the wrapped fields disappear with it (matrix #48).
