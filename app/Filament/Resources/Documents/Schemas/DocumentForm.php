<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('traveller_id')
                    ->label('Traveller')
                    ->relationship('traveller', 'full_name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabledOn('edit')
                    ->helperText('Documents belong to the person, not the booking: somebody who travels again brings the same passport.'),

                Select::make('type')
                    ->options([
                        Document::PASSPORT => 'Passport',
                        'photo' => 'Photograph',
                        'national_id' => 'National ID',
                        'vaccination' => 'Vaccination record',
                        'bank_slip' => 'Bank transfer slip',
                        'other' => 'Other',
                    ])
                    ->required()
                    ->disabledOn('edit'),

                Select::make('category')
                    ->options(array_combine(Document::CATEGORIES, array_map('ucfirst', Document::CATEGORIES)))
                    ->default(Document::IDENTITY)
                    ->required(),

                DatePicker::make('expires_at')
                    ->label('Expires')
                    ->helperText('Whether that is too soon is worked out against the configured validity window, per departure.'),
            ]),

            Section::make('File')->schema([
                // Held in Filament's temporary directory and moved into the
                // wallet by DocumentWallet, so that checksumming, versioning
                // and the private disk all happen in one place rather than
                // being reimplemented by whatever form touches a file next.
                FileUpload::make('upload')
                    ->label('Upload')
                    ->acceptedFileTypes(config('documents.mime_types'))
                    ->maxSize((int) config('documents.max_kilobytes', 8192))
                    ->storeFiles(false)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('On an existing document this adds a new version and supersedes the last one. Nothing is overwritten.'),
            ]),

            Section::make('Notes')->schema([
                Textarea::make('notes')->hiddenLabel()->rows(3),
            ]),
        ]);
    }
}
