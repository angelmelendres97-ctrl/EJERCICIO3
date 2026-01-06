<div>
    @php
        $detalles = $record ? $record->detalles()->with('ordenCompra.empresa')->get() : collect();
    @endphp

    <div class="p-4">
        <h2 class="text-lg font-bold mb-4">Órdenes de Compra para el Resumen #{{ $record->id }}</h2>
        @if($detalles->isEmpty())
            <p>No hay órdenes de compra para este resumen.</p>
        @else
            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-6 py-3">ID Orden</th>
                        <th scope="col" class="px-6 py-3">Conexión</th>
                        <th scope="col" class="px-6 py-3">Proveedor</th>
                        <th scope="col" class="px-6 py-3">Fecha</th>
                        <th scope="col" class="px-6 py-3 text-right">Total</th>
                        <th scope="col" class="px-6 py-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($detalles as $detalle)
                        <tr wire:key="detalle-{{ $detalle->id }}"
                            class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">

                            <td class="px-6 py-4">{{ $detalle->ordenCompra->id }}</td>
                            <td class="px-6 py-4">{{ $detalle->ordenCompra->empresa->nombre_empresa ?? 'N/A' }}</td>
                            <td class="px-6 py-4">{{ $detalle->ordenCompra->proveedor }}</td>
                            <td class="px-6 py-4">
                                {{ $detalle->ordenCompra->fecha_pedido ? $detalle->ordenCompra->fecha_pedido->format('Y-m-d') : 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-right">{{ number_format($detalle->ordenCompra->total, 2) }}</td>

                            <td class="px-6 py-4 text-center">
                                <button type="button" wire:click="deleteDetalle({{ $detalle->id }})"
                                    wire:confirm="¿Está seguro de que desea eliminar este registro?"
                                    class="text-red-600 hover:text-red-900">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>