<?php

/**
 * Legacy-CSS-class detector for frontend framework majors (Bootstrap 3 -> 5, and the like).
 *
 * When a release bumps the CSS framework, the tempting move is to take the framework's changelog,
 * grep the project for every removed class, and rewrite them. That is wrong twice over, and this
 * detector exists because both mistakes were made on a real upgrade:
 *
 *   1. Most "removed" classes are NOT removed from the product. Spryker's Back Office emits
 *      `form-group`, `has-error`, `control-label`, `btn-default`, `label label-*` and `hidden` from
 *      its OWN templates at releases that ship Bootstrap 5 — in some cases from the form theme, so
 *      every single form row gets them. The compiled `spryker-zed-gui-commons.css` styles them
 *      deliberately. Rewriting the project's copies is pure churn.
 *
 *   2. Some of them are load-bearing for VENDOR JAVASCRIPT. `gui`'s `tabs.js` selects
 *      `.has-error, .alert-danger` to mark a tab invalid, and `init.js` / `tabs.js` call
 *      `toggleClass('hidden')`. Rewriting `has-error` -> `is-invalid` or `hidden` -> `d-none` in
 *      project markup detaches it from that JS and causes exactly the breakage the migration was
 *      supposed to prevent.
 *
 * So the question this asks is never "did the framework remove this class". It is:
 *
 *     does the vendor tree, AT THE TARGET RELEASE, still emit or select this class itself?
 *
 * That is answerable from source with no browser, no compiled CSS and no built assets — which also
 * means it must not be deferred to "verify visually".
 *
 * Verdicts:
 *   KEEP           vendor still emits the class in its own templates -> core styles it; leave alone.
 *   KEEP (JS)      vendor JavaScript selects/toggles the class -> rewriting it BREAKS behaviour.
 *   PAIR           vendor emits the legacy class AND its modern equivalent on the same element
 *                  (e.g. `pull-left float-start`) -> mirror the pair, do not replace.
 *   MIGRATE        absent from vendor templates and vendor JS -> a genuine leftover, safe to convert.
 *
 * Usage:
 *   php $UP/check-legacy-css-classes.php                       # Bootstrap 3->5 default class list
 *   php $UP/check-legacy-css-classes.php --classes=a,b,c       # your own list
 *   php $UP/check-legacy-css-classes.php --layer=Yves          # default Zed
 *   php $UP/check-legacy-css-classes.php --verbose             # show the vendor evidence lines
 *
 * Exit code 1 when at least one class is MIGRATE (i.e. there is real work), 0 otherwise.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();

$options = getopt('', ['classes:', 'layer:', 'verbose', 'help']) ?: [];

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php check-legacy-css-classes.php [--classes=a,b] [--layer=Zed] [--verbose]\n");
    exit(0);
}

$layer = $options['layer'] ?? 'Zed';
$verbose = isset($options['verbose']);

/**
 * Bootstrap 3 -> 5 defaults. `modern` is only a hint for the PAIR check and the report; this script
 * never rewrites anything.
 */
$defaultClasses = [
    'col-xs-' => 'col-',
    'form-group' => 'mb-3',
    'has-error' => 'is-invalid',
    'control-label' => 'form-label',
    'btn-default' => 'btn-secondary',
    'btn-block' => 'd-grid wrapper',
    'pull-left' => 'float-start',
    'pull-right' => 'float-end',
    'label label-' => 'badge bg-',
    'hidden' => 'd-none',
    'img-responsive' => 'img-fluid',
    'text-help' => 'form-text',
];

$classes = $defaultClasses;
if (isset($options['classes'])) {
    $classes = [];
    foreach (explode(',', (string)$options['classes']) as $c) {
        $c = trim($c);
        if ($c !== '') {
            $classes[$c] = null;
        }
    }
}

/**
 * Collect files once, then match in PHP — far fewer process spawns than grepping per class, and it
 * keeps the vendor scan to a single directory walk.
 */
function spryker_upgrade_collect_files(string $dir, array $extensions, ?string $mustContainSegment = null): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
            continue;
        }
        $path = $file->getPathname();
        if ($mustContainSegment !== null && !str_contains($path, $mustContainSegment)) {
            continue;
        }
        $found[] = $path;
    }

    return $found;
}

$projectTemplates = spryker_upgrade_collect_files($root . '/src/Pyz/' . $layer, ['twig']);

// Vendor templates for this layer, across every Spryker vendor namespace.
$vendorTemplates = [];
$vendorJs = [];
foreach (glob($root . '/vendor/{spryker,spryker-shop,spryker-eco,spryker-sdk}/*', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $package) {
    $vendorTemplates = array_merge(
        $vendorTemplates,
        spryker_upgrade_collect_files($package . '/src', ['twig'], '/' . $layer . '/'),
    );
    $vendorJs = array_merge(
        $vendorJs,
        spryker_upgrade_collect_files($package . '/assets/' . $layer, ['js', 'ts']),
    );
}

if ($projectTemplates === []) {
    fwrite(STDOUT, "No project templates found under src/Pyz/{$layer}/ — nothing to check.\n");
    exit(0);
}

if ($vendorTemplates === []) {
    fwrite(STDERR, "WARNING: no vendor {$layer} templates found. Run `composer install` first —\n");
    fwrite(STDERR, "without a complete vendor tree every class would look like a MIGRATE, which is\n");
    fwrite(STDERR, "the dangerous direction to be wrong in.\n");
    exit(2);
}

/**
 * A class token inside a class="..." attribute. Word boundaries matter: `hidden` must not match
 * `hidden-xs`, and `col-xs-` is a prefix so it is matched as one.
 */
function spryker_upgrade_class_pattern(string $class): string
{
    $quoted = preg_quote($class, '#');

    // Prefix-style tokens (trailing dash) match the rest of the token; exact tokens get a boundary.
    if (str_ends_with($class, '-')) {
        return '#class\s*=\s*["\'][^"\']*\b' . $quoted . '#i';
    }

    return '#class\s*=\s*["\'][^"\']*\b' . $quoted . '\b#i';
}

function spryker_upgrade_scan(array $files, string $pattern, int $limit = 3): array
{
    $hits = [];
    $count = 0;

    foreach ($files as $file) {
        $contents = @file_get_contents($file);
        if ($contents === false || !preg_match($pattern, $contents)) {
            continue;
        }

        $count++;
        if (count($hits) < $limit) {
            foreach (explode("\n", $contents) as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = [$file, $i + 1, trim($line)];

                    break;
                }
            }
        }
    }

    return [$count, $hits];
}

