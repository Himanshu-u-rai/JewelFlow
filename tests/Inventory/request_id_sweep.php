<?php

/**
 * Tenant-isolation inventory — every request field that carries a record id.
 *
 *   php tests/Inventory/request_id_sweep.php
 *
 * Parses app/Http (controllers and form requests). For each validation key
 * that names an id (`*_id`, `*_ids`, `*_ids.*`, `ids.*`, `lines.*.<x>_id`), prints
 * the rule and classifies how ownership is enforced AT VALIDATION:
 *
 *   exists+shop   Rule::exists / closure that names shop_id
 *   exists+parent Rule::exists constrained by a parent the request already owns
 *   exists-only   exists:table,col with no ownership constraint (any shop's id passes)
 *   closure       a closure rule or custom rule object (read it)
 *   shape-only    integer / numeric / string / nullable — ownership, if any, is
 *                 enforced later by the lookup that uses the id (read it)
 *
 * Validation is not the only place ownership can be enforced: a shape-only id
 * that is then looked up through a BelongsToShop model is scoped by that
 * lookup. This list says where to look; it does not decide.
 *
 * Read-only; parses source, touches no database.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require __DIR__.'/../../vendor/autoload.php';

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$printer = new Standard;
$finder = new NodeFinder;
$rows = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app/Http')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $path = substr($f->getPathname(), strpos($f->getPathname(), '/app/') + 1);
    $ast = $parser->parse(file_get_contents($f->getPathname()));

    foreach ($finder->findInstanceOf($ast, Node\ArrayItem::class) as $item) {
        if (! $item->key instanceof Node\Scalar\String_) {
            continue;
        }
        $key = $item->key->value;
        if (! preg_match('/(^|\.|_)ids?(\.\*)?$|_id$/', $key)) {
            continue;
        }
        $rule = preg_replace('/\s+/', ' ', $printer->prettyPrintExpr($item->value));
        // Only rule-shaped values: a string rule or an array of rules/objects.
        if (! preg_match('/required|nullable|sometimes|integer|numeric|exists|Rule::|string|array|uuid|filled|present/', $rule)) {
            continue;
        }
        $class = match (true) {
            (bool) preg_match('/Rule::exists|exists:/', $rule) && str_contains($rule, 'shop_id') => 'exists+shop',
            (bool) preg_match('/Rule::exists\([^)]*\)\s*->\s*where\(\s*\'[a-z_]+_id\'/', $rule) => 'exists+parent',
            (bool) preg_match('/Rule::exists|exists:/', $rule) => 'exists-only',
            (bool) preg_match('/function\s*\(|fn\s*\(|new [A-Z]|::[a-z]+Rule\(/', $rule) => 'closure',
            default => 'shape-only',
        };
        $rows[] = [$class, $path.':'.$item->getStartLine(), $key, mb_substr($rule, 0, 180)];
    }
}

usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
$counts = array_count_values(array_column($rows, 0));
ksort($counts);
echo "== id-bearing validation keys: ", count($rows), " ==\n";
foreach ($counts as $c => $n) {
    printf("%5d  %s\n", $n, $c);
}
echo "\n";
foreach ($rows as $r) {
    echo implode("\t", $r), "\n";
}
