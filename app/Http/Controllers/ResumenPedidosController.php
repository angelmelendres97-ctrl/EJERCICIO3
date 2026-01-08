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
        $resumenPedidos->load('detalles', 'empresa', 'usuario');

        $nombreEmpresaTitulo = $resumenPedidos->empresa->nombre_empresa ?? 'Nombre de Empresa no disponible';
        if ($resumenPedidos->tipo === 'AZ') {
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
            'nombreEmpresaTitulo' => $nombreEmpresaTitulo,
        ])->setPaper('a4', 'landscape');

        // Returns the PDF to be viewed in the browser.
        return $pdf->stream('resumen-pedidos-' . $resumenPedidos->id . '.pdf');
    }
}
