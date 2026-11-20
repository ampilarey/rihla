<?php

namespace Modules\Stays\Filament\Host\Resources\Packages\Pages;

use App\Filament\Concerns\EditsTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Stays\Filament\Host\Resources\Packages\PackageResource;
use Modules\Stays\Support\HostContext;
use Modules\Umrah\Models\Package;

/**
 * A host edits their package until it is live ({@see PackageResource::canEdit()}).
 */
class EditPackage extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = PackageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $translations = [];

        foreach ($this->package()->translatable as $attribute) {
            $translations[$attribute] = $this->package()->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(HostContext::current()?->properties()->whereKey($data['property_id'] ?? 0)->exists(), 403);

        // The type, the slug and whether it is live are never the host's.
        unset($data['type'], $data['slug'], $data['is_published']);

        return self::withoutEmptyLocales($data, $this->package()->translatable);
    }

    protected function getHeaderActions(): array
    {
        return [PackageResource::submit()->record($this->package()), DeleteAction::make()];
    }

    private function package(): Package
    {
        /** @var Package */
        return $this->getRecord();
    }
}
