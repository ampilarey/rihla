<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /** The queue first: somebody has said they paid and is waiting to hear. */
    public function getTabs(): array
    {
        return [
            'awaiting' => Tab::make('Waiting to be checked')
                ->modifyQueryUsing(function ($query): void {
                    /** @var Builder<Payment> $query */
                    $query->awaitingReview();
                })
                ->badge(Payment::awaitingReview()->count()),

            'received' => Tab::make('Received')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Payment::SUCCEEDED)
                    ->where('amount_minor', '>=', 0)),

            'refunds' => Tab::make('Refunds')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('amount_minor', '<', 0)),

            'not_received' => Tab::make('Not received')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Payment::FAILED)),

            'all' => Tab::make('All'),
        ];
    }
}
