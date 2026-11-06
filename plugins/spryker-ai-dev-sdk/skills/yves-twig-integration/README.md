# yves-twig-integration

Optional storefront step for Spryker feature installs and upgrades. It suggests where a feature's Yves widgets and components belong in the project's own Twig templates and applies only the placements the developer picks.

Integration guides wire a feature's backend (plugins, config, schema) and leave Yves templates to the project. The vendor templates call the feature's widgets, but a project that overrides a vendor template (page layouts, header, footer, product item) renders its own copy, and the call is gone: the widget is registered and renders nothing.

## How it works

`scripts/yves-integration-scan.php` builds the template tree the project renders - project templates, vendor templates the project does not override, and the vendor blocks an extending override keeps (an `{% embed %}` block counts as the embedded component's) - and reports each widget or component as `placed`, `missing` (with the vendor call sites the project replaced), `not_registered` or `no_reference`. Plain PHP on the host, no Spryker boot; about a second on a demo shop.

The skill turns the `missing` items into a suggestion table, waits for the developer's pick, and places each picked item at the equivalent spot of the project template: the vendor decides what and where, the project decides how it looks ([references/placement-rules.md](references/placement-rules.md)).

## Flow schema

```mermaid
flowchart TD
    A([spryker-feature-install, spryker-upgrade, or the user]) --> B["1 - Scan<br/>--package for a feature<br/>snapshot / diff for an upgrade"]
    B --> C["2 - Suggestion table<br/>missing items per project file<br/>not_registered and no_reference listed"]
    C --> D{"Developer picks"}
    D -- "none / unattended" --> R
    D -- "some or all" --> E["3 - Apply one placement<br/>vendor: widget, args, guard, neighbour<br/>project: variables, wrapper, components"]
    E --> F["4 - Verify<br/>twig cache, render desktop + mobile,<br/>re-scan reads placed"]
    F --> G{"More picked?"}
    G -- "yes" --> E
    G -- "no" --> R(["5 - Report: applied, declined, not applied"])
```

## Scripts

| Script | Use |
|---|---|
| `scripts/yves-integration-scan.php` | `scan` (default), `snapshot`, `diff`; `--package=`, `--module=`, `--json`. Writes `.ai-dev/yves-integration/report.json`. |
| `scripts/yves-integration-scan.test.php` | Zero-dependency tests on a generated fixture project: `php scripts/yves-integration-scan.test.php`. |
