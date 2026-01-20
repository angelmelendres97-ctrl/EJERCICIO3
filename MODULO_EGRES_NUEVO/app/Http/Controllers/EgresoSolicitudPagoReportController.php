<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EgresoSolicitudPagoReportController extends Controller
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        $cacheKey = $this->buildCacheKey($token);
        $payload = Cache::get($cacheKey);

        if (! $payload) {
            abort(404);
        }

        $reportData = $this->buildReportData($payload['contexts'] ?? []);
        $solicitudId = $payload['solicitud_id'] ?? 'sin-solicitud';
        $generatedAt = now();

        $pdf = Pdf::loadView('pdfs.egreso-solicitud-pago', [
            'solicitudId' => $solicitudId,
            'generatedAt' => $generatedAt,
            'reportes' => $reportData,
        ]);

        $filename = 'egreso-solicitud-' . $solicitudId . '-' . $generatedAt->format('YmdHis') . '.pdf';
        $path = 'egresos/reportes/' . $filename;

        Storage::disk('local')->put($path, $pdf->output());

        Cache::forget($cacheKey);

        return $pdf->stream($filename);
    }

    protected function buildCacheKey(string $token): string
    {
        return 'egreso_report_' . $token;
    }

    protected function buildReportData(array $contexts): array
    {
        return collect($contexts)
            ->map(function (array $context): array {
                $connection = $context['connection'] ?? null;
                $empresa = $context['empresa'] ?? null;
                $sucursal = $context['sucursal'] ?? null;
                $ejercicio = $context['ejercicio'] ?? null;
                $periodo = $context['periodo'] ?? null;
                $asiento = $context['asto_cod_asto'] ?? null;

                if (! $connection || ! $empresa || ! $sucursal || ! $ejercicio || ! $periodo || ! $asiento) {
                    return [
                        'context' => $context,
                        'asiento' => null,
                        'diario' => [],
                        'directorio' => [],
                    ];
                }

                $asientoRow = DB::connection($connection)
                    ->table('saeasto')
                    ->where('asto_cod_empr', $empresa)
                    ->where('asto_cod_sucu', $sucursal)
                    ->where('asto_cod_asto', $asiento)
                    ->where('asto_cod_ejer', $ejercicio)
                    ->where('asto_num_prdo', $periodo)
                    ->first();

                $diario = DB::connection($connection)
                    ->table('saedasi')
                    ->where('asto_cod_empr', $empresa)
                    ->where('asto_cod_sucu', $sucursal)
                    ->where('asto_cod_asto', $asiento)
                    ->where('asto_cod_ejer', $ejercicio)
                    ->where('dasi_num_prdo', $periodo)
                    ->orderBy('dasi_cod_cuen')
                    ->get();

                $directorio = DB::connection($connection)
                    ->table('saedir')
                    ->where('dire_cod_empr', $empresa)
                    ->where('dire_cod_sucu', $sucursal)
                    ->where('dire_cod_asto', $asiento)
                    ->where('asto_cod_ejer', $ejercicio)
                    ->where('asto_num_prdo', $periodo)
                    ->orderBy('dir_cod_dir')
                    ->get();

                return [
                    'context' => $context,
                    'asiento' => $asientoRow,
                    'diario' => $diario,
                    'directorio' => $directorio,
                ];
            })
            ->values()
            ->all();
    }
}
