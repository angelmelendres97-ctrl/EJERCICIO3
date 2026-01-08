<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\ResumenPedidos;
use Barryvdh\DomPDF\Facade\Pdf;
use Config;
use DB;
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

        $nombreEmpresaPdf = $resumenPedidos->empresa->nombre_empresa ?? 'Nombre de Empresa no disponible';

        if ($resumenPedidos->tipo === 'AZ') {
            $nombreEmpresaPdf = $this->resolveExternalCompanyName($resumenPedidos) ?? $nombreEmpresaPdf;
        }

        // The view 'pdfs.resumen_pedidos' will be created.
        $pdf = Pdf::loadView('pdfs.resumen_pedidos', [
            'resumen' => $resumenPedidos,
            'nombreEmpresaPdf' => $nombreEmpresaPdf,
        ])->setPaper('a4', 'landscape');

        // Returns the PDF to be viewed in the browser.
        return $pdf->stream('resumen-pedidos-' . $resumenPedidos->id . '.pdf');
    }

    private function resolveExternalCompanyName(ResumenPedidos $resumenPedidos): ?string
    {
        $empresaId = $resumenPedidos->id_empresa;
        $amdgIdEmpresa = $resumenPedidos->amdg_id_empresa;

        if (!$empresaId || !$amdgIdEmpresa) {
            return null;
        }

        $connectionName = $this->getExternalConnectionName($empresaId);

        if (!$connectionName) {
            return null;
        }

        try {
            $empresa = DB::connection($connectionName)
                ->table('saeempr')
                ->where('empr_cod_empr', $amdgIdEmpresa)
                ->select(DB::raw(" '(' || empr_cod_empr || ') ' || empr_nom_empr AS nombre_empresa"))
                ->first();

            return $empresa->nombre_empresa ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getExternalConnectionName(int $empresaId): ?string
    {
        $empresa = Empresa::find($empresaId);
        if (!$empresa || !$empresa->status_conexion) {
            return null;
        }

        $connectionName = 'external_db_' . $empresaId;

        if (!Config::has("database.connections.{$connectionName}")) {
            $dbConfig = [
                'driver' => $empresa->motor,
                'host' => $empresa->host,
                'port' => $empresa->puerto,
                'database' => $empresa->nombre_base,
                'username' => $empresa->usuario,
                'password' => $empresa->clave,
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => '',
                'options' => [
                    \PDO::ATTR_PERSISTENT => true,
                ],
            ];
            Config::set("database.connections.{$connectionName}", $dbConfig);
        }

        return $connectionName;
    }
}
