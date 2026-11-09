# Placement rules - vendor reference, project design

The vendor template answers three questions: **which** widget or component, **with which arguments and condition**, and **next to what**. The project answers the fourth: **how it looks**. Mixing them up produces either a widget in the wrong place or a vendor-styled island in the project's design.

## Contents

- Take from the vendor
- Take from the project
- Finding the spot
- Examples
- Traps

## Take from the vendor

- The widget name and the full tag: `{% widget 'X' args [...] only %}{% endwidget %}`. Keep `only` and the argument order; a widget's constructor takes positional arguments.
- The guard around it: `{% if widgetExists('X') %}`, `{% widget 'X' %}...{% elsewidget %}...{% endwidget %}`, a data check (`{% if data.product.idProductAbstract %}`), a config or feature flag. Copy it; a missing guard turns an optional widget into an error or an empty wrapper.
- The `use` argument blocks inside the tag (`{% block body %}` inside `{% widget %}`) when the vendor passes markup into the widget: these are part of the call, adapt their classes to the project.
- The position relative to its neighbours: "after the price", "inside the summary, before the checkout button", "in the user menu, after the account link". This is what you look for in the project file.

## Take from the project

- Variable names. The vendor passes `data.product.idProductAbstract`; the project override may define `data.product` differently or call it `data.item`. Read the project template's `{% define data = {...} %}` / `{% set %}` lines and map each argument to what exists. When an argument has no equivalent, stop and ask - do not invent a variable.
- The wrapper. Look at how the project file wraps its siblings in the same region - a BEM element (`<div class="{{ config.name }}__actions">`), a grid or spacing class, a project atom - and wrap the widget the same way. Do not copy vendor wrapper classes (`box`, `grid__col--6`, `spacing-bottom--big`) unless the project file already uses them in that region.
- Components. Where the vendor wraps the widget in a ShopUi component (a `title`, a `toggler-item`), use the component the project uses for the same job in that file, if it uses a different one.
- The block structure. Add the call inside an existing project block; when the project template is an `extends`, never add content outside a block (Twig drops it).
- Breakpoints. The vendor may place a widget in a mobile-only or desktop-only container; place it where the project shows the equivalent content on each width.

When the widget's own view (its `views/<name>/<name>.twig`) clashes with the project's design, override that view at project level: `src/{ProjectNamespace}/Yves/<WidgetModule>/Theme/default/views/<name>/<name>.twig`, extending the vendor view and changing the blocks that carry markup and classes. That keeps the page template clean and the change local to the widget.

## Finding the spot

1. Read the suggestion's `context.before` / `context.after` lines and the vendor template around the call: name the neighbour in words ("the price block", "the add-to-cart form").
2. Search the project file for that neighbour: the same block name, the same child component include (`molecule('money-price', ...)`), the same data key, the same glossary key in `| trans`.
3. Found: place the call in the same relation to it (before / after / inside), in the project's wrapper.
4. Not found, but the region exists (a summary, a menu): propose the closest position with a reason, ask, then place.
5. Region missing (the project removed or redesigned it): report it as "no equivalent region", do not create one unasked.

## Examples

**Extends, block redefined.** Vendor `product-configurator.twig` renders `ProductGroupWidget` in `{% block product_group_widget %}` inside `body`; the project extends it and redefines `body` without `parent()`, so the widget is gone. Place the vendor tag inside the project's `body`, where the project renders the variant selection (the vendor neighbour), in the wrapper the project uses there; or, when the project's `body` is a small change, propose restoring the vendor block with `{{ parent() }}` around the project's additions instead - one line, and later vendor changes keep reaching the page.

**Replaces.** Vendor `footer.twig` renders `NewsletterSubscriptionWidget` in a `.box` before `footerNavigation`; the project footer is its own markup with `project-footer__column` columns. Place the tag in a new `project-footer__column` (the project's sibling pattern) before the navigation column, not in a `.box` div.

**Navigation item.** Vendor `navigation-top.twig` renders `CompanyMenuItemWidget` between the customer menu entries; the project header uses its own `user-navigation` molecule. Find where the project renders the account entries, place the widget there, and check the widget's view uses the project's menu item classes - override the widget view if not.

## Traps

- `{% widget %}` without `{% endwidget %}` is a Twig syntax error that breaks the whole page.
- A widget placed inside a block that the project template never renders (an `extends` file with content outside blocks, a block hidden by an `{% if %}` that is always false for the test customer) looks applied and shows nothing: render it.
- The compiled Twig cache keeps the old template until `twig:cache:warmer` (or the cache directory is cleared); `yves-atomic-frontend`'s `references/storefront-fixes.md` lists where it lives.
- A widget that needs a logged-in customer, a company user, a cart or a specific product renders empty for the wrong test data; pick data that makes it show before concluding it does not work.
- Two placements of the same widget on one page (the vendor sometimes renders it in a mobile and a desktop container) both need the project's equivalents, or one of them stays empty on that width.
