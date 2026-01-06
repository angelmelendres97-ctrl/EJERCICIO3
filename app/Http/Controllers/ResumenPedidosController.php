<?php

namespace App\Http\Controllers;

use App\Models\ResumenPedidos;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $resumenPedidos->load('detalles', 'empresa');

        // The view 'pdfs.resumen_pedidos' will be created.
        $pdf = Pdf::loadView('pdfs.resumen_pedidos', ['resumen' => $resumenPedidos])->setPaper('a4', 'landscape');

        // Returns the PDF to be viewed in the browser.
        return $pdf->stream('resumen-pedidos-' . $resumenPedidos->id . '.pdf');
    }
}
