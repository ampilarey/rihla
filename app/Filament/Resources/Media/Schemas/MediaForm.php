<?php

namespace App\Filament\Resources\Media\Schemas;

use App\Models\Media;
use App\Models\Trip;
use App\Support\MediaImage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * One gallery item — the fields and limits `MediaRequest` held for the
 * Blade form.
 */
class MediaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Radio::make('type')
                    ->options([Media::TYPE_PHOTO => 'Photo', Media::TYPE_VIDEO => 'Video'])
                    ->default(Media::TYPE_PHOTO)
                    ->required()
                    ->inline()
                    ->live()
                    ->columnSpanFull(),

                FileUpload::make('file_path')
                    ->label('Photo')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(6144)
                    ->disk('public')
                    ->visible(fn ($get): bool => $get('type') === Media::TYPE_PHOTO)
                    // Only when creating: an edit that changes the caption
                    // should not demand the file again.
                    ->required(fn ($get, string $operation): bool => $operation === 'create' && $get('type') === Media::TYPE_PHOTO)
                    // A 1600px WebP, a thumbnail and the original, as the
                    // Blade form stored them. `thumb_path` is set from this
                    // by the page, which is the only place both are known.
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => MediaImage::store($file))
                    ->helperText('JPEG, PNG or WebP, up to 6 MB.')
                    ->columnSpanFull(),

                TextInput::make('video_url')
                    ->label('Video link')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://www.youtube.com/watch?v=…')
                    ->visible(fn ($get): bool => $get('type') === Media::TYPE_VIDEO)
                    ->required(fn ($get): bool => $get('type') === Media::TYPE_VIDEO)
                    ->helperText('YouTube shows its own thumbnail; other sites show a play button.')
                    ->columnSpanFull(),

                TextInput::make('title.en')->label('Title')->maxLength(255),

                Select::make('trip_id')
                    ->label('Trip')
                    ->placeholder('None — a standalone item')
                    ->options(fn (): array => Trip::query()->orderBy('title')->get()
                        ->mapWithKeys(fn (Trip $trip): array => [$trip->getKey() => (string) $trip->title])
                        ->all())
                    ->searchable()
                    // The trip screen's "Add media" link names its trip.
                    ->default(fn (): ?int => request()->integer('trip_id') ?: null),

                Textarea::make('caption.en')->label('Caption')->rows(3)->columnSpanFull(),

                TextInput::make('sort_order')
                    ->label('Order')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->helperText('Lower comes first.'),

                Toggle::make('is_published')->label('In the gallery')->default(true)->inline(false),
            ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English.')
                ->collapsed()
                ->schema([
                    TextInput::make('title.dv')->label('Title (Dhivehi)')->maxLength(255),
                    Textarea::make('caption.dv')->label('Caption (Dhivehi)')->rows(3),
                ]),
        ]);
    }
}
