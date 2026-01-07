<?php

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Filament\Resources\OrdenCompraResource;
use App\Services\OrdenCompraSyncService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateOrdenCompra extends CreateRecord
{
    protected static string $resource = OrdenCompraResource::class;


    protected function getRedirectUrl(): string
    {
        if ($this->record) {
            return route('orden-compra.pdf', $this->record);
        }

        return $this->getResource()::getUrl('index');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Crear e Imprimir');
    }

    protected function getListeners(): array
    {
        return [
            'pedidos_seleccionados' => 'onPedidosSeleccionados',
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $newDetalles = [];
        if (isset($data['detalles']) && is_array($data['detalles'])) {
            foreach ($data['detalles'] as $detalle) {
                if (!isset($detalle['valor_impuesto'])) {
                    $cantidad = floatval($detalle['cantidad'] ?? 0);
                    $costo = floatval($detalle['costo'] ?? 0);
                    $descuento = floatval($detalle['descuento'] ?? 0);
                    $porcentajeIva = floatval($detalle['impuesto'] ?? 0);

                    $subtotalItem = $cantidad * $costo;
                    $baseImponible = $subtotalItem - $descuento;
                    $valorIva = $baseImponible * ($porcentajeIva / 100);

                    $detalle['valor_impuesto'] = number_format($valorIva, 6, '.', '');
                }
                $newDetalles[] = $detalle;
            }
            $data['detalles'] = $newDetalles;
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $record = static::getModel()::create($data);

            OrdenCompraSyncService::sincronizar($record, $this->data);

            return $record;
        });
    }

    public function onPedidosSeleccionados($pedidos, $connectionId, $motivo)
    {
        Log::info('Evento pedidos_seleccionados recibido', ['pedidos' => $pedidos, 'connectionId' => $connectionId, 'motivo' => $motivo]);

        if (empty($pedidos) || !$connectionId) {
            return;
        }

        $this->data['pedidos_importados'] = implode(', ', array_map(
            fn($pedi) => str_pad($pedi, 8, "0", STR_PAD_LEFT),
            $pedidos
        ));

        $connectionName = OrdenCompraResource::getExternalConnectionName($connectionId);
        if (!$connectionName) {
            return;
        }

        $this->data['uso_compra'] = $motivo;

        $detalles = DB::connection($connectionName)
            ->table('saedped')
            ->select(
                'dped_cod_pedi',
                'dped_cod_prod',
                'dped_cod_bode',
                'dped_can_ped',
                'dped_can_ent',
                'dped_cod_auxiliar',
                'dped_desc_axiliar',
                'deped_prod_nom',
                'deped_cod_prod',
                'dped_det_deped'
            )
            ->whereIn('dped_cod_pedi', $pedidos)
            ->whereColumn('dped_can_ped', '>', 'dped_can_ent')
            ->get();

        $detallesPendientes = $detalles->map(function ($item) {
            $cantidadPendiente = (float) $item->dped_can_ped - (float) $item->dped_can_ent;

            return (object) [
                'dped_cod_prod' => $item->dped_cod_prod,
                'dped_cod_bode' => $item->dped_cod_bode,
                'dped_cod_auxiliar' => $item->dped_cod_auxiliar,
                'dped_desc_axiliar' => $item->dped_desc_axiliar,
                'deped_prod_nom' => $item->deped_prod_nom,
                'deped_cod_prod' => $item->deped_cod_prod,
                'dped_det_deped' => $item->dped_det_deped,
                'cantidad_pendiente' => $cantidadPendiente,
            ];
        })->where('cantidad_pendiente', '>', 0);

        $detallesAuxiliares = $detallesPendientes->filter(fn($detalle) => !empty($detalle->dped_cod_auxiliar));
        $detallesNormales = $detallesPendientes->filter(fn($detalle) => empty($detalle->dped_cod_auxiliar));

        $detallesAgrupados = $detallesNormales->groupBy(function ($item) {
            return $item->dped_cod_prod . '-' . $item->dped_cod_bode;
        })->map(function ($group) {
            $first = $group->first();
            $cantidadPedida = $group->sum(fn($i) => (float)$i->cantidad_pendiente);

            return (object) [
                'dped_cod_prod' => $first->dped_cod_prod,
                'cantidad_pendiente' => $cantidadPedida,
                'dped_cod_bode' => $first->dped_cod_bode,
            ];
        })->where('cantidad_pendiente', '>', 0);

        if ($detallesPendientes->isNotEmpty()) {
            $repeaterItems = collect();

            $repeaterItems = $repeaterItems->merge($detallesAgrupados->map(function ($detalle) use ($connectionName) {
                $id_bodega_item = $detalle->dped_cod_bode;

                $productData = DB::connection($connectionName)
                    ->table('saeprod')
                    ->join('saeprbo', 'prbo_cod_prod', '=', 'prod_cod_prod')
                    ->where('prod_cod_empr', $this->data['amdg_id_empresa'])
                    ->where('prod_cod_sucu', $this->data['amdg_id_sucursal'])
                    ->where('prbo_cod_empr', $this->data['amdg_id_empresa'])
                    ->where('prbo_cod_sucu', $this->data['amdg_id_sucursal'])
                    ->where('prbo_cod_bode', $id_bodega_item)
                    ->where('prod_cod_prod', $detalle->dped_cod_prod)
                    ->select('prbo_uco_prod', 'prbo_iva_porc', 'prod_nom_prod')
                    ->first();

                $costo = 0;
                $impuesto = 0;
                $productoNombre = 'Producto no encontrado';

                if ($productData) {
                    $costo = number_format($productData->prbo_uco_prod, 6, '.', '');
                    $impuesto = round($productData->prbo_iva_porc, 2);
                    $productoNombre = $productData->prod_nom_prod . ' (' . $detalle->dped_cod_prod . ')';
                }

                $valor_impuesto = (floatval($detalle->cantidad_pendiente) * floatval($costo)) * (floatval($impuesto) / 100);

                return [
                    'id_bodega' => $id_bodega_item,
                    'codigo_producto' => $detalle->dped_cod_prod,
                    'producto' => $productoNombre,
                    'detalle' => null,
                    'cantidad' => $detalle->cantidad_pendiente,
                    'costo' => $costo,
                    'descuento' => 0,
                    'impuesto' => $impuesto,
                    'valor_impuesto' => number_format($valor_impuesto, 6, '.', ''),
                ];
            }));

            $repeaterItems = $repeaterItems->merge($detallesAuxiliares->map(function ($detalle) {
                $auxiliarDetalle = $this->formatAuxiliarDetalle($detalle);

                return [
                    'id_bodega' => $detalle->dped_cod_bode,
                    'codigo_producto' => null,
                    'producto' => null,
                    'detalle' => $auxiliarDetalle,
                    'cantidad' => $detalle->cantidad_pendiente,
                    'costo' => 0,
                    'descuento' => 0,
                    'impuesto' => 0,
                    'valor_impuesto' => number_format(0, 6, '.', ''),
                ];
            }));

            $repeaterItems = $repeaterItems->values()->toArray();

            $this->data['detalles'] = $repeaterItems;

            // Force recalculation of totals
            $subtotalGeneral = 0;
            $descuentoGeneral = 0;
            $impuestoGeneral = 0;

            foreach ($this->data['detalles'] as $detalle) {
                $cantidad = floatval($detalle['cantidad'] ?? 0);
                $costo = floatval($detalle['costo'] ?? 0);
                $descuento = floatval($detalle['descuento'] ?? 0);
                $porcentajeIva = floatval($detalle['impuesto'] ?? 0);
                $subtotalItem = $cantidad * $costo;
                $impuestoGeneral += $subtotalItem * ($porcentajeIva / 100);
                $subtotalGeneral += $subtotalItem;
                $descuentoGeneral += $descuento;
            }

            $totalGeneral = ($subtotalGeneral - $descuentoGeneral) + $impuestoGeneral;

            $this->data['subtotal'] = number_format($subtotalGeneral, 2, '.', '');
            $this->data['total_descuento'] = number_format($descuentoGeneral, 2, '.', '');
            $this->data['total_impuesto'] = number_format($impuestoGeneral, 2, '.', '');
            $this->data['total'] = number_format($totalGeneral, 2, '.', '');
        }

        // Use a more specific event name if needed, or just close the generic modal
        $this->dispatch('close-modal', id: 'filtrar-pedidos');
        $this->dispatch('pedidos_importados_actualizados', pedidos_importados: $this->data['pedidos_importados']);
    }

    private function formatAuxiliarDetalle(object $detalle): string
    {
        $descripcion = $detalle->dped_desc_axiliar
            ?? $detalle->deped_prod_nom
            ?? $detalle->dped_det_deped
            ?? 'Producto auxiliar';

        $codigoAuxiliar = $detalle->dped_cod_auxiliar
            ?? $detalle->deped_cod_prod
            ?? '';

        $codigoAuxiliar = $codigoAuxiliar !== '' ? " ({$codigoAuxiliar})" : '';

        return 'Auxiliar: ' . trim($descripcion) . $codigoAuxiliar;
    }

}
