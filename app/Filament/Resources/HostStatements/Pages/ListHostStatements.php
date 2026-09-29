<?php

namespace App\Filament\Resources\HostStatements\Pages;

use App\Filament\Resources\HostStatements\HostStatementResource;
use App\Services\Hosts\Statements;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListHostStatements extends ListRecords
{
    protected static string $resource = HostStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue for a month')
                ->icon('heroicon-o-document-plus')
                ->authorize(fn (): bool => auth()->user()?->can('partner.update') === true)
                ->schema([
                    DatePicker::make('month')->label('Any day in the month')->required()->default(now()->subMonthNoOverflow()->startOfMonth()),
                ])
                ->modalDescription('Every active host gets their statement for that month. A month already issued is left as it was.')
                ->action(function (array $data): void {
                    $count = app(Statements::class)->issueForAll(CarbonImmutable::parse($data['month']));

                    Notification::make()->success()->title($count === 0 ? 'Nothing new to issue' : "Issued {$count}")->send();
                }),
        ];
    }
}
