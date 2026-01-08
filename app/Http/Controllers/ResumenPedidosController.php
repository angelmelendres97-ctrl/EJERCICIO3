<?php

namespace App\Http\Controllers;

use App\Filament\Resources\ResumenPedidosResource;
use App\Models\ResumenPedidos;
use Barryvdh\DomPDF\Facade\Pdf;

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
        $empresaNombre = ResumenPedidosResource::resolveEmpresaNombre(
            (int) $resumenPedidos->id_empresa,
            (int) $resumenPedidos->amdg_id_empresa,
            $resumenPedidos->tipo
        );

        if ($empresaNombre) {
            $nombreEmpresaTitulo = $empresaNombre;
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
