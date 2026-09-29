<?php

namespace App\Filament\Host\Resources\Packages\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Host\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Support\HostContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/**
 * A host's new package: always an island holiday, never live, on one of
 * their own places. The tenant is attached through `partner` by Filament.
 */
class CreatePackage extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = PackageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = self::withoutEmptyLocales($data, (new Package)->translatable);

        // The select offers only the host's places; a crafted request that
        // names another building gets nothing.
        abort_unless(HostContext::current()?->properties()->whereKey($data['property_id'] ?? 0)->exists(), 403);

        $title = (string) ($data['title']['en'] ?? 'package');
        $base = Str::slug($title) ?: 'package';
        $slug = $base;

        for ($n = 2; Package::where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return [
            ...$data,
            'slug' => $slug,
            'type' => Package::ISLAND_HOLIDAY,
            'is_published' => false,
        ];
    }
}
