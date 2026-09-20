<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\Traveller;
use App\Services\Documents\DocumentWallet;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Creating goes through DocumentWallet rather than Filament's own save, so
 * that checksumming, versioning and the private disk happen in exactly one
 * place — and so that uploading a document that already exists for this
 * traveller adds a version to it rather than creating a second document.
 */
class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $upload = $data['upload'] ?? null;
        unset($data['upload']);

        $traveller = Traveller::findOrFail($data['traveller_id']);

        if (! $upload instanceof UploadedFile) {
            // Filament hands a single file as an array when multiple() is
            // off but the state has been through a repeater or a re-render.
            $upload = is_array($upload) ? reset($upload) : $upload;
        }

        abort_unless($upload instanceof UploadedFile, 422, 'No file was uploaded.');

        $version = app(DocumentWallet::class)->store(
            $traveller,
            $upload,
            (string) $data['category'],
            (string) $data['type'],
            array_intersect_key($data, array_flip(['expires_at', 'notes', 'booking_id'])),
        );

        return $version->document;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
