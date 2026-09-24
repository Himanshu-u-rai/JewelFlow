<?php

/**
 * Tenant-isolation inventory — every query the BelongsToShop scope does NOT
 * govern, classified per expression, with where its shop constraint comes from.
 *
 *   php tests/Inventory/tenant_query_sweep.php [--all]
 *
 * Read-only: parses app/ and reads the schema of jewelflow_testing (refuses
 * any other database). Nothing is executed against application data. Exits 3
 * if any file failed to parse — those files were not inventoried.
 *
 * Forms searched (each is reported with its count; a count of 0 is printed as
 * ABSENT, so an absence is a finding, not an omission):
 *   raw        DB::table / DB::select|insert|update|delete|statement|… (facade
 *              imported, aliased or global)
 *   conn       connection-level calls: DB::connection()->…, app('db')->…,
 *              $model->getConnection()->…, and injected receivers named
 *              $db / $conn / $connection
 *   unscoped   static chains on a model WITHOUT BelongsToShop — names resolved
 *              through `use … as …` aliases and namespaces; static:: / self::
 *              inside a model resolve to that model (event and observer
 *              registrations — static::updating(…), Model::observe() — are
 *              not queries and are excluded)
 *   instance   $model->newQuery() / newQueryWithoutRelationships() on an
 *              instance (the scope applies; listed when the model is unknown)
 *   removed    withoutTenant(), withoutGlobalScope(s)(), newQueryWithoutScopes(),
 *              newModelQuery()
 *   embedded   a tenant table named inside another query: join/leftJoin/…,
 *              ->from('t'), and raw fragments (whereRaw/selectRaw/DB::raw/…)
 *              that select from or join a tenant table
 *
 * Tenant ownership comes from the schema: a table with shop_id, or one that
 * reaches a shop_id table through foreign keys — any number of levels, the
 * chain is printed (via:parent>grandparent).
 *
 * A `shop_id` mention is not a constraint. Each expression's constraints are
 * found — where/insert/raw/colEq on shop_id, key (an id or *_id column,
 * find(), whereKey()), token (a token/slug/uuid/hash/code column) — and each
 * value is classified by where it comes from:
 *   context   TenantContext / the authenticated user (or its shop)
 *   record    a record's own shop or key ($invoice->shop_id, $model->id,
 *             a model's own $this->shop_id, a bound model parameter)
 *   REQUEST   request data — only from a parameter typed as an HTTP request
 *             (or request()), so a DTO named $request is not mistaken for one
 *   via>X     a parameter or DTO property, traced to what reaches it: callers
 *             matched by method name and receiver class (typed properties,
 *             promoted constructor parameters, typed parameters, traits), DTO
 *             properties through their constructor calls, $this->method()
 *             through its returns; up to three levels (deeper: `deep`)
 *   property  $this->… (set elsewhere) · loop a loop variable · other / ?
 * A row is trusted when a shop filter comes from context or record, or a key
 * does (a parent's id in hand), with no shop_id taken from request data and
 * no orWhere breaking the conjunction. `--trace=<method>` prints every call
 * site a parameter trace visits, so any via> classification can be checked.
 *
 * Rows to read: tenant-owned (or unknown table) and not trusted. Each row has
 * a stable key (file + method + expression); tests/Inventory/reviewed.tsv
 * records the verdict of every row that has been read, and rows without one
 * print UNREAD — an edited expression gets a new key and is read again.
 * Default output: rows to read, tenant-facing first, then admin and console.
 * --all prints every expression.
 */

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
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
// --trace=<method>: print every call site the parameter trace visits for that method.
$traceMethod = collect($argv)->first(fn ($a) => str_starts_with($a, '--trace='));
$traceMethod = $traceMethod ? substr($traceMethod, 8) : null;
$root = realpath(__DIR__.'/../..');

// ── schema: which tables are tenant-owned, and through what ───────────────
$shopTables = collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'shop_id'"))
    ->pluck('table_name')->flip()->all();
$parents = [];
foreach (DB::select("select distinct tc.table_name child, ccu.table_name parent from information_schema.table_constraints tc
    join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
    where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = current_schema()") as $fk) {
    $parents[$fk->child][] = $fk->parent;
}
$notOwned = ['shops', 'plans', 'platform_audit_logs'];
$chainTo = function (string $t, array $seen = []) use (&$chainTo, $shopTables, $parents, $notOwned): ?array {
    if (isset($shopTables[$t])) {
        return [];
    }
    if (in_array($t, $notOwned, true) || isset($seen[$t])) {
        return null;
    }
    $best = null;
    foreach ($parents[$t] ?? [] as $p) {
        $c = $chainTo($p, $seen + [$t => true]);
        if ($c !== null && ($best === null || count($c) + 1 < count($best))) {
            $best = array_merge([$p], $c);
        }
    }

    return $best;
};
$owned = function (string $t) use ($chainTo): string {
    if ($t === '?') {
        return '?';
    }
    $c = $chainTo($t);

    return $c === null ? '' : ($c === [] ? 'shop_id' : 'via:'.implode('>', $c));
};
$allTables = collect(DB::select("select table_name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE'"))->pluck('table_name');
$deepOwned = $allTables->filter(fn ($t) => count($chainTo($t) ?? []) >= 2)->mapWithKeys(fn ($t) => [$t => $owned($t)])->all();

// ── models: scoped or not, by fully qualified name ────────────────────────
$models = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Models')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $cls = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($f->getPathname(), strlen($root.'/app/')));
    if (! class_exists($cls) || ! is_subclass_of($cls, Illuminate\Database\Eloquent\Model::class) || (new ReflectionClass($cls))->isAbstract()) {
        continue;
    }
    $models[$cls] = [
        'scoped' => in_array(App\Models\Concerns\BelongsToShop::class, class_uses_recursive($cls), true),
        'table' => (new $cls)->getTable(),
    ];
}

