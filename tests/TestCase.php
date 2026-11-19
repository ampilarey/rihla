<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every model file, wherever it lives: `app/Models` and each module's
     * `Models` folder (docs/MODULES_PLAN.md), as file => class. Guards that
     * scanned `app/Models` alone went quiet about a model the moment it
     * moved into a module — still green, no longer checking — so a guard
     * that walks the models uses this.
     *
     * @return array<string, class-string>
     */
    protected static function modelFiles(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $models[$file] = 'App\\Models\\'.basename($file, '.php');
        }

        foreach (glob(base_path('modules/*/Models/*.php')) ?: [] as $file) {
            $models[$file] = 'Modules\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php');
        }

        return $models;
    }
}
