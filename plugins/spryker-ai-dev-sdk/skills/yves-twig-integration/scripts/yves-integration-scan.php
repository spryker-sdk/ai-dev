<?php

/**
 * Yves placement scanner: which widgets and components of a module the project's storefront does
 * not render, and where the vendor templates place them.
 *
 * Integration guides wire the backend of a feature (plugins, config, schema) but leave the Yves
 * templates to the project. A widget is registered in ShopApplicationDependencyProvider and still
 * renders nothing when no template the project actually uses calls it: the project overrides the
 * vendor template that holds the call (src/<Ns>/Yves/<Module>/Theme/<theme>/<rel> shadows
 * vendor/<vendor>/<pkg>/src/<VendorNs>/Yves/<Module>/Theme/default/<rel>), so the call is gone.
 *
 * The scanner builds the effective template tree (project files, plus vendor files the project
 * does not shadow and that something reaches - see yves_scan_reachable_vendor_keys() - plus the
 * vendor blocks an extending project override keeps; Twig comments are ignored) and reports per
 * subject:
 *   placed           a template the project renders calls it
 *   missing          vendor templates call it, but only in places the project replaced or that
 *                    no template the project uses includes: each vendor call site is a
 *                    suggestion, with the project file that replaced it (overrideMode
 *                    replaces/extends) or that dropped the include (overrideMode unreachable)
 *   not_registered   vendor templates call the widget, but no project dependency provider
 *                    registers its class: the backend step is missing, a placement renders nothing
 *   no_reference     no vendor template calls it: nothing to copy, the developer decides
 *
 * Subjects: by default every widget a project dependency provider registers. With --module or
 * --package, every widget those modules define (registered or not) and every component of those
 * modules that another module's vendor template includes (molecule('x', 'Module')).
 *
 * The report is a list of suggestions, never an instruction: projects drop widgets on purpose.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php yves-integration-scan.php [scan] [--module=WishlistWidget] [--package=spryker-shop/wishlist-widget] [--json]
 *   php yves-integration-scan.php snapshot [filters]   # before an upgrade: record today's gaps
 *   php yves-integration-scan.php diff [filters]       # after it: only the gaps the upgrade added
 *
 * Writes report.json (and snapshot.json) to .ai-dev/yves-integration/, or YVES_INTEGRATION_STATE_DIR.
 * Exit code: 0 with or without suggestions (they are optional), 2 on a usage or project error.
 */

declare(strict_types=1);

const YVES_SCAN_STATUS_PLACED = 'placed';
const YVES_SCAN_STATUS_MISSING = 'missing';
const YVES_SCAN_STATUS_NOT_REGISTERED = 'not_registered';
const YVES_SCAN_STATUS_NO_REFERENCE = 'no_reference';
const YVES_SCAN_SNIPPET_MAX_LINES = 12;
const YVES_SCAN_CONTEXT_LINES = 2;

function yves_scan_project_root(): string
{
    $explicit = getenv('SPRYKER_PROJECT_ROOT');
    if (is_string($explicit) && $explicit !== '') {
        $real = realpath($explicit);
        if ($real === false || !is_file($real . '/composer.json')) {
            fwrite(STDERR, "SPRYKER_PROJECT_ROOT does not point at a composer project: $explicit\n");
            exit(2);
        }

        return $real;
    }

    $dir = (string)getcwd();
    while (true) {
        if (is_file($dir . '/composer.json') && is_dir($dir . '/src') && is_dir($dir . '/vendor')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            fwrite(STDERR, "Could not find a Spryker project root (composer.json, src/, vendor/) above the current directory.\n");
            exit(2);
        }
        $dir = $parent;
    }
}

function yves_scan_state_dir(string $root): string
{
    $explicit = getenv('YVES_INTEGRATION_STATE_DIR');
    $dir = is_string($explicit) && $explicit !== '' ? $explicit : $root . '/.ai-dev/yves-integration';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!is_file($dir . '/.gitignore')) {
        file_put_contents($dir . '/.gitignore', "*\n");
    }

    return $dir;
}

/**
 * @return list<string> project namespaces that have Yves code (src/<Ns>/Yves)
 */
function yves_scan_project_namespaces(string $root): array
{
    $namespaces = [];
    foreach (glob($root . '/src/*/Yves', GLOB_ONLYDIR) ?: [] as $dir) {
        $namespace = basename(dirname($dir));
        if (!in_array($namespace, ['Generated', 'Orm'], true)) {
            $namespaces[] = $namespace;
        }
    }

    return $namespaces;
}

