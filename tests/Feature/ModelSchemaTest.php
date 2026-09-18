<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every name a model declares must be a column that exists.
 *
 * GuideStep listed four attributes in $fillable that were not columns, and
 * omitted the one that was. Mass assignment drops an unknown name without
 * complaint, so the seeder's text was silently discarded for months, and the
 * admin panel returned a 500 the moment it tried to write one of the four.
 *
 * Nothing compared the two, so the disagreement could not be seen from either
 * side: the model looked right, the migration looked right.
 */
class ModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_fillable_attribute_is_a_real_column(): void
    {
        $offenders = [];

        foreach ($this->models() as $model) {
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                $offenders[] = get_class($model).": table {$table} does not exist";

                continue;
            }

            $columns = Schema::getColumnListing($table);

            foreach ($model->getFillable() as $attribute) {
                if (! in_array($attribute, $columns, true)) {
                    $offenders[] = sprintf(
                        '%s: $fillable names "%s", which %s has no column for',
                        class_basename($model),
                        $attribute,
                        $table,
                    );
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Models declare attributes their tables do not have:'], $offenders,
        )));
    }

    /**
     * A cast on an attribute that is not a column is the same disagreement
     * seen from the other direction, and it is how `fiqh_notes` came to be
     * read as an array that was never written.
     */
    public function test_every_cast_is_a_real_column(): void
    {
        $offenders = [];

        foreach ($this->models() as $model) {
            if (! Schema::hasTable($model->getTable())) {
                continue;
            }

            $columns = Schema::getColumnListing($model->getTable());

            foreach (array_keys($model->getCasts()) as $attribute) {
                // Laravel casts the primary key itself; it is always present.
                if ($attribute === $model->getKeyName()) {
                    continue;
                }

                if (! in_array($attribute, $columns, true)) {
                    $offenders[] = sprintf(
                        '%s: casts "%s", which %s has no column for',
                        class_basename($model),
                        $attribute,
                        $model->getTable(),
                    );
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Models cast attributes their tables do not have:'], $offenders,
        )));
    }

    /** @return list<Model> */
    private function models(): array
    {
        $models = [];

        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.Str::before($file->getFilename(), '.php');

            if (! class_exists($class)) {
                continue;
            }

            $instance = new $class;

            if ($instance instanceof Model) {
                $models[] = $instance;
            }
        }

        $this->assertNotEmpty($models, 'No models were found to check.');

        return $models;
    }
}