// ── parse app/ ────────────────────────────────────────────────────────────
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$printer = new Standard;
$finder = new NodeFinder;
$print = fn (Node $n): string => preg_replace('/\s+/', ' ', $n instanceof Node\Expr ? $printer->prettyPrintExpr($n) : $printer->prettyPrint([$n]));
$writes = ['update', 'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'updateOrInsert', 'delete', 'forceDelete', 'increment',
    'decrement', 'incrementEach', 'decrementEach', 'create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'createOrFirst', 'truncate',
    'save', 'restore', 'destroy', 'createMany', 'saveMany', 'sync', 'attach', 'detach', 'record'];
// Static calls on a model that register behaviour rather than query.
$notQueries = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored', 'retrieved',
    'replicating', 'forceDeleting', 'forceDeleted', 'booted', 'boot', 'observe', 'addGlobalScope', 'resolveRelationUsing', 'unguard', 'reguard'];
$sqlMethods = ['select', 'selectOne', 'scalar', 'insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement', 'cursor', 'selectResultSets'];
$rawFragments = ['whereRaw', 'orWhereRaw', 'selectRaw', 'orderByRaw', 'havingRaw', 'orHavingRaw', 'groupByRaw', 'fromRaw'];
$joins = ['join', 'leftJoin', 'rightJoin', 'crossJoin', 'joinSub', 'leftJoinSub', 'rightJoinSub', 'joinWhere', 'leftJoinWhere', 'from', 'fromSub'];
$removals = ['withoutTenant', 'withoutGlobalScope', 'withoutGlobalScopes', 'newQueryWithoutScopes', 'newModelQuery'];
$connMethods = array_merge(['table'], $sqlMethods);
$isDb = fn ($name) => $name instanceof Node\Name && in_array($name->toString(), ['DB', 'Illuminate\Support\Facades\DB'], true);
$tablesInSql = fn (string $sql) => preg_match_all('/\b(?:from|join|into|update)\s+"?([a-z_][a-z0-9_]*)"?/i', $sql, $m) ? array_values(array_unique(array_map('strtolower', $m[1]))) : [];

$files = 0;
$parseFailures = [];
$rows = [];
$forms = array_fill_keys(['raw DB::table', 'raw DB::<sql>', 'conn DB::connection()->…', "conn app('db')->…", 'conn ->getConnection()->…',
    'conn injected $db/$conn', 'unscoped Model::…', 'unscoped static::/self:: in a model', 'model call resolved via import alias', 'instance ->newQuery()',
    'removed withoutTenant/withoutGlobalScope(s)', 'removed newQueryWithoutScopes/newModelQuery', 'embedded join', 'embedded ->from()',
    'embedded raw fragment'], 0);
