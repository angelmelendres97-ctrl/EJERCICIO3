<?php

namespace App\Services;

use App\Models\Empresa;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class ExternalConnectionService
{
    public function getConnectionName(?int $empresaId): ?string
    {
        if (!$empresaId) {
            return null;
        }

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

    public function getEmpresasOptions(?int $empresaId): array
    {
        $connectionName = $this->getConnectionName($empresaId);
        if (!$connectionName) {
            return [];
        }

        try {
            return DB::connection($connectionName)
                ->table('saeempr')
                ->pluck('empr_nom_empr', 'empr_cod_empr')
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getSucursalesOptions(?int $empresaId, ?string $empresaCode): array
    {
        if (!$empresaCode) {
            return [];
        }

        $connectionName = $this->getConnectionName($empresaId);
        if (!$connectionName) {
            return [];
        }

        try {
            return DB::connection($connectionName)
                ->table('saesucu')
                ->where('sucu_cod_empr', $empresaCode)
                ->pluck('sucu_nom_sucu', 'sucu_cod_sucu')
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }
}
