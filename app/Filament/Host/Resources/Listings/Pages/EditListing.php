<?php

namespace App\Filament\Host\Resources\Listings\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Host\Resources\Listings\ListingResource;
use App\Models\Property;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditListing extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = ListingResource::class;

    /**
     * Every language, not the reader's one string — otherwise saving would
     * keep one translation and drop the others (the staff page's reason).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $translations = [];

        foreach ($this->listing()->translatable as $attribute) {
            $translations[$attribute] = $this->listing()->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->listing()->translatable);
    }

    public function getSubheading(): ?string
    {
        $listing = $this->listing();
        $state = ListingResource::approvalLabel($listing->approval);

        return $listing->approval === Property::CHANGES_REQUESTED && filled($listing->approval_note)
            ? $state.' — '.$listing->approval_note
            : $state;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitForApproval')
                ->label('Submit for approval')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => in_array($this->listing()->approval, [Property::DRAFT, Property::CHANGES_REQUESTED, Property::WITHDRAWN], true))
                ->requiresConfirmation()
                ->modalDescription('A person at Rihla checks the listing before guests can see it. You can keep editing meanwhile.')
                ->action(function (): void {
                    $this->listing()->forceFill([
                        'approval' => Property::PENDING,
                        'submitted_at' => now(),
                    ])->save();

                    Notification::make()->success()->title('Sent to Rihla for a check')->send();
                }),
        ];
    }

    private function listing(): Property
    {
        /** @var Property */
        return $this->getRecord();
    }
}
