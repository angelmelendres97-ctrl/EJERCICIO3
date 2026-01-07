<?php

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Filament\Resources\OrdenCompraResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use App\Models\PedidoCompra;
use Illuminate\Support\Facades\Log;
use Filament\Actions\Action;

class EditOrdenCompra extends EditRecord
{
    protected static string $resource = OrdenCompraResource::class;

    protected function getListeners(): array
    {
        return [
            'pedidos_seleccionados' => 'onPedidosSeleccionados',
        ];
    }

    public function onPedidosSeleccionados($pedidos, $connectionId, $motivo)
    {
        Log::info('Evento pedidos_seleccionados recibido en Edit', ['pedidos' => $pedidos, 'connectionId' => $connectionId, 'motivo' => $motivo]);

        if (empty($pedidos) || !$connectionId) {
            return;
        }

        $pedidosImportadosActuales = array_filter(explode(', ', $this->data['pedidos_importados'] ?? ''));
        $nuevosPedidos = array_map(fn($p) => str_pad($p, 8, "0", STR_PAD_LEFT), $pedidos);
        $this->data['pedidos_importados'] = implode(', ', array_unique(array_merge($pedidosImportadosActuales, $nuevosPedidos)));

        $connectionName = OrdenCompraResource::getExternalConnectionName($connectionId);
        if (!$connectionName) {
            return;
        }

        if (empty($this->data['uso_compra'])) {
            $this->data['uso_compra'] = $motivo;
        }

        $detalles = DB::connection($connectionName)
            ->table('saedped')
            ->whereIn('dped_cod_pedi', $pedidos)
            ->whereColumn('dped_can_ped', '>', 'dped_can_ent')
            ->get();

        $detallesAgrupados = $detalles->groupBy(function ($item) {
            if (!empty($item->dped_cod_auxiliar)) {
                return 'aux-' . ($item->dped_det_dped ?? uniqid('', true));
            }

            if ($this->isServicioItem($item->dped_cod_prod ?? null)) {
                return 'servicio-' . ($item->dped_cod_pedi ?? 'pedido') . '-' . ($item->dped_cod_bode ?? 'bode') . '-' . uniqid('', true);
            }

            return $item->dped_cod_prod . '-' . $item->dped_cod_bode;
        })->map(function ($group) {
            $first = $group->first();
            $cantidadPedida = $group->sum(fn($i) => (float)$i->dped_can_ped);
            $cantidadEntregada = $group->sum(fn($i) => (float)$i->dped_can_ent);
            return (object) [
                'dped_cod_prod' => $first->dped_cod_prod,
                'cantidad_pendiente' => $cantidadPedida - $cantidadEntregada,
                'dped_cod_bode' => $first->dped_cod_bode,
                'es_auxiliar' => !empty($first->dped_cod_auxiliar),
                'es_servicio' => $this->isServicioItem($first->dped_cod_prod ?? null),
                'auxiliar_codigo' => $first->dped_cod_auxiliar ?? null,
                'auxiliar_nombre' => $first->dped_det_dped
                    ?? $first->dped_desc_axiliar
                    ?? $first->deped_prod_nom
                    ?? null,
                'servicio_nombre' => $first->dped_det_dped
                    ?? $first->dped_desc_axiliar
                    ?? $first->deped_prod_nom
                    ?? null,
            ];
        })->where('cantidad_pendiente', '>', 0);

        if ($detallesAgrupados->isNotEmpty()) {
            $repeaterItems = $detallesAgrupados->map(function ($detalle) use ($connectionName) {
                $id_bodega_item = $detalle->dped_cod_bode;

                $costo = 0;
                $impuesto = 0;
                $productoNombre = 'Producto no encontrado';

                if (!$detalle->es_auxiliar && !$detalle->es_servicio) {
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

                    if ($productData) {
                        $costo = number_format($productData->prbo_uco_prod, 6, '.', '');
                        $impuesto = round($productData->prbo_iva_porc, 2);
                        $productoNombre = $productData->prod_nom_prod . ' (' . $detalle->dped_cod_prod . ')';
                    }
                }

                $valor_impuesto = (floatval($detalle->cantidad_pendiente) * floatval($costo)) * (floatval($impuesto) / 100);

                $auxiliarDescripcion = null;
                if ($detalle->es_auxiliar) {
                    $auxiliarDescripcion = trim(collect([
                        $detalle->auxiliar_codigo ? 'Código auxiliar: ' . $detalle->auxiliar_codigo : null,
                        $detalle->auxiliar_nombre ? 'Descripción: ' . $detalle->auxiliar_nombre : null,
                    ])->filter()->implode(' | '));
                }

                $servicioDescripcion = null;
                if ($detalle->es_servicio) {
                    $servicioDescripcion = trim(collect([
                        $detalle->dped_cod_prod ? 'Código servicio: ' . $detalle->dped_cod_prod : null,
                        $detalle->servicio_nombre ? 'Descripción: ' . $detalle->servicio_nombre : null,
                    ])->filter()->implode(' | '));
                }

                return [
                    'id_bodega' => $id_bodega_item,
                    'codigo_producto' => ($detalle->es_auxiliar || $detalle->es_servicio) ? null : $detalle->dped_cod_prod,
                    'producto' => ($detalle->es_auxiliar || $detalle->es_servicio) ? null : $productoNombre,
                    'es_auxiliar' => $detalle->es_auxiliar,
                    'es_servicio' => $detalle->es_servicio,
                    'producto_auxiliar' => $auxiliarDescripcion,
                    'producto_servicio' => $servicioDescripcion,
                    'cantidad' => $detalle->cantidad_pendiente,
                    'costo' => $costo,
                    'descuento' => 0,
                    'impuesto' => $impuesto,
                    'valor_impuesto' => number_format($valor_impuesto, 6, '.', ''),
                ];
            })->values()->toArray();

            // Filter out blank rows from existing details before merging
            $existingItems = array_filter($this->data['detalles'] ?? [], fn($item) => !empty($item['codigo_producto']));
            $this->data['detalles'] = array_merge($existingItems, $repeaterItems);
            
            // Recalculate totals after merging
            $this->recalculateTotals();
        }

        $this->applySolicitadoPor($connectionName, $this->parsePedidosImportados($this->data['pedidos_importados'] ?? ''));

        $this->dispatch('close-modal', id: 'filtrar-pedidos');
    }

