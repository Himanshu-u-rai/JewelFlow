<?php

/**
 * Tenant-isolation inventory — every query that the BelongsToShop scope does
 * NOT govern, classified per expression.
 *
 *   php tests/Inventory/tenant_query_sweep.php [--all]
 *
 * Read-only: it parses app/ and reads the schema of jewelflow_testing (refuses
 * any other database). Nothing is executed against application data.
 *
 * Finds three kinds of expression:
 *   raw      DB::table('t')… chains, and DB::select/insert/update/delete/
 *            statement/unprepared/affectingStatement with a SQL string
 *   unscoped Model::…  chains on a model WITHOUT BelongsToShop
 *   removed  any chain calling withoutTenant(), withoutGlobalScope('shop')
 *            or withoutGlobalScopes()
 *
 * For each: the table, whether that table is tenant-owned (has shop_id, or
 * reaches a shop_id table through a foreign key), whether the SAME expression
 * names shop_id (a constraint elsewhere in the method is not counted — the
 * earlier sweep's 12-line window was the weakness it replaces), whether it
 * writes, and whether request data flows into it textually.
 *
 * Default output: only tenant-owned expressions with no shop_id in the
 * expression — the list that must be read. --all prints every expression.
 */

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

$all = in_array('--all', $argv, true);

// ── schema: which tables are tenant-owned ─────────────────────────────────
$shopTables = collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'shop_id'"))
    ->pluck('table_name')->flip()->all();
$fks = DB::select("select tc.table_name child, ccu.table_name parent from information_schema.table_constraints tc
    join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
    where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = current_schema()");
$parentOwned = [];
foreach ($fks as $fk) {
    if (! isset($shopTables[$fk->child]) && isset($shopTables[$fk->parent]) && ! in_array($fk->child, ['shops', 'plans', 'platform_audit_logs'], true)) {
        $parentOwned[$fk->child] = $fk->parent;
    }
}
$owned = fn (string $t) => isset($shopTables[$t]) ? 'shop_id' : (isset($parentOwned[$t]) ? 'via:'.$parentOwned[$t] : '');

// ── models: scoped or not ─────────────────────────────────────────────────
$models = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app/Models')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $cls = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($f->getPathname(), strpos($f->getPathname(), 'app/Models') + 4));
    if (! class_exists($cls) || ! is_subclass_of($cls, Illuminate\Database\Eloquent\Model::class) || (new ReflectionClass($cls))->isAbstract()) {
        continue;
    }
    $models[class_basename($cls)] = [
        'scoped' => in_array(App\Models\Concerns\BelongsToShop::class, class_uses_recursive($cls), true),
        'table' => (new $cls)->getTable(),
    ];
}

