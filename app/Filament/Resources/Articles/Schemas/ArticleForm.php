<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Models\Person;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Article')->schema([
                Tabs::make('Translations')->tabs([
                    self::localeTab('en', 'English', required: true),
                    self::localeTab('dv', 'Dhivehi', required: false),
                ])->columnSpanFull(),
            ]),

            Section::make('Details')->columns(2)->schema([
                TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true)
                    ->helperText('The public URL. Changing it breaks existing links.'),

                // A Person, not a User: the people readers should see are the
                // ones who travel with them, not whoever holds a login.
                Select::make('author_id')
                    ->label('Author')
                    ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->helperText('Optional. Left blank, the article is published unattributed rather than credited to nobody in particular.'),

                DateTimePicker::make('published_at')
                    ->label('Publish at')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('Blank is a draft. A future time schedules it — nobody has to remember to press anything.'),

                FileUpload::make('cover_image')->image()->directory('articles')->imageEditor(),
            ]),
        ]);
    }

    private static function localeTab(string $locale, string $label, bool $required): Tab
    {
        $rtl = $locale === 'dv' ? ['dir' => 'rtl', 'lang' => 'dv'] : [];

        return Tab::make($label)->schema([
            TextInput::make("title.{$locale}")
                ->label('Title')
                ->required($required)
                ->maxLength(255)
                ->extraInputAttributes($rtl)
                ->live(onBlur: $locale === 'en')
                ->afterStateUpdated(function (string $operation, ?string $state, callable $set) use ($locale): void {
                    if ($locale === 'en' && $operation === 'create' && filled($state)) {
                        $set('slug', Str::slug($state));
                    }
                }),

            Textarea::make("excerpt.{$locale}")->label('Excerpt')->rows(2)->extraInputAttributes($rtl)
                ->helperText('Shown on the listing and given to search engines.'),

            Textarea::make("body.{$locale}")
                ->label('Body')
                ->rows(16)
                ->required($required)
                ->extraInputAttributes($rtl)
                ->helperText('Plain text. Line breaks are kept; HTML is shown as typed rather than rendered.'),
        ]);
    }
}
