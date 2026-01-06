<?php

namespace App\Filament\Resources\ResumenPedidosResource\Pages;

use App\Filament\Resources\ResumenPedidosResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListResumenPedidos extends ListRecords
{
    protected static string $resource = ResumenPedidosResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