// ── parse app/ ────────────────────────────────────────────────────────────
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$printer = new Standard;
$finder = new NodeFinder;
$writes = ['update', 'insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'updateOrInsert', 'delete', 'forceDelete', 'increment', 'decrement',
    'incrementEach', 'decrementEach', 'create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'truncate', 'save', 'restore', 'destroy'];
$rawWriters = ['insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement'];
$rows = [];

/** Outermost method-call chain containing $node, as printed source. */
$chainOf = function (Node $root, array $parents) use ($printer): Node {
    $top = $root;
    foreach ($parents as $p) {
        if (($p instanceof Node\Expr\MethodCall || $p instanceof Node\Expr\NullsafeMethodCall) && $p->var === $top) {
            $top = $p;
        } else {
            break;
        }
    }

    return $top;
};

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $path = substr($f->getPathname(), strpos($f->getPathname(), '/app/') + 1);
    try {
        $ast = $parser->parse(file_get_contents($f->getPathname()));
    } catch (Throwable $e) {
        fwrite(STDERR, "parse error {$path}: {$e->getMessage()}\n");
        continue;
    }

    // Parent links, so a chain can be walked outwards from its root.
    $traverser = new PhpParser\NodeTraverser(new PhpParser\NodeVisitor\ParentConnectingVisitor);
    $ast = $traverser->traverse($ast);

    $parentsOf = function (Node $n): array {
        $out = [];
        while ($n = $n->getAttribute('parent')) {
            $out[] = $n;
        }

        return $out;
    };

    $record = function (string $kind, string $table, Node $root) use (&$rows, $path, $parentsOf, $chainOf, $printer, $owned, $writes, $rawWriters) {
        $top = $chainOf($root, $parentsOf($root));
        $src = $printer->prettyPrintExpr($top);
        $methods = [];
        for ($n = $top; $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall || $n instanceof Node\Expr\StaticCall; $n = $n->var ?? null) {
            if ($n->name instanceof Node\Identifier) {
                $methods[] = $n->name->toString();
            }
            if ($n instanceof Node\Expr\StaticCall) {
                break;
            }
        }
        $isWrite = (bool) array_intersect($methods, $writes)
            || ($kind === 'raw' && $root instanceof Node\Expr\StaticCall && in_array($root->name->toString(), $rawWriters, true) && ! str_starts_with(strtolower(ltrim(str_replace(["'", '"'], '', $src ?? ''), 'DB:')), 'select'));
        $rows[] = [
            'file' => $path, 'line' => $root->getStartLine(), 'kind' => $kind, 'table' => $table,
            'owned' => $table === '?' ? '?' : $owned($table),
            'shop_id' => str_contains($src, 'shop_id') ? 'yes' : 'NO',
            'op' => $isWrite ? 'WRITE' : 'read',
            'request' => preg_match('/\$request\b|request\(\)|\$validated\b|->input\(|->validated\(\)|\$payload\b/', $src) ? 'request' : '',
            'src' => preg_replace('/\s+/', ' ', mb_substr($src, 0, 260)),
        ];
    };

    foreach ($finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            continue;
        }
        $class = $call->class->getLast();
        $method = $call->name->toString();
        $arg0 = $call->args[0]->value ?? null;

        if ($class === 'DB' && $method === 'table') {
            $record('raw', $arg0 instanceof Node\Scalar\String_ ? explode(' ', $arg0->value)[0] : '?', $call);
        } elseif ($class === 'DB' && in_array($method, ['select', 'selectOne', 'scalar', 'insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement', 'cursor'], true)) {
            $sql = $arg0 instanceof Node\Scalar\String_ ? $arg0->value : ($arg0 ? $printer->prettyPrintExpr($arg0) : '');
            preg_match('/\b(?:from|into|update|join|table)\s+"?([a-z_]+)"?/i', $sql, $m);
            $record('raw', $m[1] ?? '?', $call);
        } elseif (isset($models[$class]) && ! $models[$class]['scoped']) {
            $record('unscoped', $models[$class]['table'], $call);
        }
    }

    foreach ($finder->find($ast, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall)
        && $n->name instanceof Node\Identifier
        && in_array($n->name->toString(), ['withoutTenant', 'withoutGlobalScopes', 'withoutGlobalScope'], true)) as $call) {
        // Walk inwards to the static root to name the model.
        $root = $call;
        while ($root instanceof Node\Expr\MethodCall) {
            $root = $root->var;
        }
        $model = $root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name ? $root->class->getLast() : '?';
        $record('removed', $models[$model]['table'] ?? '?', $call);
    }
}

// One row per expression: a chain found from two roots keeps its first.
$seen = [];
$rows = array_values(array_filter($rows, function ($r) use (&$seen) {
    $k = $r['file'].':'.$r['line'].':'.$r['kind'];

    return ! isset($seen[$k]) && ($seen[$k] = true);
}));

$summary = [];
foreach ($rows as $r) {
    $k = sprintf('%-8s %-8s owned=%-3s shop_id=%-3s', $r['kind'], $r['op'], $r['owned'] === '' ? 'no' : ($r['owned'] === '?' ? '?' : 'yes'), $r['shop_id']);
    $summary[$k] = ($summary[$k] ?? 0) + 1;
}
ksort($summary);
echo "== summary (expressions) ==\n";
foreach ($summary as $k => $n) {
    printf("%5d  %s\n", $n, $k);
}

echo "\n== ".($all ? 'all expressions' : 'tenant-owned (or unknown table), no shop_id in the expression')." ==\n";
foreach ($rows as $r) {
    if (! $all && ! ($r['owned'] !== '' && $r['shop_id'] === 'NO')) {
        continue;
    }
    echo implode("\t", [$r['file'].':'.$r['line'], $r['kind'], $r['op'], $r['table'], $r['owned'] ?: '-', $r['request'], $r['src']]), "\n";
}
