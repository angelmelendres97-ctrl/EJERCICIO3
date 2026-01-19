<?php

namespace App\Http\Controllers;

use App\Filament\Resources\ResumenPedidosResource;
use App\Models\ResumenPedidos;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ResumenPedidosController extends Controller
{
    /**
     * Generate and download a PDF for the given purchase order summary.
     *
     * @param  \App\Models\ResumenPedidos  $resumenPedidos
     * @return \Illuminate\Http\Response
     */
    public function descargarPdf(ResumenPedidos $resumenPedidos)
    {
        // Load necessary relationships to avoid N+1 problems.
        $resumenPedidos->load('empresa', 'usuario');
        $detalles = $resumenPedidos->detalles()
            ->whereHas('ordenCompra', fn($query) => $query->where('anulada', false))
            ->with('ordenCompra')
            ->get();

        $nombreEmpresaTitulo = $resumenPedidos->empresa->nombre_empresa ?? 'Nombre de Empresa no disponible';
        if ($resumenPedidos->tipo === 'PB') {
            $nombreEmpresaTitulo = $resumenPedidos->empresa->nombre_pb ?: $nombreEmpresaTitulo;
        } elseif ($resumenPedidos->tipo === 'AZ') {
            $connectionName = ResumenPedidosResource::getExternalConnectionName((int) $resumenPedidos->id_empresa);
            if ($connectionName) {
                try {
                    $empresaNombre = DB::connection($connectionName)
                        ->table('saeempr')
                        ->where('empr_cod_empr', $resumenPedidos->amdg_id_empresa)
                        ->value('empr_nom_empr');
                } catch (\Exception $e) {
                    $empresaNombre = null;
                }

                if ($empresaNombre) {
                    $nombreEmpresaTitulo = $empresaNombre;
                }
            }
        }

        // The view 'pdfs.resumen_pedidos' will be created.
        $pdf = Pdf::loadView('pdfs.resumen_pedidos', [
            'resumen' => $resumenPedidos,
            'detalles' => $detalles,
            'nombreEmpresaTitulo' => $nombreEmpresaTitulo,
        ])->setPaper('a4', 'landscape');

        // Returns the PDF to be viewed in the browser.
        return $pdf->stream('resumen-pedidos-' . $resumenPedidos->id . '.pdf');
    }
}