/**
 * @return list<string>
 */
function yves_scan_files(string $dir, string $extension): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === $extension) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Vendor Yves modules: module name => [package, namespace, dir].
 *
 * @return array<string, array{package: string, namespace: string, dir: string}>
 */
function yves_scan_vendor_modules(string $root): array
{
    $modules = [];
    foreach (glob($root . '/vendor/*/*/src/*/Yves/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!preg_match('#/vendor/([^/]+/[^/]+)/src/([^/]+)/Yves/([^/]+)$#', $dir, $m)) {
            continue;
        }
        $modules[$m[3]] ??= ['package' => $m[1], 'namespace' => $m[2], 'dir' => $dir];
    }

    return $modules;
}

/**
 * Template key: "<Module>/<path below Theme/<theme>>", the unit Twig resolves project-first.
 */
function yves_scan_template_key(string $path): ?string
{
    return preg_match('#/Yves/([^/]+)/Theme/[^/]+/(.+\.twig)$#', $path, $m) ? $m[1] . '/' . $m[2] : null;
}

/**
 * Widget classes defined in a Yves module dir: FQCN => name (from getName(), else the class name).
 *
 * @return array<string, string>
 */
function yves_scan_widget_classes(string $moduleDir): array
{
    $widgets = [];
    foreach (glob($moduleDir . '/Widget/*.php') ?: [] as $file) {
        $code = (string)file_get_contents($file);
        if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $ns) || !preg_match('/^(final\s+|abstract\s+)?class\s+(\w+)/m', $code, $class)) {
            continue;
        }
        if (trim($class[1]) === 'abstract') {
            continue;
        }
        $name = preg_match('/function\s+getName\s*\(\s*\)[^{]*\{\s*return\s+[\'"]([^\'"]+)[\'"]/', $code, $n) ? $n[1] : $class[2];
        $widgets[$ns[1] . '\\' . $class[2]] = $name;
    }

    return $widgets;
}

/**
 * FQCNs referenced as Foo::class in the project's Yves dependency providers, resolved through use statements.
 *
 * @param list<string> $namespaces
 *
 * @return array<string, string> FQCN => dependency provider (relative path)
 */
function yves_scan_registered_classes(string $root, array $namespaces): array
{
    $registered = [];
    foreach ($namespaces as $namespace) {
        foreach (glob($root . '/src/' . $namespace . '/Yves/*/*DependencyProvider.php') ?: [] as $file) {
            $code = (string)file_get_contents($file);
            $uses = [];
            preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $code, $useMatches, PREG_SET_ORDER);
            foreach ($useMatches as $use) {
                $alias = $use[2] ?? '';
                $uses[$alias !== '' ? $alias : substr((string)strrchr('\\' . $use[1], '\\'), 1)] = $use[1];
            }
            preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $ownNamespace);
            preg_match_all('/\\\\?([\w\\\\]+)::class/', $code, $classMatches);
            foreach ($classMatches[1] as $reference) {
                $head = explode('\\', $reference)[0];
                $fqcn = isset($uses[$head])
                    ? $uses[$head] . substr($reference, strlen($head))
                    : (str_contains($reference, '\\') ? $reference : ($ownNamespace[1] ?? '') . '\\' . $reference);
                $registered[ltrim($fqcn, '\\')] ??= substr($file, strlen($root) + 1);
            }
        }
    }

    return $registered;
}

/**
 * Blank out Twig comments ({# ... #}, possibly spanning lines) so that nothing inside them is
 * matched as a reference, an extends or a block tag. Newlines are kept, so line numbers stay valid.
 */
function yves_scan_strip_twig_comments(string $code): string
{
    return (string)preg_replace_callback('/\{#.*?#\}/s', fn (array $m): string => (string)preg_replace('/[^\n]/', ' ', $m[0]), $code);
}

/**
 * Template key a component reference points at ("molecule:x@Module" => "Module/components/molecules/x/x.twig").
 */
function yves_scan_reference_key(string $name): ?string
{
    if (!preg_match('/^(atom|molecule|organism|template|view):([\w-]+)@(\w+)$/', $name, $m)) {
        return null;
    }
    $directory = ['atom' => 'components/atoms', 'molecule' => 'components/molecules', 'organism' => 'components/organisms', 'template' => 'templates', 'view' => 'views'][$m[1]];

    return $m[3] . '/' . $directory . '/' . $m[2] . '/' . $m[2] . '.twig';
}

