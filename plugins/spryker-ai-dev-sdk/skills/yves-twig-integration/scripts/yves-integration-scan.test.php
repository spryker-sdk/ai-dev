<?php

declare(strict_types=1);

/** Zero-dependency tests for yves-integration-scan.php. Run: php scripts/yves-integration-scan.test.php */

require __DIR__ . '/yves-integration-scan.php';

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

function write_file(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function widget_class(string $root, string $package, string $module, string $class, string $name): void
{
    write_file(
        "$root/vendor/$package/src/SprykerShop/Yves/$module/Widget/$class.php",
        "<?php\n\nnamespace SprykerShop\\Yves\\$module\\Widget;\n\nclass $class extends AbstractWidget\n{\n    public static function getName(): string\n    {\n        return '$name';\n    }\n}\n",
    );
}

function vendor_twig(string $root, string $package, string $module, string $rel, string $twig): void
{
    write_file("$root/vendor/$package/src/SprykerShop/Yves/$module/Theme/default/$rel", $twig);
}

function project_twig(string $root, string $module, string $rel, string $twig): void
{
    write_file("$root/src/Pyz/Yves/$module/Theme/default/$rel", $twig);
}

/**
 * @param list<string> $classes FQCNs registered in ShopApplicationDependencyProvider
 */
function register_widgets(string $root, array $classes): void
{
    $uses = implode("\n", array_map(fn (string $fqcn): string => "use $fqcn;", $classes));
    $list = implode(",\n            ", array_map(fn (string $fqcn): string => substr((string)strrchr($fqcn, '\\'), 1) . '::class', $classes));
    write_file(
        "$root/src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php",
        "<?php\n\nnamespace Pyz\\Yves\\ShopApplication;\n\n$uses\n\nclass ShopApplicationDependencyProvider\n{\n    protected function getGlobalWidgets(): array\n    {\n        return [\n            $list,\n        ];\n    }\n}\n",
    );
}

function fixture_project(): string
{
    $root = sys_get_temp_dir() . '/yves-scan-test-' . uniqid();
    write_file("$root/composer.json", '{}');

    widget_class($root, 'spryker-shop/newsletter-widget', 'NewsletterWidget', 'NewsletterSubscriptionWidget', 'NewsletterSubscriptionWidget');
    widget_class($root, 'spryker-shop/product-group-widget', 'ProductGroupWidget', 'ProductGroupWidget', 'ProductGroupWidget');
    widget_class($root, 'spryker-shop/product-review-widget', 'ProductReviewWidget', 'DisplayProductAbstractReviewWidget', 'DisplayProductAbstractReviewWidget');
    widget_class($root, 'spryker-shop/cart-note-widget', 'CartNoteWidget', 'CartNoteFormWidget', 'CartNoteFormWidget');
    widget_class($root, 'spryker-shop/orphan-widget', 'OrphanWidget', 'OrphanWidget', 'OrphanWidget');
    widget_class($root, 'spryker-shop/wishlist-widget', 'WishlistWidget', 'WishlistMenuItemWidget', 'WishlistMenuItemWidget');
    vendor_twig($root, 'spryker-shop/wishlist-widget', 'WishlistWidget', 'components/molecules/wishlist-badge/wishlist-badge.twig', "{% block body %}badge{% endblock %}\n");

    // Footer: the project replaces it and drops the newsletter widget.
    vendor_twig($root, 'spryker-shop/shop-ui', 'ShopUi', 'components/organisms/footer/footer.twig', "{% block body %}\n<div class=\"box\">\n    {% widget 'NewsletterSubscriptionWidget' only %}{% endwidget %}\n</div>\n{% endblock %}\n");
    project_twig($root, 'ShopUi', 'components/organisms/footer/footer.twig', "{% block body %}\n<footer class=\"project-footer\"></footer>\n{% endblock %}\n");

    // Product item: the project extends it; it redefines `rating` without parent(), keeps `groups`,
    // and the review widget sits inside an embed whose blocks the override cannot touch.
    vendor_twig($root, 'spryker-shop/shop-ui', 'ShopUi', 'components/molecules/product-item/product-item.twig', implode("\n", [
        '{% block body %}',
        '    {% block groups %}',
        "        {% widget 'ProductGroupWidget' args [data.idProductAbstract] only %}{% endwidget %}",
        '    {% endblock %}',
        '    {% block rating %}',
        "        {% embed molecule('rating-box', 'ShopUi') %}",
        '            {% block content %}',
        "                {% widget 'DisplayProductAbstractReviewWidget' args [data.idProductAbstract] only %}{% endwidget %}",
        '            {% endblock %}',
        '        {% endembed %}',
        '    {% endblock %}',
        "    {% include molecule('wishlist-badge', 'WishlistWidget') only %}",
        '{% endblock %}',
        '',
    ]));
    project_twig($root, 'ShopUi', 'components/molecules/product-item/product-item.twig', implode("\n", [
        "{% extends molecule('product-item', '@SprykerShop:ShopUi') %}",
        '{% block rating %}<span class="project-rating"></span>{% endblock %}',
        '',
    ]));

    // Cart page: the project keeps the block with parent(), so the note widget still renders.
    vendor_twig($root, 'spryker-shop/cart-page', 'CartPage', 'templates/page-layout-cart/page-layout-cart.twig', "{% block content %}\n    {% widget 'CartNoteFormWidget' only %}{% endwidget %}\n{% endblock %}\n");
    project_twig($root, 'CartPage', 'templates/page-layout-cart/page-layout-cart.twig', "{% extends template('page-layout-cart', '@SprykerShop:CartPage') %}\n{% block content %}\n    <div class=\"project-cart\">{{ parent() }}</div>\n{% endblock %}\n");

    // Navigation calls the wishlist widget, but no dependency provider registers it.
    vendor_twig($root, 'spryker-shop/shop-ui', 'ShopUi', 'components/organisms/navigation-top/navigation-top.twig', "{% block body %}\n    {% widget 'WishlistMenuItemWidget' only %}{% endwidget %}\n{% endblock %}\n");

    register_widgets($root, [
        'SprykerShop\\Yves\\NewsletterWidget\\Widget\\NewsletterSubscriptionWidget',
        'SprykerShop\\Yves\\ProductGroupWidget\\Widget\\ProductGroupWidget',
        'SprykerShop\\Yves\\ProductReviewWidget\\Widget\\DisplayProductAbstractReviewWidget',
        'SprykerShop\\Yves\\CartNoteWidget\\Widget\\CartNoteFormWidget',
        'SprykerShop\\Yves\\OrphanWidget\\Widget\\OrphanWidget',
    ]);

    return $root;
}

/**
 * @param array<string, mixed> $report
 *
 * @return array<string, mixed>|null
 */
function item(array $report, string $id): ?array
{
    foreach ($report['items'] as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }

    return null;
}

$root = fixture_project();
$empty = ['modules' => [], 'packages' => []];
$report = yves_scan_run($root, $empty);

echo "registered widgets\n";
$newsletter = item($report, 'NewsletterSubscriptionWidget');
check('a replacing override that drops the call is missing', $newsletter['status'] === YVES_SCAN_STATUS_MISSING);
check('the suggestion names the vendor site and the project file', $newsletter['suggestions'][0]['projectFile'] === 'src/Pyz/Yves/ShopUi/Theme/default/components/organisms/footer/footer.twig'
    && $newsletter['suggestions'][0]['overrideMode'] === 'replaces'
    && str_contains($newsletter['suggestions'][0]['snippet'], "{% widget 'NewsletterSubscriptionWidget' only %}"));
check('the suggestion carries the neighbouring vendor lines', $newsletter['suggestions'][0]['context']['before'] === ['{% block body %}', '<div class="box">']);
check('an extending override that keeps the block renders the widget', item($report, 'ProductGroupWidget')['status'] === YVES_SCAN_STATUS_PLACED);
check('a redefined block without parent() drops the widget inside it', item($report, 'DisplayProductAbstractReviewWidget')['status'] === YVES_SCAN_STATUS_MISSING);
check('blocks of an embed are not counted as overridable', item($report, 'DisplayProductAbstractReviewWidget')['suggestions'][0]['blocks'] === ['body', 'rating', '~content']);
check('a redefined block with parent() keeps the widget', item($report, 'CartNoteFormWidget')['status'] === YVES_SCAN_STATUS_PLACED);
check('a registered widget no vendor template calls has no reference', item($report, 'OrphanWidget')['status'] === YVES_SCAN_STATUS_NO_REFERENCE);
check('an unregistered widget is not a subject without a filter', item($report, 'WishlistMenuItemWidget') === null);
check('missing items are listed first', $report['items'][0]['status'] === YVES_SCAN_STATUS_MISSING);

echo "scoped to packages\n";
$scoped = yves_scan_run($root, ['modules' => [], 'packages' => ['spryker-shop/wishlist-widget']]);
check('a widget called in vendor but not registered is not_registered', item($scoped, 'WishlistMenuItemWidget')['status'] === YVES_SCAN_STATUS_NOT_REGISTERED);
check('a component another module includes is a subject, placed through a kept block', item($scoped, 'molecule:wishlist-badge@WishlistWidget')['status'] === YVES_SCAN_STATUS_PLACED);
check('only the scoped module is reported', count($scoped['items']) === 2);
$none = yves_scan_run($root, ['modules' => [], 'packages' => ['spryker-shop/not-installed']]);
check('a filter matching no installed module reports nothing', $none['items'] === [] && $none['scope']['modules'] === []);

echo "snapshot and diff\n";
$snapshot = yves_scan_gap_keys($report);
check('an unchanged tree has no new gaps', yves_scan_new_gaps(yves_scan_run($root, $empty), $snapshot)['items'] === []);
vendor_twig($root, 'spryker-shop/shop-ui', 'ShopUi', 'components/organisms/footer/footer.twig', "{% block body %}\n<div class=\"box\">\n    {% widget 'NewsletterSubscriptionWidget' only %}{% endwidget %}\n    {% widget 'OrphanWidget' only %}{% endwidget %}\n</div>\n{% endblock %}\n");
$diff = yves_scan_new_gaps(yves_scan_run($root, $empty), $snapshot);
check('a call site the update added shows up', item($diff, 'OrphanWidget')['status'] === YVES_SCAN_STATUS_MISSING);
check('gaps that existed before stay out of the diff', item($diff, 'NewsletterSubscriptionWidget') === null && count($diff['items']) === 1);

exec('rm -rf ' . escapeshellarg($root));
echo "\n$count checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