    private function recalculateTotals()
    {
        $subtotalGeneral = 0;
        $descuentoGeneral = 0;
        $impuestoGeneral = 0;

        foreach ($this->data['detalles'] as $detalle) {
            $cantidad = floatval($detalle['cantidad'] ?? 0);
            $costo = floatval($detalle['costo'] ?? 0);
            $descuento = floatval($detalle['descuento'] ?? 0);
            $porcentajeIva = floatval($detalle['impuesto'] ?? 0);
            $subtotalItem = $cantidad * $costo;
            $impuestoGeneral += ($subtotalItem - $descuento) * ($porcentajeIva / 100);
            $subtotalGeneral += $subtotalItem;
            $descuentoGeneral += $descuento;
        }

        $totalGeneral = ($subtotalGeneral - $descuentoGeneral) + $impuestoGeneral;

        $this->data['subtotal'] = number_format($subtotalGeneral, 2, '.', '');
        $this->data['total_descuento'] = number_format($descuentoGeneral, 2, '.', '');
        $this->data['total_impuesto'] = number_format($impuestoGeneral, 2, '.', '');
        $this->data['total'] = number_format($totalGeneral, 2, '.', '');
        
        // This is crucial to make the form's total display update in real-time
        $this->form->fill($this->data);
    }

    private function isServicioItem(?string $codigoProducto): bool
    {
        if (!$codigoProducto) {
            return false;
        }

        return (bool) preg_match('/^SP[-\\s]*SP[-\\s]*SP/i', $codigoProducto);
    }

    private function parsePedidosImportados(?string $value): array
    {
        if (!$value) {
            return [];
        }

        return collect(preg_split('/\\s*,\\s*/', trim($value)))
            ->filter()
            ->map(fn($pedido) => (int) ltrim((string) $pedido, '0'))
            ->filter(fn($pedido) => $pedido > 0)
            ->values()
            ->all();
    }

    private function applySolicitadoPor(string $connectionName, array $pedidos): void
    {
        if (empty($pedidos)) {
            return;
        }

        $solicitantes = DB::connection($connectionName)
            ->table('saepedi')
            ->whereIn('pedi_cod_pedi', $pedidos)
            ->pluck('pedi_res_pedi')
            ->filter(fn($value) => !empty(trim((string) $value)))
            ->map(fn($value) => trim((string) $value))
            ->unique()
            ->values();

        if ($solicitantes->isNotEmpty()) {
            $this->data['solicitado_por'] = $solicitantes->implode(', ');
            $this->form->fill($this->data);
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
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

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->hidden();
    }
}