/**
 * Parse a Twig file: references with their enclosing block chain, the blocks it defines, its extends target.
 * Twig comments are ignored. "dynamic" lists "<tier>:<Module>" for atom/molecule/organism calls whose
 * template name is a variable: they can reach any component of that tier in that module.
 * "lines" are the original lines (for snippets); matching runs on the comment-free copy.
 *
 * @return array{references: list<array<string, mixed>>, blocks: array<string, bool>, extends: ?string, lines: list<string>, dynamic: list<string>}
 */
function yves_scan_parse_twig(string $file): array
{
    $code = (string)file_get_contents($file);
    $lines = explode("\n", $code);
    $matchLines = explode("\n", yves_scan_strip_twig_comments($code));
    $dynamic = [];
    $stack = [];
    $references = [];
    $blocks = [];
    $blockStarts = [];
    $embedDepth = 0;
    $extends = null;
    $referenceRe = '/\{%-?\s*widget\s+[\'"](\w+)[\'"]|(?:findWidget|widgetExists|widgetGlobalExists|widgetGlobal)\(\s*[\'"](\w+)[\'"]|\b(atom|molecule|organism|template|view)\(\s*[\'"]([\w-]+)[\'"]\s*,\s*[\'"]@?(?:[\w]+:)?(\w+)[\'"]/';

    foreach ($matchLines as $index => $line) {
        if ($extends === null && preg_match('/\{%-?\s*extends\s+(.+?)\s*-?%\}/', $line, $m)) {
            $extends = $m[1];
        }
        // A variable as the template name with a literal (or omitted, i.e. ShopUi) module: atom(type),
        // molecule(data.template, 'Module'). A variable module as well (the generic widget dispatch
        // templates) names nothing the scanner could narrow down and is left out.
        if (preg_match_all('/\b(atom|molecule|organism)\(\s*[a-zA-Z_][^,()]*(?:,\s*(?:[\'"]@?(?:\w+:)?(?<module>\w+)[\'"]|(?<variable>[a-zA-Z_])))?/', $line, $dynamicMatches, PREG_SET_ORDER)) {
            foreach ($dynamicMatches as $dynamicMatch) {
                if (($dynamicMatch['variable'] ?? '') === '') {
                    $dynamic[] = $dynamicMatch[1] . ':' . (($dynamicMatch['module'] ?? '') !== '' ? $dynamicMatch['module'] : 'ShopUi');
                }
            }
        }

        if (preg_match_all($referenceRe, $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (($match[1] ?? '') !== '') {
                    $references[] = ['type' => 'widget', 'name' => $match[1], 'kind' => 'tag', 'line' => $index + 1, 'blocks' => $stack];
                } elseif (($match[2] ?? '') !== '') {
                    $references[] = ['type' => 'widget', 'name' => $match[2], 'kind' => 'function', 'line' => $index + 1, 'blocks' => $stack];
                } elseif (($match[4] ?? '') !== '') {
                    $references[] = ['type' => 'component', 'name' => $match[3] . ':' . $match[4] . '@' . $match[5], 'module' => $match[5], 'kind' => 'include', 'line' => $index + 1, 'blocks' => $stack];
                }
            }
        }

        // Blocks inside {% embed %} belong to the embedded component, not to this template: an
        // override of this template cannot redefine them, so they are marked with a leading '~'.
        preg_match_all('/\{%-?\s*(block\s+(\w+)(.*?)|endblock\b.*?|embed\b.*?|endembed\b.*?)\s*-?%\}/', $line, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if (str_starts_with($tag[1], 'embed')) {
                $embedDepth++;

                continue;
            }
            if (str_starts_with($tag[1], 'endembed')) {
                $embedDepth = max(0, $embedDepth - 1);

                continue;
            }
            if (str_starts_with($tag[1], 'endblock')) {
                $closed = array_pop($stack);
                if ($closed !== null && !str_starts_with($closed, '~') && isset($blockStarts[$closed])) {
                    $body = implode("\n", array_slice($matchLines, $blockStarts[$closed], $index - $blockStarts[$closed] + 1));
                    $blocks[$closed] = str_contains($body, 'parent()');
                }

                continue;
            }
            if (trim($tag[3]) !== '') {
                // {% block title 'Shop' %}: a one-line block without endblock.
                if ($embedDepth === 0) {
                    $blocks[$tag[2]] = false;
                }

                continue;
            }
            $stack[] = $embedDepth > 0 ? '~' . $tag[2] : $tag[2];
            if ($embedDepth === 0) {
                $blockStarts[$tag[2]] = $index;
            }
        }
    }

    return ['references' => $references, 'blocks' => $blocks, 'extends' => $extends, 'lines' => $lines, 'dynamic' => array_values(array_unique($dynamic))];
}

