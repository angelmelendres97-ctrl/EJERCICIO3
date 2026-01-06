<?php

namespace App\Livewire;

use App\Models\DetalleResumenPedidos;
use App\Models\ResumenPedidos;
use Filament\Notifications\Notification;
use Livewire\Component;

class ResumenPedidosDetalles extends Component
{
    public ResumenPedidos $record;

    public function mount(ResumenPedidos $record)
    {
        $this->record = $record;
    }

    public function deleteDetalle($detalleId)
    {
        $detalle = DetalleResumenPedidos::find($detalleId);
        if ($detalle) {
            $detalle->delete();
            Notification::make()
                ->title('Detalle eliminado correctamente')
                ->success()
                ->send();
            
            // Refresh the record data
            $this->record->refresh();
        } else {
            Notification::make()
                ->title('Error al eliminar el detalle')
                ->danger()
                ->send();
        }
    }

    public function render()
    {
        return view('livewire.resumen-pedidos-detalles');
    }
}