$methodDefs = [];   // method => list of [class, param names]           (callee side of parameter tracing)
$callSites = [];    // method => list of [call, caller class, caller method, target class|null, value classifier]
$newSites = [];     // class => list of [new node, caller class, caller method, value classifier]
$pending = [];      // row index => constraint positions whose value is a parameter, resolved once every file is parsed

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $files++;
    $path = substr($f->getPathname(), strlen($root) + 1);
    try {
        $ast = $parser->parse(file_get_contents($f->getPathname()));
    } catch (Throwable $e) {
        $parseFailures[] = "{$path}: {$e->getMessage()}";
        continue;
    }
    $ast = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => true, 'preserveOriginalNames' => true])))->traverse($ast);
    $ast = (new NodeTraverser(new ParentConnectingVisitor))->traverse($ast);
    $area = str_starts_with($path, 'app/Console/') ? 'console' : (preg_match('#/(Admin|Platform)/#', $path) ? 'admin' : 'tenant');

    $up = function (Node $n, string ...$classes) {
        while ($n = $n->getAttribute('parent')) {
            foreach ($classes as $c) {
                if ($n instanceof $c) {
                    return $n;
                }
            }
        }

        return null;
    };
    $fnOf = fn (Node $n) => $up($n, Node\Stmt\ClassMethod::class, Node\Stmt\Function_::class, Node\Expr\Closure::class, Node\Expr\ArrowFunction::class);
    $methodName = function (Node $n) use ($up): string {
        $m = $up($n, Node\Stmt\ClassMethod::class, Node\Stmt\Function_::class);

        return $m ? $m->name->toString() : '-';
    };
    $classOf = function (Node $n) use ($up): ?string {
        $c = $up($n, Node\Stmt\Class_::class, Node\Stmt\Trait_::class);

        return $c && $c->namespacedName ? $c->namespacedName->toString() : null;
    };
    // Outermost method-call chain containing $node.
    $chainTop = function (Node $n): Node {
        while (($p = $n->getAttribute('parent')) && ($p instanceof Node\Expr\MethodCall || $p instanceof Node\Expr\NullsafeMethodCall) && $p->var === $n) {
            $n = $p;
        }

        return $n;
    };
    $rootOf = function (Node $n): Node {
        while ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) {
            $n = $n->var;
        }

        return $n;
    };

    // The declared class of a parameter visible at $at (closures included).
    $paramType = function (string $name, ?Node $at) use ($fnOf): ?string {
        for ($fn = $at ? $fnOf($at) : null; $fn !== null; $fn = $fnOf($fn)) {
            foreach ($fn->getParams() as $p) {
                if ($p->var instanceof Node\Expr\Variable && $p->var->name === $name) {
                    return $p->type instanceof Node\Name ? $p->type->toString() : null;
                }
            }
        }

        return null;
    };
    $isHttp = fn (?string $t) => $t !== null && is_a($t, Illuminate\Http\Request::class, true);
    // The declared class of $this->name: a typed property or a promoted constructor parameter.
    $propType = function (string $name, Node $at) use ($up): ?string {
        $cls = $up($at, Node\Stmt\Class_::class);
        foreach ($cls?->getProperties() ?? [] as $prop) {
            foreach ($prop->props as $pp) {
                if ($pp->name->toString() === $name && $prop->type instanceof Node\Name) {
                    return $prop->type->toString();
                }
            }
        }
        foreach ($cls?->getMethod('__construct')?->params ?? [] as $param) {
            if ($param->flags !== 0 && $param->var->name === $name && $param->type instanceof Node\Name) {
                return $param->type->toString();
            }
        }

        return null;
    };

    // Where a value comes from:
    //   context | record | param:$x | dto:Class::prop | property | loop | REQUEST | other | ?
    $valueClass = function (Node\Expr $v, ?Node $at, int $depth = 0) use (&$valueClass, $print, $finder, $fnOf, $up, $paramType, $isHttp): string {
        if ($v instanceof Node\Expr\Cast) {
            return $valueClass($v->expr, $at, $depth);
        }
        if ($v instanceof Node\Expr\BinaryOp\Coalesce) {
            return $valueClass($v->left, $at, $depth);
        }
        if ($v instanceof Node\Expr\Ternary) {
            // Both branches that carry a value (a null/constant branch carries none).
            $branches = array_filter([$v->if ?? $v->cond, $v->else], fn ($b) => ! ($b instanceof Node\Expr\ConstFetch || $b instanceof Node\Scalar));
            $classes = array_values(array_unique(array_map(fn ($b) => $valueClass($b, $at, $depth), $branches)));

            return $classes === [] ? 'other' : (count($classes) === 1 ? $classes[0] : 'mixed('.implode(',', $classes).')');
        }
        $src = $print($v);
        if (preg_match('/TenantContext::|auth\(\)->(user\(\)|id\(\))|Auth::(user|id)\(\)|->user\(\)\??->shop_id|currentShopId|tenantShopId|^request\(\)->user\(\)/', $src)) {
            return 'context';
        }
        if (preg_match('/^request\(/', $src)) {
            return 'REQUEST';
        }
        $root = $v;
        while ($root instanceof Node\Expr\PropertyFetch || $root instanceof Node\Expr\NullsafePropertyFetch || $root instanceof Node\Expr\MethodCall
            || $root instanceof Node\Expr\NullsafeMethodCall || $root instanceof Node\Expr\ArrayDimFetch) {
            $root = $root->var;
        }
        if ($root !== $v && $root instanceof Node\Expr\Variable && is_string($root->name)) {
            if ($root->name === 'this' && $v instanceof Node\Expr\PropertyFetch && $v->var === $root && $v->name instanceof Node\Identifier
                && preg_match('/^(id|shop_id|[a-z0-9_]+_id)$/', $v->name->toString())
                && ($cls = $up($v, Node\Stmt\Class_::class)) && $cls->namespacedName
                && is_subclass_of($cls->namespacedName->toString(), Illuminate\Database\Eloquent\Model::class)) {
                return 'record';   // a model's own key: the record itself
            }
            if ($root->name === 'this') {
                // $this->method(…): what that method returns, same class.
                if ($v instanceof Node\Expr\MethodCall && $v->var === $root && $v->name instanceof Node\Identifier && $depth < 3
                    && ($cls = $up($v, Node\Stmt\Class_::class, Node\Stmt\Trait_::class)) && ($m = $cls->getMethod($v->name->toString())) && $m->stmts) {
                    $rets = array_filter($finder->findInstanceOf($m->stmts, Node\Stmt\Return_::class), fn ($r) => $r->expr !== null);
                    $classes = array_values(array_unique(array_map(fn ($r) => $valueClass($r->expr, $r, $depth + 1), $rets)));

                    return $classes === [] ? 'other' : (count($classes) === 1 ? $classes[0] : 'mixed('.implode(',', $classes).')');
                }

                return 'property';
            }
            $type = $paramType($root->name, $at);
            if ($isHttp($type)) {
                return preg_match('/^\$'.$root->name.'->user\(\)/', $src) ? 'context' : 'REQUEST';
            }
            $last = ($v instanceof Node\Expr\PropertyFetch || $v instanceof Node\Expr\NullsafePropertyFetch || $v instanceof Node\Expr\MethodCall
                || $v instanceof Node\Expr\NullsafeMethodCall) && $v->name instanceof Node\Identifier ? $v->name->toString() : null;
            if ($type !== null && $last !== null && $v->var === $root && ($v instanceof Node\Expr\PropertyFetch) && class_exists($type)
                && ! is_subclass_of($type, Illuminate\Database\Eloquent\Model::class)) {
                return 'dto:'.$type.'::'.$last;   // resolved from its constructor calls, after parsing
            }
            if ($depth < 3 && ($v instanceof Node\Expr\ArrayDimFetch || ($last !== null && preg_match('/^(id|shop_id|[a-z0-9_]+_id|shop|getKey|pluck|modelKeys|keys)$/', $last)))) {
                // A key (or the shop) of something in hand: a record, unless the
                // thing itself is request data; the caller's own shop stays context.
                $rc = $valueClass($root, $at, $depth + 1);
                if (str_contains($rc, 'REQUEST')) {
                    return 'REQUEST';
                }

                return $v instanceof Node\Expr\ArrayDimFetch || $rc === 'context' ? $rc : 'record';
            }

            return 'other';
        }
        if (! $v instanceof Node\Expr\Variable || ! is_string($v->name) || $depth >= 3 || $at === null) {
            return $v instanceof Node\Expr\Variable ? '?' : 'other';
        }
        if ($isHttp($paramType($v->name, $at))) {
            return 'REQUEST';
        }
        // Walk out through closures/arrow functions that capture the variable.
        for ($fn = $fnOf($at); $fn !== null; $fn = $fnOf($fn)) {
            foreach ($fn->getParams() as $param) {
                if ($param->var instanceof Node\Expr\Variable && $param->var->name === $v->name) {
                    if ($param->type instanceof Node\Name && is_subclass_of($param->type->toString(), Illuminate\Database\Eloquent\Model::class)) {
                        return 'record';   // a bound or passed model instance
                    }

                    return $fn instanceof Node\Stmt\ClassMethod || $fn instanceof Node\Stmt\Function_ ? 'param:'.$v->name : '?';
                }
            }
            $assigns = $finder->find($fn->getStmts() ?? [], fn (Node $n) => $n instanceof Node\Expr\Assign
                && (($n->var instanceof Node\Expr\Variable && $n->var->name === $v->name)
                    || ($n->var instanceof Node\Expr\Array_ || $n->var instanceof Node\Expr\List_) && str_contains($print($n->var), '$'.$v->name)));
            if ($assigns !== []) {
                $classes = array_values(array_unique(array_map(fn ($a) => $valueClass($a->expr, $a, $depth + 1), $assigns)));

                return count($classes) === 1 ? $classes[0] : 'mixed('.implode(',', $classes).')';
            }
            if ($finder->find($fn->getStmts() ?? [], fn (Node $n) => $n instanceof Node\Stmt\Foreach_
                && $n->valueVar instanceof Node\Expr\Variable && $n->valueVar->name === $v->name) !== []) {
                return 'loop';
            }
            $captured = $fn instanceof Node\Expr\ArrowFunction
                || ($fn instanceof Node\Expr\Closure && collect($fn->uses)->contains(fn ($u) => $u->var->name === $v->name));
            if (! $captured) {
                return '?';
            }
        }

        return '?';
    };

    // Every constraint in one expression: [kind, value class, is-or]. Kinds:
    // where/insert/raw/colEq on shop_id; key — an id or *_id column, find(),
    // whereKey(); token — a token/slug/uuid/hash/code column.
    $constraintsOf = function (Node $top, bool $isWrite) use ($finder, $print, $valueClass): array {
        $found = [];
        $col = fn ($e) => $e instanceof Node\Scalar\String_ ? $e->value : null;
        $kindOf = fn (?string $c) => match (true) {
            $c === null => null,
            (bool) preg_match('/(^|\.)shop_id$/', $c) => 'where',
            (bool) preg_match('/(^|\.)(id|[a-z0-9_]+_id)$/', $c) => 'key',
            (bool) preg_match('/(token|slug|uuid|hash|code|signature)$/', $c) => 'token',
            default => null,
        };
        foreach ($finder->find($top, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\NullsafeMethodCall)
            && $n->name instanceof Node\Identifier) as $call) {
            $name = $call->name->toString();
            $or = (bool) preg_match('/^or[A-Z]/', $name);
            $args = array_values(array_filter($call->args, fn ($a) => $a instanceof Node\Arg));
            $a0 = $args[0]->value ?? null;
            if (preg_match('/^(or)?(where|whereIn|whereNot|whereNotIn|firstWhere)$/i', $name) && ($k = $kindOf($col($a0)))) {
                $val = $args[count($args) === 2 ? 1 : 2]->value ?? null;
                $found[] = [$k, $val ? $valueClass($val, $call) : '?', $or || str_contains(strtolower($name), 'not')];
            } elseif (preg_match('/^(or)?where$/i', $name) && $a0 instanceof Node\Expr\Array_) {
                foreach ($a0->items as $item) {
                    if ($item && ($k = $kindOf($col($item->key)))) {
                        $found[] = [$k, $valueClass($item->value, $call), $or];
                    }
                }
            } elseif (preg_match('/^(or)?whereShopId$/i', $name) && $a0) {
                $found[] = ['where', $valueClass($a0, $call), $or];
            } elseif (in_array($name, ['find', 'findOrFail', 'findMany', 'findOrNew', 'whereKey', 'sole'], true) && $a0 && ! $a0 instanceof Node\Expr\Closure) {
                $found[] = ['key', $valueClass($a0, $call), false];
            } elseif (preg_match('/^(or)?whereColumn$/i', $name) && str_contains($print($call), 'shop_id')) {
                $found[] = ['colEq', '-', $or];
            } elseif (in_array($name, ['whereRaw', 'orWhereRaw', 'select', 'selectOne', 'statement', 'update', 'delete', 'insert', 'scalar', 'cursor'], true)
                && ($a0 instanceof Node\Scalar\String_ || $a0 instanceof Node\Expr\BinaryOp\Concat || $a0 instanceof Node\Scalar\InterpolatedString)
                && preg_match('/shop_id\s*(=|in\b)/i', $print($a0))) {
                $bindings = $args[1]->value ?? null;
                $classes = $bindings instanceof Node\Expr\Array_
                    ? array_values(array_unique(array_map(fn ($i) => $valueClass($i->value, $call), array_filter($bindings->items))))
                    : ['?'];
                $found[] = ['raw', count($classes) === 1 ? $classes[0] : 'mixed('.implode(',', $classes).')', $or];
            }
        }
        if ($isWrite) {
            foreach ($finder->find($top, fn (Node $n) => $n instanceof Node\ArrayItem && $n->key instanceof Node\Scalar\String_ && $n->key->value === 'shop_id') as $item) {
                $found[] = ['insert', $valueClass($item->value, $item), false];
            }
        }

        return $found;
    };

    $record = function (string $kind, string $form, string $table, Node $at, ?Node $frag = null) use (&$rows, &$forms, &$pending, $path, $area, $chainTop, $print, $owned, $writes, $constraintsOf, $methodName, $classOf, $finder, $valueClass): void {
        $forms[$form]++;
        $top = $chainTop($at);
        $src = $print($top);
        $methods = [];
        for ($n = $top; $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall || $n instanceof Node\Expr\StaticCall; $n = $n->var ?? null) {
            if ($n->name instanceof Node\Identifier) {
                $methods[] = $n->name->toString();
            }
            if ($n instanceof Node\Expr\StaticCall) {
                break;
            }
        }
        $sqlWrite = $kind !== 'embedded' && (bool) preg_match('/^\s*[\'"]?\s*(insert|update|delete|truncate|alter|create|drop)\b/i',
            $print($at->args[0]->value ?? new Node\Scalar\String_('')));
        $isWrite = $kind !== 'embedded' && ((bool) array_intersect($methods, $writes) || $sqlWrite);
        if ($kind === 'embedded') {
            // A joined table is constrained by a filter on its own shop_id in the
            // same chain (by table name or alias), or by its join condition.
            $a0 = $at->args[0]->value ?? null;
            $alias = $a0 instanceof Node\Scalar\String_ && preg_match('/\s+as\s+(\w+)/i', $a0->value, $m) ? $m[1] : $table;
            $filters = $finder->find($top, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall)
                && $n->name instanceof Node\Identifier && preg_match('/^where(In)?$/', $n->name->toString())
                && ($n->args[0]->value ?? null) instanceof Node\Scalar\String_
                && in_array($n->args[0]->value->value, ["{$alias}.shop_id", "{$table}.shop_id"], true));
            $constraints = $filters !== []
                ? array_map(fn ($f) => ['where', $valueClass($f->args[count($f->args) === 2 ? 1 : 2]->value, $f), false], $filters)
                : (str_contains($frag === $at && $frag instanceof Node\Expr\MethodCall
                    // a join: only its own arguments — printing the call would print the whole chain before it
                    ? implode(' ', array_map(fn ($a) => $a instanceof Node\Arg ? $print($a->value) : '', $frag->args))
                    : $print($frag ?? $at), 'shop_id') ? [['frag', 'shop_id', false]] : []);
        } else {
            $constraints = $constraintsOf($top, $isWrite);
        }
        $rows[] = [
            'file' => $path, 'line' => $at->getStartLine(), 'area' => $area, 'kind' => $kind, 'form' => $form, 'table' => $table,
            'owned' => $owned($table), 'op' => $isWrite ? 'WRITE' : 'read', 'constraints' => $constraints,
            'or' => (bool) array_filter($methods, fn ($m) => preg_match('/^or[A-Z]/', $m)),
            'mention' => str_contains($src, 'shop_id'),
            'request' => preg_match('/\$request\b|request\(\)|\$validated\b|->input\(|->validated\(\)|\$payload\b/', $src) ? 'request' : '',
            'key' => substr(sha1($path.'|'.$methodName($at).'|'.$kind.'|'.$table.'|'.$src), 0, 10),
            'src' => mb_substr($src, 0, 260),
        ];
        foreach ($constraints as $i => $c) {
            if (preg_match('/(^|[(,])(param:|dto:)/', $c[1])) {
                $pending[count($rows) - 1][$i] = [$classOf($at), $methodName($at), $c[1]];
            }
        }
    };

    // Method definitions and call sites, for tracing parameters to their callers.
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $m) {
        $methodDefs[$m->name->toString()][] = [$classOf($m->name) ?? '?', array_map(fn ($p) => $p->var->name ?? null, $m->params), $m->isStatic()];
    }
    foreach ($finder->find($ast, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\NullsafeMethodCall)
        && $n->name instanceof Node\Identifier) as $call) {
        $caller = $classOf($call);
        $recv = $call instanceof Node\Expr\StaticCall ? $call->class : $call->var;
        $target = match (true) {
            $recv instanceof Node\Name => $recv->isSpecialClassName() ? $caller : $recv->toString(),
            $recv instanceof Node\Expr\Variable && $recv->name === 'this' => $caller,
            $recv instanceof Node\Expr\Variable && is_string($recv->name) => $paramType($recv->name, $call),
            $recv instanceof Node\Expr\PropertyFetch && $recv->var instanceof Node\Expr\Variable && $recv->var->name === 'this'
                && $recv->name instanceof Node\Identifier => $propType($recv->name->toString(), $call),
            $recv instanceof Node\Expr\New_ && $recv->class instanceof Node\Name => $recv->class->toString(),
            $recv instanceof Node\Expr\FuncCall && $recv->name instanceof Node\Name && in_array($recv->name->toString(), ['app', 'resolve'], true)
                && ($recv->args[0]->value ?? null) instanceof Node\Expr\ClassConstFetch && $recv->args[0]->value->class instanceof Node\Name
                => $recv->args[0]->value->class->toString(),
            default => null,
        };
        $callSites[$call->name->toString()][] = [$call, $caller, $methodName($call), $target, $valueClass];
    }
    foreach ($finder->find($ast, fn (Node $n) => $n instanceof Node\Expr\New_ && $n->class instanceof Node\Name) as $new) {
        $newSites[$new->class->toString()][] = [$new, $classOf($new), $methodName($new), $valueClass];
    }

    foreach ($finder->find($ast, fn (Node $n) => $n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier) as $call) {
        $method = $call->name->toString();
        $a0 = $call->args[0]->value ?? null;
        if ($isDb($call->class)) {
            if ($method === 'table') {
                $record('raw', 'raw DB::table', $a0 instanceof Node\Scalar\String_ ? explode(' ', $a0->value)[0] : '?', $call);
            } elseif (in_array($method, $sqlMethods, true)) {
                $t = $a0 ? $tablesInSql($print($a0)) : [];
                $record('raw', 'raw DB::<sql>', $t[0] ?? '?', $call);
            } elseif ($method === 'raw' && $a0) {
                foreach ($tablesInSql($print($a0)) as $t) {
                    if ($owned($t) !== '') {
                        $record('embedded', 'embedded raw fragment', $t, $call, $a0);
                    }
                }
            }
            continue;
        }
        if (! $call->class instanceof Node\Name) {
            continue;
        }
        $special = $call->class->isSpecialClassName();
        $cls = $special ? $classOf($call) : $call->class->toString();
        $original = $call->class->getAttribute('originalName');
        if (! $special && isset($models[$cls]) && $original && $original->getLast() !== class_basename($cls)) {
            $forms['model call resolved via import alias']++;
        }
        if (in_array($method, $removals, true)) {
            $record('removed', 'removed withoutTenant/withoutGlobalScope(s)', $models[$cls]['table'] ?? '?', $call);
        } elseif ($cls !== null && isset($models[$cls]) && ! $models[$cls]['scoped'] && ! in_array($method, $notQueries, true)) {
            $record('unscoped', $special ? 'unscoped static::/self:: in a model' : 'unscoped Model::…', $models[$cls]['table'], $call);
        }
    }

    foreach ($finder->find($ast, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) && $n->name instanceof Node\Identifier) as $call) {
        $method = $call->name->toString();
        $a0 = $call->args[0]->value ?? null;
        $recv = $call->var;
        $base = $rootOf($recv);
        $modelOf = function (Node $base) use ($models, $call, $finder, $fnOf): string {
            if ($base instanceof Node\Expr\New_ && $base->class instanceof Node\Name) {
                return $base->class->toString();
            }
            if ($base instanceof Node\Expr\Variable && is_string($base->name) && ($fn = $fnOf($call))) {
                foreach ($fn->getParams() as $p) {
                    if ($p->var->name === $base->name && $p->type instanceof Node\Name) {
                        return $p->type->toString();
                    }
                }
            }

            return '?';
        };
        if (in_array($method, $removals, true) && ! ($base instanceof Node\Expr\StaticCall)) {
            $m = $modelOf($base);
            $record('removed', in_array($method, ['newQueryWithoutScopes', 'newModelQuery'], true) ? 'removed newQueryWithoutScopes/newModelQuery' : 'removed withoutTenant/withoutGlobalScope(s)',
                $models[$m]['table'] ?? '?', $call);
        } elseif (in_array($method, ['newQuery', 'newQueryWithoutRelationships'], true)) {
            $m = $modelOf($base);
            if (! isset($models[$m]) || ! $models[$m]['scoped']) {
                $record('instance', 'instance ->newQuery()', $models[$m]['table'] ?? '?', $call);
            } else {
                $forms['instance ->newQuery()']++;   // scoped model: counted, not listed
            }
        } elseif (in_array($method, $connMethods, true) && (
            ($recv instanceof Node\Expr\StaticCall && $isDb($recv->class) && $recv->name instanceof Node\Identifier && $recv->name->toString() === 'connection')
            || ($recv instanceof Node\Expr\FuncCall && $recv->name instanceof Node\Name && in_array($recv->name->toString(), ['app', 'resolve'], true) && str_contains($print($recv), "'db'"))
            || ($recv instanceof Node\Expr\MethodCall && $recv->name instanceof Node\Identifier && in_array($recv->name->toString(), ['getConnection', 'connection'], true))
            || (($recv instanceof Node\Expr\Variable && is_string($recv->name) && preg_match('/^(db|conn|connection)$/i', $recv->name))
                || ($recv instanceof Node\Expr\PropertyFetch && $recv->name instanceof Node\Identifier && preg_match('/^(db|conn|connection)$/i', $recv->name->toString()))))) {
            if ($method !== 'table' && ! ($a0 && preg_match('/^\s*[\'"]?\s*(select|insert|update|delete|with)\b/i', $print($a0)))) {
                continue;   // a builder's select(columns), not SQL
            }
            $form = match (true) {
                $recv instanceof Node\Expr\StaticCall => 'conn DB::connection()->…',
                $recv instanceof Node\Expr\FuncCall => "conn app('db')->…",
                $recv instanceof Node\Expr\MethodCall => 'conn ->getConnection()->…',
                default => 'conn injected $db/$conn',
            };
            $t = $method === 'table' ? ($a0 instanceof Node\Scalar\String_ ? explode(' ', $a0->value)[0] : '?') : ($tablesInSql($print($a0))[0] ?? '?');
            $record('conn', $form, $t, $call);
        } elseif (in_array($method, $joins, true) && $a0 instanceof Node\Scalar\String_) {
            $t = strtolower(explode(' ', trim($a0->value))[0]);
            if ($owned($t) !== '') {
                $record('embedded', in_array($method, ['from', 'fromSub'], true) ? 'embedded ->from()' : 'embedded join', $t, $call,
                    $method === 'from' ? $chainTop($call) : $call);
            }
        } elseif (in_array($method, $rawFragments, true) && $a0) {
            foreach ($tablesInSql($print($a0)) as $t) {
                if ($owned($t) !== '') {
                    $record('embedded', 'embedded raw fragment', $t, $call, $a0);
                }
            }
        }
    }
}