/**
 * Does the project override render the vendor content at this block chain?
 * A replacing override renders none of it. An extending one renders it unless it redefines
 * a block of the chain without calling parent().
 *
 * @param list<string> $chain
 * @param array<string, bool> $overrideBlocks block => calls parent()
 */
function yves_scan_vendor_site_survives(bool $overrideExtends, array $chain, array $overrideBlocks): bool
{
    if (!$overrideExtends) {
        return false;
    }
    foreach ($chain as $block) {
        if (isset($overrideBlocks[$block]) && $overrideBlocks[$block] === false) {
            return false;
        }
    }

    return true;
}

/**
 * Does the override extend the vendor version of itself (rather than replace it)?
 */
function yves_scan_override_extends_vendor(?string $extends, string $key): bool
{
    if ($extends === null) {
        return false;
    }
    [$module] = explode('/', $key, 2);

    return (bool)preg_match('/[\'"]@(?:Spryker\w*|Spryker):' . preg_quote($module, '/') . '[\'"]/', $extends);
}

/**
 * Which vendor templates can actually be rendered, by template key.
 *
 * A vendor template renders when the project does not replace it (an override that extends the
 * vendor template keeps it alive) and something reaches it:
 *   - roots: vendor views/ and templates/ (controllers and widgets render them), and every vendor
 *     template that nothing in any scanned template references (its consumer is unknown, so the
 *     old behaviour holds: it counts, marked 'undecided');
 *   - components (atoms, molecules, organisms): when a reachable template includes them through a
 *     reference that survives the project's override of the including template, or the project
 *     includes them itself;
 *   - dynamic names (atom(type), molecule(data.template, 'Module')): a reachable template with one
 *     reaches every component of that tier in that module, marked 'undecided'. Calls with a
 *     variable module (the generic widget dispatch templates) are ignored.
 * 'certain' means a chain of literal references from a root or a project template; 'undecided'
 * means the chain starts at an unreferenced template or goes through a dynamic name.
 *
 * @param array<string, array<string, mixed>> $vendorParsed template key => parsed vendor template
 * @param array<string, array<string, mixed>> $projectParsed template key => parsed project template
 *
 * @return array<string, string> template key => 'certain' | 'undecided' (keys of vendor templates not listed are unreachable)
 */
function yves_scan_reachable_vendor_keys(array $vendorParsed, array $projectParsed): array
{
    $alive = [];
    foreach ($vendorParsed as $key => $parsed) {
        $alive[$key] = !isset($projectParsed[$key]) || yves_scan_override_extends_vendor($projectParsed[$key]['extends'], $key);
    }

    $referenced = [];
    foreach ([$vendorParsed, $projectParsed] as $templates) {
        foreach ($templates as $key => $parsed) {
            foreach ($parsed['references'] as $reference) {
                $target = $reference['type'] === 'component' ? yves_scan_reference_key($reference['name']) : null;
                if ($target !== null && $target !== $key) {
                    $referenced[$target] = true;
                }
            }
        }
    }

    $state = [];
    $queue = [];
    $mark = function (string $key, string $level) use (&$state, &$queue, $vendorParsed): void {
        if (!isset($vendorParsed[$key]) || ($state[$key] ?? null) === 'certain' || ($state[$key] ?? null) === $level) {
            return;
        }
        $state[$key] = $level;
        $queue[] = $key;
    };
    $dynamicModules = [];
    $expand = function (string $dynamic) use (&$dynamicModules, $mark, $vendorParsed): void {
        if (isset($dynamicModules[$dynamic])) {
            return;
        }
        $dynamicModules[$dynamic] = true;
        [$tier, $module] = explode(':', $dynamic, 2);
        $prefix = $module . '/components/' . $tier . 's/';
        foreach (array_keys($vendorParsed) as $key) {
            if (str_starts_with($key, $prefix)) {
                $mark($key, 'undecided');
            }
        }
    };

    foreach (array_keys($vendorParsed) as $key) {
        if (preg_match('#^\w+/(views|templates)/#', $key)) {
            $mark($key, 'certain');
        } elseif (!isset($referenced[$key])) {
            $mark($key, 'undecided');
        }
    }
    foreach ($projectParsed as $parsed) {
        foreach ($parsed['references'] as $reference) {
            $target = $reference['type'] === 'component' ? yves_scan_reference_key($reference['name']) : null;
            if ($target !== null) {
                $mark($target, 'certain');
            }
        }
        foreach ($parsed['dynamic'] as $module) {
            $expand($module);
        }
    }

    while ($queue !== []) {
        $key = array_shift($queue);
        if (!$alive[$key]) {
            continue;
        }
        $parsed = $vendorParsed[$key];
        $override = $projectParsed[$key] ?? null;
        foreach ($parsed['dynamic'] as $module) {
            $expand($module);
        }
        foreach ($parsed['references'] as $reference) {
            $target = $reference['type'] === 'component' ? yves_scan_reference_key($reference['name']) : null;
            if ($target === null) {
                continue;
            }
            if ($override === null || yves_scan_vendor_site_survives(true, $reference['blocks'], $override['blocks'])) {
                $mark($target, $state[$key]);
            }
        }
    }

    return $state;
}

