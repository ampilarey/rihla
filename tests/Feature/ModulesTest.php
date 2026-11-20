<?php

namespace Tests\Feature;

use App\Providers\ModulesServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * The module structure's two rules that nothing else would catch —
 * docs/MODULES_PLAN.md.
 */
class ModulesTest extends TestCase
{
    /**
     * A model moved into a module without its old name pinned would write
     * its new class name into `payable_type`, `noticeable_type`,
     * `auditable_type` or `model_type`, and every row written before the
     * move would stop resolving — silently, until a page asked for one.
     */
    public function test_every_module_model_keeps_the_class_name_its_rows_hold(): void
    {
        foreach (glob(base_path('modules/*/Models/*.php')) ?: [] as $file) {
            $module = basename(dirname($file, 2));
            $class = 'Modules\\'.$module.'\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $legacy = array_search($class, ModulesServiceProvider::LEGACY_NAMES, true);
            $this->assertNotFalse($legacy, $class.' is not in ModulesServiceProvider::LEGACY_NAMES.');
            $this->assertSame($legacy, (new $class)->getMorphClass(), $class.' must keep writing its old name.');
        }
    }

    public function test_every_module_folder_is_registered(): void
    {
        $folders = array_map('basename', glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []);

        $this->assertEqualsCanonicalizing($folders, ModulesServiceProvider::names());
    }

    /**
     * Comparing a stored class-name column with `X::class` is right only
     * until X moves into a module; then the rows hold the old name and the
     * comparison silently matches nothing. `(new X)->getMorphClass()` is
     * right before and after.
     */
    public function test_no_code_compares_a_stored_class_name_with_a_class_constant(): void
    {
        $columns = '(referenceable|about|auditable|noticeable|payable)_type';
        $offenders = [];

        foreach (['app', 'modules'] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($folder), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match("/'{$columns}'\\s*(,|=>)\\s*[A-Z]\\w*::class/", $source) === 1
                    || preg_match("/->{$columns}\\s*(===|!==|==)\\s*[A-Z]\\w*::class/", $source) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
