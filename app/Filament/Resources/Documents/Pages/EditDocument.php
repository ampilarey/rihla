<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DocumentWallet;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Saving with a file attached adds a version; saving without one edits the
 * document's own fields. Neither ever overwrites a stored file.
 *
 * No delete action: `document.delete` does not exist as a permission, and
 * [R-8] keeps every version.
 */
class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Document $record */
        $upload = $data['upload'] ?? null;
        unset($data['upload']);

        $record->fill($data)->save();

        $upload = is_array($upload) ? reset($upload) : $upload;

        if ($upload instanceof UploadedFile) {
            app(DocumentWallet::class)->store(
                $record->traveller,
                $upload,
                $record->category,
                $record->type,
            );
        }

        return $record->refresh();
    }
}
