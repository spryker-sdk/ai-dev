# Reconcile rules

Compare each checklist row with the reference demo shop at the same release. Write the result into
the row:

- `reference`: `same`, `differs` or `absent`, with the reference path — for example
  `differs: src/Pyz/Zed/Checkout/CheckoutDependencyProvider.php`.
- `decision`: one value from the table below.
- `note`: the reason, when the decision is not `apply-guide`.

| Decision | When |
|---|---|
| `apply-guide` | The reference is `same`, or the reference has no such file and the step is needed. |
| `apply-reference` | The reference differs and the reference is right for this project (rules 1 and 4). |
| `merge` | The project has the file and its own values: apply only what the feature adds (rules 2 and 3). |
| `already-present` | The project has it. `check-feature-installed.php --mark` sets it for complete wiring. |
| `skip:<reason>` | A scenario the developer did not choose, a plugin the reference does not wire and the developer declined, demo data declined. |

## The rules

1. **Plugin subset and order: the reference decides.** The guide lists plugins for every scenario
   and every product type. Take the plugins the reference wires for the feature, in the reference's
   order. A guide plugin that the reference does not wire → ask the developer, and record the answer
   in `note`.

   The reference is `b2b-demo-marketplace`; the project can be another type (`state.md`). A plugin
   the reference wires for a capability the project does not have — merchants, product offers,
   company accounts, business units — is `skip:<capability> not in the project`. After the composer
   step, `check-feature-installed.php` reports such plugins as `not in vendor/ (module not
   installed)`: their module is not part of the project, so they cannot be wired. Do not add a module
   to make a plugin wireable; ask the developer when the feature itself needs it.
2. **Existing config: the project decides.** Merge the feature's part into the project's value:
   add the array element, add the regex alternative, add the plugin to the stack. Never replace
   the value with the guide's. A `...` in a guide snippet always means merge.
3. **Example values take the project's values.** Store codes, locales, the `/en|/de` part of a URL
   regex, region names and hosts in a guide snippet are examples.
4. **Files the guide does not name.** `find-feature-change.php --add-rows` adds a row with
   `source: reference-only` for each file of the reference change that no row names: templates,
   DependencyProviders of neighbouring modules, OMS processes, data, deploy and recipe files. Its
   decision follows the same table. A guide data path is an example (`data/import/glossary.csv`):
   when a `reference-only` row names the real file for the same data, decide the two rows together
   and skip one of them with the reason.
5. **Integrator manifests check, never apply.** A manifest that wires a plugin no row names is a
   finding: add a row, or record why it is not needed.
6. **`already-present` is checked, not applied again.** A second copy of a plugin in a stack runs it
   twice.

## Example: a guide value that replaces the project's value

The Order Amendment guide sets the secured URL pattern to one regex:

```php
$config[CustomerConstants::CUSTOMER_SECURED_PATTERN] = '(^(/en|/de)?/order-amendment($|/))';
```

The project already protects many customer pages with that constant. A literal copy removes the
login protection from all of them. The reference adds the new path as one more alternative:

```php
$config[CustomerConstants::CUSTOMER_SECURED_PATTERN] = '(...existing alternatives...|^(/en|/de)?/order-amendment($|/))';
```

The row's decision is `merge`, the locales are the project's (rule 3), and `note` names the
constant.

## Example: plugins the reference leaves out

A guide's checkout plugin stack can include gift-card plugins. A marketplace reference does not wire
them, because the marketplace shop does not use gift cards. The plugins are not a step for a
marketplace project unless the developer says the project uses gift cards.