$rows = [];
$migrateCount = 0;

foreach ($classes as $class => $modern) {
    $pattern = spryker_upgrade_class_pattern($class);

    [$projectCount] = spryker_upgrade_scan($projectTemplates, $pattern, 0);
    if ($projectCount === 0) {
        continue; // the project does not use it — not our problem
    }

    [$vendorCount, $vendorHits] = spryker_upgrade_scan($vendorTemplates, $pattern);

    // Vendor JS: selectors and class toggles are plain string occurrences, not class="" attributes.
    $jsPattern = '#[\'"\.]' . preg_quote($class, '#') . ($class === rtrim($class, '-') ? '\b' : '') . '#i';
    [$jsCount, $jsHits] = spryker_upgrade_scan($vendorJs, $jsPattern);

    // PAIR: vendor puts the legacy and the modern class on the same element.
    $paired = false;
    if ($modern !== null && $vendorCount > 0 && !str_contains($modern, ' ')) {
        $pairPattern = '#class\s*=\s*["\'][^"\']*\b'
            . preg_quote($class, '#') . '\b[^"\']*\b' . preg_quote($modern, '#') . '#i';
        [$pairCount, $pairHits] = spryker_upgrade_scan($vendorTemplates, $pairPattern, 1);
        if ($pairCount > 0) {
            $paired = true;
            $vendorHits = $pairHits;
        }
    }

    if ($jsCount > 0) {
        $verdict = 'KEEP (JS)';
        $why = "vendor {$layer} JS selects/toggles it in {$jsCount} file(s) — rewriting BREAKS behaviour";
        $evidence = $jsHits;
    } elseif ($paired) {
        $verdict = 'PAIR';
        $why = "vendor emits it TOGETHER with `{$modern}` — mirror the pair, do not replace";
        $evidence = $vendorHits;
    } elseif ($vendorCount > 0) {
        $verdict = 'KEEP';
        $why = "vendor still emits it in {$vendorCount} {$layer} template(s) — core styles it";
        $evidence = $vendorHits;
    } else {
        $verdict = 'MIGRATE';
        $why = $modern !== null
            ? "absent from vendor templates and vendor JS — genuine leftover, convert to `{$modern}`"
            : 'absent from vendor templates and vendor JS — genuine leftover';
        $evidence = [];
        $migrateCount++;
    }

    $rows[] = compact('class', 'projectCount', 'verdict', 'why', 'evidence');
}

if ($rows === []) {
    fwrite(STDOUT, "None of the checked legacy classes appear in src/Pyz/{$layer}/.\n");
    exit(0);
}

$order = ['MIGRATE' => 0, 'PAIR' => 1, 'KEEP (JS)' => 2, 'KEEP' => 3];
usort($rows, fn(array $a, array $b): int => [$order[$a['verdict']], $a['class']] <=> [$order[$b['verdict']], $b['class']]);

fwrite(STDOUT, sprintf(
    "Legacy CSS classes in src/Pyz/%s/ checked against vendor at the INSTALLED release\n"
    . "(%d vendor %s templates, %d vendor JS/TS files)\n\n",
    $layer,
    count($vendorTemplates),
    $layer,
    count($vendorJs),
));

foreach ($rows as $row) {
    fwrite(STDOUT, sprintf(
        "  %-11s %-16s %d project file(s)\n               %s\n",
        $row['verdict'],
        $row['class'],
        $row['projectCount'],
        $row['why'],
    ));

    if ($verbose) {
        foreach ($row['evidence'] as [$file, $line, $text]) {
            $text = strlen($text) > 110 ? substr($text, 0, 107) . '...' : $text;
            fwrite(STDOUT, sprintf("               \033[2m%s:%d\033[0m %s\n", spryker_upgrade_rel($file, $root), $line, $text));
        }
    }

    fwrite(STDOUT, "\n");
}

if ($migrateCount === 0) {
    fwrite(STDOUT,
        "No migration work: every legacy class the project uses is still emitted or selected by\n"
        . "vendor at this release. Do NOT rewrite them from the framework's changelog — for the\n"
        . "KEEP (JS) rows in particular, rewriting detaches project markup from vendor JavaScript.\n");
    exit(0);
}

fwrite(STDOUT, sprintf(
    "%d class(es) marked MIGRATE — these are genuine leftovers with no vendor counterpart.\n"
    . "Everything else must be left alone. Re-run with --verbose to see the vendor evidence.\n",
    $migrateCount,
));

exit(1);