/**
 * The project template that replaced the nearest ancestor of an unreachable vendor template: the
 * file the developer would look at to see why the template is no longer included.
 *
 * @param array<string, list<string>> $referencers template key => keys of the vendor templates referencing it
 * @param array<string, string> $projectTemplates template key => project file
 */
function yves_scan_replacing_project_file(string $key, array $referencers, array $projectTemplates): ?string
{
    $queue = [$key];
    $seen = [$key => true];
    while ($queue !== []) {
        $current = array_shift($queue);
        if (isset($projectTemplates[$current])) {
            return $projectTemplates[$current];
        }
        foreach ($referencers[$current] ?? [] as $parent) {
            if (!isset($seen[$parent])) {
                $seen[$parent] = true;
                $queue[] = $parent;
            }
        }
    }

    return null;
}

/**
 * @param list<string> $lines
 */
function yves_scan_snippet(array $lines, int $line, string $type): string
{
    $start = $line - 1;
    $end = $start;
    if ($type === 'widget' && preg_match('/\{%-?\s*widget\b/', $lines[$start] ?? '')) {
        for ($i = $start; $i < min(count($lines), $start + YVES_SCAN_SNIPPET_MAX_LINES); $i++) {
            $end = $i;
            if (preg_match('/\{%-?\s*endwidget/', $lines[$i])) {
                break;
            }
        }
    }

    return rtrim(implode("\n", array_slice($lines, $start, $end - $start + 1)));
}

/**
 * @param list<string> $lines
 *
 * @return array{before: list<string>, after: list<string>}
 */
function yves_scan_context(array $lines, int $line, string $snippet): array
{
    $before = [];
    for ($i = $line - 2; $i >= 0 && count($before) < YVES_SCAN_CONTEXT_LINES; $i--) {
        if (trim($lines[$i]) !== '') {
            array_unshift($before, trim($lines[$i]));
        }
    }
    $after = [];
    for ($i = $line - 1 + substr_count($snippet, "\n") + 1; $i < count($lines) && count($after) < YVES_SCAN_CONTEXT_LINES; $i++) {
        if (trim($lines[$i]) !== '') {
            $after[] = trim($lines[$i]);
        }
    }

    return ['before' => $before, 'after' => $after];
}

/**
 * @param array{modules: list<string>, packages: list<string>} $filters
 *
 * @return array<string, mixed>
 */
