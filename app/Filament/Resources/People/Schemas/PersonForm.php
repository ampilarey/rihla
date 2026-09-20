<?php

namespace App\Filament\Resources\People\Schemas;

use App\Models\Person;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PersonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                // Not translatable: a person's name is their name, and
                // transliterating it automatically is how machine-generated
                // Dhivehi got onto this site before.
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                        if ($operation === 'create' && filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),

                TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),

                Select::make('role')
                    ->options([
                        Person::ROLE_TOUR_LEADER => 'Group leader',
                        Person::ROLE_SCHOLAR => 'Scholar',
                    ])
                    ->required(),

                FileUpload::make('photo_path')
                    ->label('Photograph')
                    ->image()
                    ->directory('people')
                    ->imageEditor()
                    ->helperText('Optional. Initials are drawn when there is none.'),

                TextInput::make('groups_led')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Leave blank if nobody has counted. Blank shows nothing; 0 would claim none.'),

                Toggle::make('is_published'),

                TextInput::make('sort_order')->numeric()->default(0),
            ]),

            Section::make('About')->schema([
                Tabs::make('Translations')->tabs([
                    self::localeTab('en', 'English', required: true),
                    self::localeTab('dv', 'Dhivehi', required: false),
                ])->columnSpanFull(),
            ]),
        ]);
    }

    private static function localeTab(string $locale, string $label, bool $required): Tab
    {
        $rtl = $locale === 'dv' ? ['dir' => 'rtl', 'lang' => 'dv'] : [];

        return Tab::make($label)->schema([
            TextInput::make("title.{$locale}")
                ->label('Title')
                ->maxLength(255)
                ->placeholder('Group leader since 2019')
                ->extraInputAttributes($rtl),

            Textarea::make("bio.{$locale}")
                ->label('Biography')
                ->rows(4)
                ->required($required)
                ->extraInputAttributes($rtl),

            // The single most practical fact about a group leader for a
            // Maldivian party, and nowhere on any competitor's site.
            Repeater::make("languages.{$locale}")
                ->label('Languages spoken')
                ->simple(TextInput::make('language')->required()->maxLength(60)->extraInputAttributes($rtl))
                ->addActionLabel('Add a language')
                ->defaultItems(0),
        ]);
    }
}
