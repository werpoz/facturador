<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\SerieResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSeries extends ManageRecords
{
    protected static string $resource = SerieResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