function yves_scan_run(string $root, array $filters): array
{
    $namespaces = yves_scan_project_namespaces($root);
    $vendorModules = yves_scan_vendor_modules($root);

    $vendorTemplates = [];
    foreach ($vendorModules as $module => $info) {
        foreach (yves_scan_files($info['dir'] . '/Theme/default', 'twig') as $file) {
            $key = yves_scan_template_key($file);
            if ($key !== null) {
                $vendorTemplates[$key] = $file;
            }
        }
    }
    $projectTemplates = [];
    foreach ($namespaces as $namespace) {
        foreach (yves_scan_files($root . '/src/' . $namespace . '/Yves', 'twig') as $file) {
            $key = yves_scan_template_key($file);
            if ($key !== null) {
                $projectTemplates[$key] ??= $file;
            }
        }
    }

    $widgetClasses = [];
    $widgetModules = [];
    foreach ($vendorModules as $module => $info) {
        foreach (yves_scan_widget_classes($info['dir']) as $fqcn => $name) {
            $widgetClasses[$fqcn] = $name;
            $widgetModules[$name] ??= $module;
        }
    }
    foreach ($namespaces as $namespace) {
        foreach (glob($root . '/src/' . $namespace . '/Yves/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (yves_scan_widget_classes($dir) as $fqcn => $name) {
                $widgetClasses[$fqcn] = $name;
                $widgetModules[$name] ??= basename($dir);
            }
        }
    }
    $registeredNames = [];
    foreach (yves_scan_registered_classes($root, $namespaces) as $fqcn => $provider) {
        if (isset($widgetClasses[$fqcn])) {
            $registeredNames[$widgetClasses[$fqcn]] = $provider;
        }
    }

    $effective = [];
    $undecidedAt = [];
    $vendorSites = [];
    $vendorComponentReferences = [];
    $parsedProject = [];
    foreach ($projectTemplates as $key => $file) {
        $parsedProject[$key] = yves_scan_parse_twig($file);
        foreach ($parsedProject[$key]['references'] as $reference) {
            $effective[$reference['name']][] = substr($file, strlen($root) + 1) . ':' . $reference['line'];
        }
    }
    $parsedVendor = [];
    $referencers = [];
    foreach ($vendorTemplates as $key => $file) {
        $parsedVendor[$key] = yves_scan_parse_twig($file);
        foreach ($parsedVendor[$key]['references'] as $reference) {
            $target = $reference['type'] === 'component' ? yves_scan_reference_key($reference['name']) : null;
            if ($target !== null && $target !== $key) {
                $referencers[$target][] = $key;
            }
        }
    }
    $reachable = yves_scan_reachable_vendor_keys($parsedVendor, $parsedProject);

    foreach ($parsedVendor as $key => $parsed) {
        $file = $vendorTemplates[$key];
        $override = $parsedProject[$key] ?? null;
        $overrideExtends = $override !== null && yves_scan_override_extends_vendor($override['extends'], $key);
        $fromModule = explode('/', $key, 2)[0];
        $level = $override === null || $overrideExtends ? ($reachable[$key] ?? null) : null;
        foreach ($parsed['references'] as $reference) {
            $relative = substr($file, strlen($root) + 1);
            if ($reference['type'] === 'component') {
                $vendorComponentReferences[] = $reference + ['fromModule' => $fromModule];
            }
            $survives = $override === null || yves_scan_vendor_site_survives($overrideExtends, $reference['blocks'], $override['blocks']);
            if ($survives && $level !== null) {
                // A template nothing references (consumer unknown) counts as before, except for a
                // component of its own module: that one is no placement of the module's feature.
                $subjectModule = $reference['type'] === 'widget' ? ($widgetModules[$reference['name']] ?? '') : $reference['module'];
                if ($level === 'certain') {
                    $effective[$reference['name']][] = $relative . ':' . $reference['line'];

                    continue;
                }
                if ($fromModule !== $subjectModule) {
                    $effective[$reference['name']][] = $relative . ':' . $reference['line'];
                    $undecidedAt[$reference['name']][] = $relative . ':' . $reference['line'];

                    continue;
                }
            }
            $snippet = yves_scan_snippet($parsed['lines'], $reference['line'], $reference['type']);
            $site = [
                'vendorFile' => $relative,
                'line' => $reference['line'],
                'kind' => $reference['kind'],
                'blocks' => $reference['blocks'],
                'snippet' => $snippet,
                'context' => yves_scan_context($parsed['lines'], $reference['line'], $snippet),
            ];
            if (!$survives) {
                $sharedBlocks = array_values(array_filter($reference['blocks'], fn (string $block): bool => isset($override['blocks'][$block])));
                $vendorSites[$reference['name']][] = $site + [
                    'projectFile' => substr($projectTemplates[$key], strlen($root) + 1),
                    'overrideMode' => $overrideExtends ? 'extends' : 'replaces',
                    'projectBlocks' => $sharedBlocks,
                ];

                continue;
            }
            // The template is not replaced (or its block survives), but nothing the project renders
            // includes it: the nearest replaced ancestor is the project file that dropped the include.
            $replacing = yves_scan_replacing_project_file($key, $referencers, $projectTemplates);
            $vendorSites[$reference['name']][] = $site + [
                'projectFile' => $replacing !== null ? substr($replacing, strlen($root) + 1) : null,
                'overrideMode' => 'unreachable',
                'projectBlocks' => [],
            ];
        }
    }

    $scopeModules = $filters['modules'];
    foreach ($filters['packages'] as $package) {
        foreach ($vendorModules as $module => $info) {
            if ($info['package'] === strtolower($package)) {
                $scopeModules[] = $module;
            }
        }
    }
    $scopeModules = array_values(array_unique($scopeModules));
    $scoped = $filters['modules'] !== [] || $filters['packages'] !== [];

    $subjects = [];
    if ($scoped) {
        foreach ($widgetModules as $name => $module) {
            if (in_array($module, $scopeModules, true)) {
                $subjects[$name] = ['type' => 'widget', 'module' => $module];
            }
        }
        // Components of the scoped modules that another module's vendor template includes: the
        // page-level placements. Includes inside the module itself travel with its own widgets.
        foreach ($vendorComponentReferences as $reference) {
            if (in_array($reference['module'], $scopeModules, true) && $reference['fromModule'] !== $reference['module']) {
                $subjects[$reference['name']] = ['type' => 'component', 'module' => $reference['module']];
            }
        }
    } else {
        foreach ($registeredNames as $name => $provider) {
            $subjects[$name] = ['type' => 'widget', 'module' => $widgetModules[$name] ?? ''];
        }
    }

    $items = [];
    foreach ($subjects as $name => $subject) {
        $id = $name;
        $placed = $effective[$name] ?? [];
        $sites = $vendorSites[$name] ?? [];
        $registered = $subject['type'] === 'component' || isset($registeredNames[$name]);

        if (!$registered && ($sites !== [] || $placed !== [])) {
            $status = YVES_SCAN_STATUS_NOT_REGISTERED;
        } elseif ($placed !== []) {
            $status = YVES_SCAN_STATUS_PLACED;
        } elseif ($sites !== []) {
            $status = YVES_SCAN_STATUS_MISSING;
        } else {
            $status = YVES_SCAN_STATUS_NO_REFERENCE;
        }

        $items[] = [
            'id' => $id,
            'type' => $subject['type'],
            'name' => $name,
            'module' => $subject['module'],
            'package' => $vendorModules[$subject['module']]['package'] ?? null,
            'registered' => $subject['type'] === 'component' ? null : $registered,
            'registeredIn' => $registeredNames[$name] ?? null,
            'status' => $status,
            'placedAt' => $placed,
            // Subset of placedAt whose template nothing references (reachability undecided).
            'placedUndecided' => $undecidedAt[$name] ?? [],
            'suggestions' => $status === YVES_SCAN_STATUS_PLACED ? [] : $sites,
        ];
    }
    usort($items, fn (array $a, array $b): int => [yves_scan_status_rank($a['status']), $a['id']] <=> [yves_scan_status_rank($b['status']), $b['id']]);

    return [
        'scope' => $scoped ? ['modules' => $scopeModules, 'filters' => $filters] : 'registered widgets',
        'projectNamespaces' => $namespaces,
        'counts' => array_count_values(array_column($items, 'status')),
        'items' => $items,
    ];
}

