<?php

namespace Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;
use Throwable;

/**
 * Every json/jsonb column must have a json-ish cast on its Eloquent model.
 *
 * Without one, Eloquent binds the raw PHP array, which stringifies to the
 * literal 'Array' and Postgres rejects the whole insert — silently, if the
 * caller swallows it. A missing `snapshot` cast on EntityEvent cost the
 * audit trail every return, sale and job order event before it was caught.
 */
class JsonCastArchitectureTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /**
     * Cast declarations that produce a json-encoded bind.
     */
    private const JSON_CASTS = [
        'array', 'json', 'object', 'collection',
        'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection',
        AsArrayObject::class, AsCollection::class,
    ];

    /**
     * Tables with a json column that deliberately have no Eloquent model.
     *
     * Nothing writes to these through Eloquent, so there is no $casts array to
     * get wrong. Anything else missing a model means the scan lost it.
     */
    private const MODEL_LESS_JSON_TABLES = [
        // Written by raw SQL from the audit-log archiver; read-only thereafter.
        'platform_audit_logs_archive',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_every_json_column_has_a_json_cast_on_its_model(): void
    {
        $jsonColumns = [];
        foreach (DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND data_type IN ('json', 'jsonb')"
        ) as $row) {
            $jsonColumns[$row->table_name][] = $row->column_name;
        }

        $modelsChecked = [];
        $coveredTables = [];
        $drift         = [];

        foreach ($this->modelClasses() as $class) {
            try {
                $model = new $class();
                $table = $model->getTable();
            } catch (Throwable) {
                continue;
            }

            foreach ($jsonColumns[$table] ?? [] as $column) {
                $modelsChecked[$class] = true;
                $coveredTables[$table] = true;

                $cast = $model->getCasts()[$column] ?? null;

                if ($cast === null || ! $this->isJsonCast($cast)) {
                    $drift[] = sprintf(
                        '%s::$casts is missing a json cast for %s.%s (found: %s)',
                        class_basename($class),
                        $table,
                        $column,
                        $cast ?? 'no cast'
                    );
                }
            }
        }

        // Guard against the scan silently matching nothing (e.g. a namespace move,
        // a Models/ subdirectory that stops autoloading, a renamed table).
        //
        // The old floor counted (model, column) PAIRS, which is not the quantity
        // that goes wrong: models carrying several json columns each — Shop,
        // EntityEvent, Subscription — inflate it enough that most of the model
        // layer could drop out of the scan and 25 would still be cleared. Count
        // distinct models instead, and — the assertion that actually closes the
        // hole — require every json table to be reached by SOME model. A model
        // the scan loses takes its table's coverage with it and is named in the
        // failure, instead of being absorbed by a sibling's extra columns.
        $uncovered = array_values(array_diff(
            array_keys($jsonColumns),
            array_keys($coveredTables),
            self::MODEL_LESS_JSON_TABLES,
        ));

        $this->assertSame([], $uncovered,
            "json/jsonb tables no Eloquent model claims — the model scan lost them,\n"
            . "or they are new and need a model (or a MODEL_LESS_JSON_TABLES entry):\n"
            . implode("\n", $uncovered));

        $this->assertGreaterThan(30, count($modelsChecked),
            'json cast scan reached suspiciously few models.');

        $this->assertSame([], $drift, "Uncast json columns will bind as the literal 'Array':\n" . implode("\n", $drift));
    }

    private function isJsonCast(string $cast): bool
    {
        $base = explode(':', $cast)[0];

        if (in_array($cast, self::JSON_CASTS, true) || in_array($base, ['array', 'json', 'object', 'collection'], true)) {
            return true;
        }

        // Custom cast classes own their own serialisation.
        return class_exists($cast) && is_subclass_of($cast, CastsAttributes::class);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Models'), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([app_path('Models') . '/', '.php'], '', $file->getPathname());
            $class    = 'App\\Models\\' . str_replace('/', '\\', $relative);

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
