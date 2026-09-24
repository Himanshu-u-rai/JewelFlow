<?php

/**
 * Tenant-isolation inventory — request data that carries a record id.
 *
 *   php tests/Inventory/request_id_sweep.php
 *
 * Read-only; parses app/Http, touches no database. Exits 3 if any file failed
 * to parse — those files were not inventoried.
 *
 * Part 1 — every id-bearing VALIDATION key (`*_id`, `*_ids`, `*_ids.*`,
 * `ids.*`, `lines.*.<x>_id`) and how ownership is enforced at validation:
 *   exists+shop   Rule::exists / closure / rule object that names shop_id
 *   exists+parent Rule::exists constrained by a parent the request owns
 *   exists-only   exists:table,col with no ownership constraint
 *   closure       a closure rule or custom rule object (read it)
 *   shape-only    integer / numeric / string / nullable — ownership, if any,
 *                 comes from the lookup that uses the id
 *
 * Part 2 — every READ of an id-like request field, wherever it happens:
 * input/get/query/post/integer/string/route/…('x_id'), $request->x_id,
 * request('x_id'), keys read by a variable name (dynamic), and whole-request
 * bags (all/except/collect) passed into a write. For each: whether the same
 * method validates that key (or the method's FormRequest does), and where the
 * value goes — a scoped model lookup, an unscoped one, a stored write, or
 * elsewhere (one assignment hop is followed).
 *
 * Validation is not the only place ownership can be enforced; this list says
 * where to look, it does not decide.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();   // class_uses for scoped models; no queries

$root = realpath(__DIR__.'/../..');
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$printer = new Standard;
$finder = new NodeFinder;
$print = fn (Node $n) => preg_replace('/\s+/', ' ', $printer->prettyPrintExpr($n));
$idKey = fn (string $k) => (bool) preg_match('/(^|\.|_)ids?(\.\*)?$|_id$/', $k);
$classify = fn (string $rule) => match (true) {
    (bool) preg_match('/Rule::exists|exists:|ExistsRule/', $rule) && str_contains($rule, 'shop') => 'exists+shop',
    (bool) preg_match('/Rule::exists\([^)]*\)\s*->\s*where\(\s*\'[a-z_]+_id\'/', $rule) => 'exists+parent',
    (bool) preg_match('/Rule::exists|exists:/', $rule) => 'exists-only',
    (bool) preg_match('/function\s*\(|fn\s*\(|new [A-Z\\\\]|::[a-z]+Rule\(/', $rule) => 'closure',
    default => 'shape-only',
};
$scoped = function (string $cls): ?bool {
    if (! class_exists($cls) || ! is_subclass_of($cls, Illuminate\Database\Eloquent\Model::class)) {
        return null;
    }

    return in_array(App\Models\Concerns\BelongsToShop::class, class_uses_recursive($cls), true);
};

$files = [];
$parseFailures = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Http')) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $path = substr($f->getPathname(), strlen($root) + 1);
    try {
        $ast = $parser->parse(file_get_contents($f->getPathname()));
    } catch (Throwable $e) {
        $parseFailures[] = "{$path}: {$e->getMessage()}";
        continue;
    }
    $ast = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => true])))->traverse($ast);
    $files[$path] = (new NodeTraverser(new ParentConnectingVisitor))->traverse($ast);
}

// ── Part 1: validation keys, and each method's / FormRequest's validated key set ─
$rules = [];          // [class, path:line, key, rule]
$validatedIn = [];    // "path|method" => [key => class]
$formRequestKeys = [];// FormRequest FQCN => [key => class]
foreach ($files as $path => $ast) {
    foreach ($finder->findInstanceOf($ast, Node\ArrayItem::class) as $item) {
        if (! $item->key instanceof Node\Scalar\String_) {
            continue;
        }
        $key = $item->key->value;
        $rule = $print($item->value);
        if (! preg_match('/required|nullable|sometimes|integer|numeric|exists|Rule::|string|array|uuid|filled|present|max:|min:/', $rule)) {
            continue;
        }
        $class = $classify($rule);
        $m = $item;
        while (($m = $m->getAttribute('parent')) && ! $m instanceof Node\Stmt\ClassMethod) {
        }
        $c = $m;
        while ($c && ($c = $c->getAttribute('parent')) && ! $c instanceof Node\Stmt\Class_) {
        }
        $base = preg_replace('/\.\*.*$|\..*$/', '', $key);
        if ($m) {
            $validatedIn[$path.'|'.$m->name->toString()][$key] = $class;
            $validatedIn[$path.'|'.$m->name->toString()][$base] ??= $class;
        }
        if ($c && $c->namespacedName && is_subclass_of($c->namespacedName->toString(), Illuminate\Foundation\Http\FormRequest::class)) {
            $formRequestKeys[$c->namespacedName->toString()][$key] = $class;
            $formRequestKeys[$c->namespacedName->toString()][$base] ??= $class;
        }
        if ($idKey($key)) {
            $rules[] = [$class, $path.':'.$item->getStartLine(), $key, mb_substr($rule, 0, 180)];
        }
    }
}

// ── Part 2: request reads of id-like keys, and where they go ──────────────
$reads = [];
$inputMethods = ['input', 'get', 'query', 'post', 'integer', 'string', 'float', 'boolean', 'route', 'header', 'cookie', 'json', 'date', 'enum'];
$bagMethods = ['all', 'except', 'collect', 'input'];   // input() with no key is the whole bag
$writeMethods = ['create', 'update', 'fill', 'forceFill', 'forceCreate', 'insert', 'insertGetId', 'updateOrCreate', 'firstOrCreate', 'upsert', 'make'];
foreach ($files as $path => $ast) {
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        $httpVars = [];
        $formRequest = null;
        foreach ($method->params as $p) {
            if ($p->type instanceof Node\Name && is_a($p->type->toString(), Illuminate\Http\Request::class, true)) {
                $httpVars[] = $p->var->name;
                if (is_subclass_of($p->type->toString(), Illuminate\Foundation\Http\FormRequest::class)) {
                    $formRequest = $p->type->toString();
                }
            }
        }
        $cls = $method->getAttribute('parent');
        $inFormRequest = $cls instanceof Node\Stmt\Class_ && $cls->namespacedName
            && is_subclass_of($cls->namespacedName->toString(), Illuminate\Foundation\Http\FormRequest::class);
        $isHttp = fn (Node $e) => ($e instanceof Node\Expr\Variable && in_array($e->name, $httpVars, true))
            || ($e instanceof Node\Expr\FuncCall && $e->name instanceof Node\Name && $e->name->toString() === 'request' && $e->args === [])
            || ($inFormRequest && $e instanceof Node\Expr\Variable && $e->name === 'this');
        $validated = ($validatedIn[$path.'|'.$method->name->toString()] ?? []) + ($formRequest ? ($formRequestKeys[$formRequest] ?? []) : [])
            + ($inFormRequest ? ($formRequestKeys[$cls->namespacedName->toString()] ?? []) : []);

        // Where a value goes: the call it is an argument of (one assignment hop).
        $sinkOf = function (Node $n) use (&$sinkOf, $finder, $method, $print, $scoped, $writeMethods): string {
            for ($p = $n->getAttribute('parent'); $p !== null; $p = $p->getAttribute('parent')) {
                if ($p instanceof Node\Expr\Assign && $p->var instanceof Node\Expr\Variable && is_string($p->var->name) && $p->expr !== null
                    && ($p->expr === $n || in_array($n, $finder->find($p->expr, fn ($x) => $x === $n), true))) {
                    $uses = $finder->find($method->stmts ?? [], fn (Node $x) => $x instanceof Node\Expr\Variable && $x->name === $p->var->name
                        && $x !== $p->var && $x->getStartLine() >= $p->getStartLine());
                    $sinks = array_values(array_unique(array_map(fn ($u) => $sinkOf($u, 1), array_slice($uses, 0, 8))));

                    return $sinks === [] ? 'unused' : implode('|', $sinks);
                }
                if ($p instanceof Node\ArrayItem && ($call = $p->getAttribute('parent')?->getAttribute('parent')?->getAttribute('parent'))
                    && ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier
                    && in_array($call->name->toString(), $writeMethods, true)) {
                    return 'STORED';
                }
                if (($p instanceof Node\Expr\MethodCall || $p instanceof Node\Expr\StaticCall) && $p->name instanceof Node\Identifier) {
                    $name = $p->name->toString();
                    if (! preg_match('/^(find|findOrFail|findMany|where|whereIn|whereKey|firstWhere|firstOrFail|lockActiveOrFail)/', $name)) {
                        continue;
                    }
                    $r = $p;
                    while ($r instanceof Node\Expr\MethodCall) {
                        $r = $r->var;
                    }
                    if ($r instanceof Node\Expr\StaticCall && $r->class instanceof Node\Name) {
                        $c = $r->class->toString();
                        if (in_array($c, ['DB', 'Illuminate\Support\Facades\DB'], true)) {
                            return 'UNSCOPED:DB';
                        }
                        $chain = $print($p);
                        $s = $scoped($c);
                        if (str_contains($chain, 'withoutTenant') || str_contains($chain, 'withoutGlobalScope')) {
                            return str_contains($chain, 'shop_id') ? 'unscoped+shop' : 'UNSCOPED:'.class_basename($c);
                        }

                        return $s === true ? 'scoped' : ($s === false ? (str_contains($chain, 'shop_id') ? 'unscoped+shop' : 'UNSCOPED:'.class_basename($c)) : 'lookup');
                    }

                    return 'relation/other-lookup';
                }
            }

            return 'other';
        };

        foreach ($finder->find($method->stmts ?? [], fn (Node $n) => true) as $n) {
            $key = null;
            $dynamic = false;
            if (($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) && $n->name instanceof Node\Identifier && $isHttp($n->var)) {
                $m = $n->name->toString();
                $a0 = $n->args[0]->value ?? null;
                if (in_array($m, $inputMethods, true) && $a0 !== null) {
                    [$key, $dynamic] = $a0 instanceof Node\Scalar\String_ ? [$a0->value, false] : [$print($a0), true];
                } elseif (in_array($m, $bagMethods, true) && ($m !== 'input' || $a0 === null)) {
                    $sink = $sinkOf($n);
                    if (str_contains($sink, 'STORED') || str_starts_with($sink, 'UNSCOPED')) {
                        $reads[] = ['BAG', $path.':'.$n->getStartLine(), $m.'()', '-', $sink, mb_substr($print($n->getAttribute('parent') ?? $n), 0, 140)];
                    }
                    continue;
                }
            } elseif ($n instanceof Node\Expr\PropertyFetch && $n->name instanceof Node\Identifier && $isHttp($n->var)
                && ! ($n->getAttribute('parent') instanceof Node\Expr\MethodCall && $n->getAttribute('parent')->var === $n)) {
                $key = $n->name->toString();
            } elseif ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && $n->name->toString() === 'request' && isset($n->args[0])) {
                $a0 = $n->args[0]->value;
                [$key, $dynamic] = $a0 instanceof Node\Scalar\String_ ? [$a0->value, false] : [$print($a0), true];
            }
            if ($key === null || (! $dynamic && ! $idKey($key))) {
                continue;
            }
            $base = preg_replace('/\..*$/', '', $key);
            $v = $dynamic ? 'DYNAMIC' : ($validated[$key] ?? $validated[$base] ?? 'UNVALIDATED');
            $reads[] = [$v, $path.':'.$n->getStartLine(), $key, $formRequest ? class_basename($formRequest) : '-', $sinkOf($n),
                mb_substr($print($n), 0, 140)];
        }
    }
}

// ── report ────────────────────────────────────────────────────────────────
echo 'files parsed: '.count($files).'; parse failures: '.count($parseFailures)."\n";
foreach ($parseFailures as $p) {
    echo "  NOT INVENTORIED: {$p}\n";
}

usort($rules, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
$counts = array_count_values(array_column($rules, 0));
ksort($counts);
echo "\n== Part 1 — id-bearing validation keys: ".count($rules)." ==\n";
foreach ($counts as $c => $n) {
    printf("%5d  %s\n", $n, $c);
}

$summary = [];
foreach ($reads as $r) {
    $sink = preg_replace('/UNSCOPED:[A-Za-z]+/', 'UNSCOPED', $r[4]);
    $summary[sprintf('%-13s -> %s', $r[0], $sink)] = ($summary[sprintf('%-13s -> %s', $r[0], $sink)] ?? 0) + 1;
}
ksort($summary);
echo "\n== Part 2 — id-like request reads: ".count($reads)." (validation of that key -> where the value goes) ==\n";
foreach ($summary as $k => $n) {
    printf("%5d  %s\n", $n, $k);
}
// To read: unvalidated or dynamic keys, and anything reaching an unscoped lookup or a stored write without an ownership rule.
$toRead = array_filter($reads, fn ($r) => in_array($r[0], ['UNVALIDATED', 'DYNAMIC', 'BAG'], true)
    || str_contains($r[4], 'UNSCOPED') || (str_contains($r[4], 'STORED') && in_array($r[0], ['shape-only', 'exists-only'], true)));
usort($toRead, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
echo "\n== Part 2 — to read: ".count($toRead)." ==\n";
foreach ($toRead as $r) {
    echo implode("\t", $r), "\n";
}

echo "\n== Part 1 — keys ==\n";
foreach ($rules as $r) {
    echo implode("\t", $r), "\n";
}

exit($parseFailures === [] ? 0 : 3);