function yves_scan_status_rank(string $status): int
{
    return [YVES_SCAN_STATUS_MISSING => 0, YVES_SCAN_STATUS_NOT_REGISTERED => 1, YVES_SCAN_STATUS_NO_REFERENCE => 2, YVES_SCAN_STATUS_PLACED => 3][$status] ?? 4;
}

/**
 * Gap identity for snapshot/diff: the subject, its status and its vendor call sites.
 *
 * @param array<string, mixed> $report
 *
 * @return array<string, true>
 */
function yves_scan_gap_keys(array $report): array
{
    $keys = [];
    foreach ($report['items'] as $item) {
        if ($item['status'] === YVES_SCAN_STATUS_PLACED) {
            continue;
        }
        $keys[$item['id'] . '|' . $item['status']] = true;
        foreach ($item['suggestions'] as $site) {
            $keys[$item['id'] . '|' . $item['status'] . '|' . $site['vendorFile'] . '|' . trim($site['snippet'])] = true;
        }
    }

    return $keys;
}

/**
 * Keep only the gaps and call sites that the snapshot did not have.
 *
 * @param array<string, mixed> $report
 * @param array<string, true> $snapshotKeys
 *
 * @return array<string, mixed>
 */
function yves_scan_new_gaps(array $report, array $snapshotKeys): array
{
    $items = [];
    foreach ($report['items'] as $item) {
        if ($item['status'] === YVES_SCAN_STATUS_PLACED) {
            continue;
        }
        $itemKey = $item['id'] . '|' . $item['status'];
        $item['suggestions'] = array_values(array_filter(
            $item['suggestions'],
            fn (array $site): bool => !isset($snapshotKeys[$itemKey . '|' . $site['vendorFile'] . '|' . trim($site['snippet'])]),
        ));
        if (!isset($snapshotKeys[$itemKey]) || $item['suggestions'] !== []) {
            $items[] = $item;
        }
    }
    $report['items'] = $items;
    $report['counts'] = array_count_values(array_column($items, 'status'));

    return $report;
}

