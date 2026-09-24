<?php

/**
 * Tenant-isolation inventory — which cross-shop tests touch which families.
 *
 *   php tests/Inventory/test_family_map.php [--all]
 *
 * Read-only; reads files and the route table, touches no database.
 *
 * A test file counts as CROSS-SHOP when it creates two or more tenants (two
 * calls to createRetailerTenant / createManufacturerTenant / createShop, or a
 * mix) or names a second shop (shopA/shopB, otherShop, foreignShop, "another
 * shop", a Bravo fixture). A family
 * is a tenant model (its class name or table) or a route prefix (the first
 * literal URI segment; four for api/ routes), matched by URI or route name. A file "references" a family when its text names
 * it. That is a pointer to where coverage may be, not proof of it: a reference
 * says nothing about which operations were exercised. Families with no match
 * are listed as exactly that — no heuristic match. Whether they lack
 * behavioural coverage was NOT independently checked: a test can exercise a
 * family without naming its class, table or route.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$root = realpath(__DIR__.'/../..');
$all = in_array('--all', $argv, true);

// ── cross-shop test files ────────────────────────────────────────────────
$tests = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests')) as $f) {
    if ($f->getExtension() !== 'php' || ! str_ends_with($f->getFilename(), 'Test.php')) {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    $tenants = preg_match_all('/create(Retailer|Manufacturer)Tenant\(|->createShop\(/', $src);
    // Two tenant creations, a second shop's variable, or a test naming another shop
    // (tests that build both shops through one helper call it once).
    if ($tenants >= 2 || preg_match('/\$shop[AB]\b|\$(other|foreign)Shop\b|another shop|other shop\b|Bravo/i', $src)) {
        $tests[substr($f->getPathname(), strlen($root) + 1)] = $src;
    }
}

// ── families: tenant models, and route prefixes (by URI or route name) ───
$families = [];
$routeNames = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Models')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $cls = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($f->getPathname(), strlen($root.'/app/')));
    if (! class_exists($cls) || ! is_subclass_of($cls, Illuminate\Database\Eloquent\Model::class) || (new ReflectionClass($cls))->isAbstract()) {
        continue;
    }
    $model = new $cls;
    $scoped = in_array(App\Models\Concerns\BelongsToShop::class, class_uses_recursive($cls), true);
    if (! $scoped && ! in_array('shop_id', $model->getFillable(), true) && ! Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'shop_id')) {
        continue;
    }
    $families['model '.class_basename($cls)] = [
        '/\b'.preg_quote(class_basename($cls), '/').'\b|[\'"]'.preg_quote($model->getTable(), '/').'[\'"]/',
        $scoped ? 'scoped' : 'shop_id, no scope',
    ];
}
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $segments = array_values(array_filter(explode('/', $route->uri()), fn ($s) => $s !== '' && ! str_starts_with($s, '{')));
    if ($segments === [] || in_array($segments[0], ['admin', 'super-admin', 'platform', '_ignition', 'up', 'sanctum'], true)) {
        continue;
    }
    $prefix = implode('/', array_slice($segments, 0, str_starts_with($route->uri(), 'api/') ? 4 : 1));
    $routeNames['route /'.$prefix][] = $route->getName();
    $families['route /'.$prefix] = ['#[\'"](https?://[^\'"]*)?/'.preg_quote($prefix, '#').'(/|[\'"?])'
        .(($names = array_filter($routeNames['route /'.$prefix])) ? '|route\(\s*[\'"]('.implode('|', array_map(fn ($n) => preg_quote($n, '#'), $names)).')[\'"]' : '')
        .'#', 'route'];
}

// ── map ───────────────────────────────────────────────────────────────────
$covered = [];
$uncovered = [];
foreach ($families as $name => [$pattern, $kind]) {
    $hits = array_keys(array_filter($tests, fn ($src) => preg_match($pattern, $src)));
    if ($hits === []) {
        $uncovered[] = "{$name} ({$kind})";
    } else {
        $covered[$name] = [$kind, $hits];
    }
}

echo 'cross-shop test files: '.count($tests).'; families: '.count($families).' ('
    .count(array_filter($families, fn ($f) => $f[1] !== 'route')).' tenant models, '
    .count(array_filter($families, fn ($f) => $f[1] === 'route')).' route prefixes)'."\n";
echo 'families with a heuristic match in at least one cross-shop test: '.count($covered).'; with none: '.count($uncovered)."\n";
echo "\n== no heuristic match (absence of behavioural coverage NOT independently checked) ==\n";
foreach ($uncovered as $u) {
    echo "  {$u}\n";
}
echo "\n== referenced — family, cross-shop files".($all ? '' : ' (first three)')." ==\n";
ksort($covered);
foreach ($covered as $name => [$kind, $hits]) {
    printf("  %-44s %3d  %s\n", "{$name} ({$kind})", count($hits), implode(', ', array_map('basename', $all ? $hits : array_slice($hits, 0, 3))));
}