// ── parameters: what callers pass, traced up to three levels ─────────────
// Call sites are matched by method name and, where the receiver's class is
// known, by class; an unknown receiver matches any class.
$resolve = null;
$trace = function (?string $class, string $method, string $param, int $depth = 0) use (&$trace, &$resolve, $methodDefs, $callSites, $traceMethod, $print): array {
    $out = [];
    foreach ($methodDefs[$method] ?? [] as [$defClass, $params, $isStatic]) {
        if ($class !== null && $defClass !== $class) {
            continue;
        }
        $pos = array_search($param, $params, true);
        if ($pos === false) {
            continue;
        }
        foreach ($callSites[$method] ?? [] as [$call, $callerClass, $callerMethod, $target, $valueClass]) {
            if ($isStatic && $target === null && ! $call instanceof Node\Expr\StaticCall) {
                continue;   // a static method is not reached through an untyped instance call
            }
            if ($target !== null && $target !== $defClass && ! is_subclass_of($defClass, $target) && ! is_subclass_of($target, $defClass)
                && ! (class_exists($target) && in_array($defClass, class_uses_recursive($target), true))) {
                continue;
            }
            $args = array_values(array_filter($call->args, fn ($a) => $a instanceof Node\Arg));
            $arg = collect($args)->first(fn ($a) => $a->name?->toString() === $param) ?? ($args[$pos] ?? null);
            if ($arg !== null) {
                $got = $resolve($valueClass($arg->value, $call), $callerClass, $callerMethod, $depth + 1);
                if ($traceMethod === $method) {
                    fwrite(STDERR, sprintf("trace %s::%s(\$%s) <- %s::%s line %d: %s => %s\n", $defClass, $method, $param, $callerClass ?? '?', $callerMethod,
                        $call->getStartLine(), $print($arg->value), implode(',', $got)));
                }
                $out = array_merge($out, $got);
            }
        }
    }

    return array_values(array_unique($out));
};
// A DTO property: what its constructor calls pass for it.
$dto = function (string $class, string $prop, int $depth) use (&$resolve, $newSites): array {
    $ctor = class_exists($class) ? (new ReflectionClass($class))->getConstructor() : null;
    $names = $ctor ? array_map(fn ($p) => $p->getName(), $ctor->getParameters()) : [];
    $pos = array_search($prop, $names, true);
    $out = [];
    foreach ($newSites[$class] ?? [] as [$new, $callerClass, $callerMethod, $valueClass]) {
        $args = array_values(array_filter($new->args, fn ($a) => $a instanceof Node\Arg));
        $arg = collect($args)->first(fn ($a) => $a->name?->toString() === $prop) ?? ($pos === false ? null : ($args[$pos] ?? null));
        if ($arg !== null) {
            $out = array_merge($out, $resolve($valueClass($arg->value, $new), $callerClass, $callerMethod, $depth + 1));
        }
    }

    return array_values(array_unique($out));
};
// A value class with its param:/dto: parts replaced by what reaches them (three levels).
$resolve = function (string $c, ?string $class, string $method, int $depth) use (&$trace, $dto): array {
    $parts = preg_match('/^mixed\((.*)\)$/', $c, $m) ? explode(',', $m[1]) : [$c];
    $out = [];
    foreach ($parts as $part) {
        if ($depth > 3 && preg_match('/^(param|dto):/', $part)) {
            $out[] = 'deep';
        } elseif (str_starts_with($part, 'param:')) {
            $found = $trace($class, $method, substr($part, 6), $depth);
            $out = array_merge($out, $found === [] ? ['?'] : $found);
        } elseif (str_starts_with($part, 'dto:')) {
            [$cls, $prop] = explode('::', substr($part, 4), 2);
            $found = $dto($cls, $prop, $depth);
            $out = array_merge($out, $found === [] ? ['?'] : $found);
        } else {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
};
foreach ($pending as $i => $positions) {
    foreach ($positions as $j => [$class, $method, $value]) {
        $classes = $resolve($value, $class, $method, 0);
        $rows[$i]['constraints'][$j][1] = 'via>'.(count($classes) === 1 ? $classes[0] : 'mixed('.implode(',', $classes).')');
    }
}

$trustedFrom = function (string $from): bool {
    $from = preg_replace('/^via>/', '', $from);
    $parts = preg_match('/^mixed\((.*)\)$/', $from, $m) ? explode(',', $m[1]) : [$from];

    return array_diff($parts, ['context', 'record']) === [];
};
foreach ($rows as &$r) {
    $cs = $r['constraints'];
    $shopKinds = ['where', 'insert', 'raw'];
    // A shop_id taken from request data is never excused by another filter.
    $badShop = array_filter($cs, fn ($c) => in_array($c[0], $shopKinds, true) && str_contains($c[1], 'REQUEST'));
    $goodShop = array_filter($cs, fn ($c) => in_array($c[0], $shopKinds, true) && ! $c[2] && $trustedFrom($c[1]));
    // An id or token from the request is normal beside a trusted filter — a shop
    // filter, or a key taken from a record in hand (a parent's id) — and the
    // cross-shop lookup pattern without one.
    $requestKey = array_filter($cs, fn ($c) => in_array($c[0], ['key', 'token'], true) && str_contains($c[1], 'REQUEST'));
    $goodKey = array_filter($cs, fn ($c) => $c[0] === 'key' && ! $c[2] && $trustedFrom($c[1]));
    // An orWhere in the chain breaks the conjunction.
    $r['trusted'] = $badShop === [] && ! $r['or'] && ($goodShop !== [] || $goodKey !== []);
    $show = $badShop ? reset($badShop) : ($goodShop ? reset($goodShop) : ($requestKey ? reset($requestKey) : ($goodKey ? reset($goodKey) : ($cs ? reset($cs) : null))));
    $r['shop'] = $show ? ($show[2] ? 'or' : '').$show[0] : ($r['mention'] ? 'mention' : 'none');
    $r['from'] = $show ? $show[1] : '-';
    $r['shop'] .= $r['or'] && $show && ! $show[2] ? '+or' : '';
}
unset($r);

// One row per expression: a chain found from two roots keeps its first.
$seen = [];
$rows = array_values(array_filter($rows, function ($r) use (&$seen) {
    $k = $r['file'].':'.$r['line'].':'.$r['kind'].':'.$r['table'];

    return ! isset($seen[$k]) && ($seen[$k] = true);
}));

// ── what has been read ────────────────────────────────────────────────────
$reviewed = [];
foreach (file(__DIR__.'/reviewed.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line !== '' && $line[0] !== '#') {
        [$k, $verdict, $note] = array_pad(explode("\t", $line, 3), 3, '');
        $reviewed[$k] = $verdict.($note !== '' ? ' — '.$note : '');
    }
}
$toRead = fn ($r) => $r['owned'] !== '' && ! $r['trusted'];

// ── report ────────────────────────────────────────────────────────────────
echo "== parse ==\nfiles parsed: {$files}; parse failures: ".count($parseFailures)."\n";
foreach ($parseFailures as $p) {
    echo "  NOT INVENTORIED: {$p}\n";
}

echo "\n== forms searched (occurrences; ABSENT = searched, none found) ==\n";
foreach ($forms as $form => $n) {
    printf("%6s  %s\n", $n === 0 ? 'ABSENT' : $n, $form);
}

echo "\n== ownership through parents (foreign keys) ==\n";
$viaOne = $allTables->filter(fn ($t) => count($chainTo($t) ?? []) === 1)->values()->all();
echo '  one level: '.count($viaOne).' — '.implode(', ', array_map(fn ($t) => "{$t} ".$owned($t), $viaOne))."\n";
echo '  two or more levels: '.($deepOwned === [] ? 'none in the schema' : implode(', ', array_map(fn ($t, $v) => "{$t} {$v}", array_keys($deepOwned), $deepOwned)))."\n";
$unowned = $allTables->filter(fn ($t) => $chainTo($t) === null)->sort()->values()->all();
echo '  no shop_id and no foreign-key path to one ('.count($unowned).'; ownership here, if any, is by convention — a polymorphic or '
    ."untyped column — and is not traced):\n    ".implode(', ', $unowned)."\n";

$summary = [];
foreach ($rows as $r) {
    $k = sprintf('%-8s %-6s %-5s owned=%-3s shop=%-7s from=%s', $r['area'], $r['kind'], $r['op'],
        $r['owned'] === '' ? 'no' : ($r['owned'] === '?' ? '?' : 'yes'), $r['shop'], $r['from']);
    $summary[$k] = ($summary[$k] ?? 0) + 1;
}
ksort($summary);
echo "\n== summary (expressions: ".count($rows).") ==\n";
foreach ($summary as $k => $n) {
    printf("%5d  %s\n", $n, $k);
}

$list = array_filter($rows, fn ($r) => $all || $toRead($r));
usort($list, fn ($a, $b) => [['tenant' => 0, 'admin' => 1, 'console' => 2][$a['area']], $a['op'] === 'WRITE' ? 0 : 1, $a['request'] === '' ? 1 : 0, $a['file'], $a['line']]
    <=> [['tenant' => 0, 'admin' => 1, 'console' => 2][$b['area']], $b['op'] === 'WRITE' ? 0 : 1, $b['request'] === '' ? 1 : 0, $b['file'], $b['line']]);
$status = ['read' => [], 'unread' => []];
foreach ($list as $r) {
    $status[isset($reviewed[$r['key']]) ? 'read' : 'unread'][$r['area']][] = 1;
}
echo "\n== ".($all ? 'all expressions' : 'rows to read')." — read / UNREAD by area ==\n";
foreach (['tenant', 'admin', 'console'] as $a) {
    printf("  %-8s read %4d   UNREAD %4d\n", $a, count($status['read'][$a] ?? []), count($status['unread'][$a] ?? []));
}
echo "\n";
foreach ($list as $r) {
    echo implode("\t", [$r['key'], $r['area'], $r['file'].':'.$r['line'], $r['kind'], $r['op'], $r['table'], $r['owned'] ?: '-',
        $r['shop'].':'.$r['from'], $r['request'], $reviewed[$r['key']] ?? 'UNREAD', $r['src']]), "\n";
}

exit($parseFailures === [] ? 0 : 3);