/**
 * @param array<string, mixed> $report
 */
function yves_scan_print(array $report): void
{
    $counts = $report['counts'];
    if (is_array($report['scope']) && $report['scope']['modules'] === []) {
        printf(
            "Scope: no installed Yves module matches %s - nothing to place (is the package installed, and does it have Yves code?)\n",
            implode(', ', array_merge($report['scope']['filters']['modules'], $report['scope']['filters']['packages'])),
        );

        return;
    }
    printf(
        "Scope: %s\nplaced %d, missing %d, not_registered %d, no_reference %d\n",
        is_array($report['scope']) ? implode(', ', $report['scope']['modules']) : $report['scope'],
        $counts[YVES_SCAN_STATUS_PLACED] ?? 0,
        $counts[YVES_SCAN_STATUS_MISSING] ?? 0,
        $counts[YVES_SCAN_STATUS_NOT_REGISTERED] ?? 0,
        $counts[YVES_SCAN_STATUS_NO_REFERENCE] ?? 0,
    );
    foreach ($report['items'] as $item) {
        if ($item['status'] === YVES_SCAN_STATUS_PLACED) {
            continue;
        }
        printf("\n[%s] %s %s (%s)\n", $item['status'], $item['type'], $item['name'], $item['package'] ?? $item['module']);
        foreach ($item['suggestions'] as $site) {
            $where = sprintf('  vendor %s:%d%s', $site['vendorFile'], $site['line'], $site['blocks'] !== [] ? ' in block ' . implode(' > ', $site['blocks']) : '');
            if ($site['overrideMode'] === 'unreachable') {
                printf(
                    "%s\n  -> not rendered: no template the project uses includes it%s\n",
                    $where,
                    $site['projectFile'] !== null ? '; the including template was replaced by ' . $site['projectFile'] : '',
                );

                continue;
            }
            printf(
                "%s\n  -> project %s (%s%s)\n",
                $where,
                $site['projectFile'],
                $site['overrideMode'],
                $site['projectBlocks'] !== [] ? '; project redefines ' . implode(', ', $site['projectBlocks']) : '',
            );
        }
    }
}

/**
 * @param list<string> $argv
 */
function yves_scan_main(array $argv): int
{
    $mode = 'scan';
    $filters = ['modules' => [], 'packages' => []];
    $json = false;
    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['scan', 'snapshot', 'diff'], true)) {
            $mode = $argument;
        } elseif (str_starts_with($argument, '--module=')) {
            array_push($filters['modules'], ...array_filter(explode(',', substr($argument, 9))));
        } elseif (str_starts_with($argument, '--package=')) {
            array_push($filters['packages'], ...array_filter(explode(',', substr($argument, 10))));
        } elseif ($argument === '--json') {
            $json = true;
        } else {
            fwrite(STDERR, "Usage: yves-integration-scan.php [scan|snapshot|diff] [--module=Name,...] [--package=vendor/name,...] [--json]\n");

            return 2;
        }
    }

    $root = yves_scan_project_root();
    $stateDir = yves_scan_state_dir($root);
    $report = yves_scan_run($root, $filters);

    if ($mode === 'snapshot') {
        file_put_contents($stateDir . '/snapshot.json', json_encode(array_keys(yves_scan_gap_keys($report)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        printf("Snapshot of %d gap(s) written to %s/snapshot.json\n", count(array_filter($report['items'], fn (array $item): bool => $item['status'] !== YVES_SCAN_STATUS_PLACED)), $stateDir);

        return 0;
    }
    if ($mode === 'diff') {
        $snapshotFile = $stateDir . '/snapshot.json';
        if (!is_file($snapshotFile)) {
            fwrite(STDERR, "No snapshot at $snapshotFile: run `snapshot` before the update.\n");

            return 2;
        }
        $report = yves_scan_new_gaps($report, array_fill_keys((array)json_decode((string)file_get_contents($snapshotFile), true), true));
        $report['mode'] = 'diff: gaps that are new since the snapshot';
    }

    file_put_contents($stateDir . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } else {
        yves_scan_print($report);
        printf("\nReport: %s/report.json\n", $stateDir);
    }

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(yves_scan_main($argv));
}
