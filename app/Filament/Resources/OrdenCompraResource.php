<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrdenCompraResource\Pages;
use App\Models\OrdenCompra;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Empresa;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Models\Proveedores;
use App\Models\Producto;
use App\Services\ProveedorSyncService;
use App\Services\ProductoSyncService;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Tables\Filters\Filter;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\View;
use Filament\Actions\StaticAction;
use Illuminate\Database\Eloquent\Model; // ESTA LÍNEA ES NECESARIA
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;

class OrdenCompraResource extends Resource
{
    protected static ?string $model = OrdenCompra::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function userIsAdmin(): bool
    {
        $user = auth()->user();

        return $user?->hasRole('ADMINISTRADOR') ?? false;
    }

    public static function getExternalConnectionName(int $empresaId): ?string
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

    private static function getProveedorFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Información General')
                ->schema([
                    Forms\Components\Select::make('id_empresa')
                        ->label('Conexion')
                        ->relationship('empresa', 'nombre_empresa')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (callable $set): void {
                            $set('admg_id_empresa', null);
                            $set('admg_id_sucursal', null);
                        })
                        ->required(),

                    Forms\Components\Select::make('admg_id_empresa')
                        ->label('Empresa')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            if (!$empresaId) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
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
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn(callable $set) => $set('admg_id_sucursal', null))
                        ->required(),

                    Forms\Components\Select::make('admg_id_sucursal')
                        ->label('Sucursal')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saesucu')
                                    ->where('sucu_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('sucu_nom_sucu', 'sucu_cod_sucu')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),

                    Forms\Components\Select::make('tipo')
                        ->label('Tipo Identificacion')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('comercial.tipo_iden_clpv')
                                    ->pluck('identificacion', 'identificacion')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('ruc')
                        ->label('Identificacion')
                        ->required()
                        ->maxLength(13)
                        ->suffixAction(
                            Action::make('buscar_sri')
                                ->label('Buscar')
                                ->icon('heroicon-o-magnifying-glass')
                                ->action(function (Get $get, Set $set): void {
                                    $ruc = preg_replace('/\D/', '', (string) $get('ruc'));

                                    if (!$ruc || strlen($ruc) < 10) {
                                        Notification::make()
                                            ->title('Ingresa un RUC/Cédula válido para consultar en el SRI.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    $endpoint = 'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/ConsolidadoContribuyente/obtenerPorNumerosRuc';

                                    try {
                                        $response = Http::timeout(15)
                                            ->acceptJson()
                                            ->get($endpoint, ['ruc' => $ruc]);
                                    } catch (\Throwable $e) {
                                        Notification::make()
                                            ->title('No se pudo conectar con el SRI.')
                                            ->body($e->getMessage())
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    if (!$response->ok()) {
                                        Notification::make()
                                            ->title('El SRI no respondió correctamente.')
                                            ->body('Código: ' . $response->status())
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    $payload = $response->json();
                                    $data = is_array($payload) ? ($payload[0] ?? null) : $payload;

                                    if (!is_array($data)) {
                                        Notification::make()
                                            ->title('Respuesta del SRI inesperada.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    $razonSocial = data_get($data, 'razonSocial');
                                    $numeroRuc = data_get($data, 'numeroRuc');
                                    $agenteRetencion = data_get($data, 'agenteRetencion');

                                    if (empty($razonSocial)) {
                                        Notification::make()
                                            ->title('No se encontró razón social en la respuesta del SRI.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    $toBoolSiNo = fn($v) => in_array(strtoupper(trim((string) $v)), ['SI', 'S', 'TRUE', '1'], true);

                                    $set('ruc', $numeroRuc ?: $ruc);
                                    $set('nombre', $razonSocial);
                                    $set('nombre_comercial', $razonSocial);
                                    $set('aplica_retencion_sn', $toBoolSiNo($agenteRetencion));

                                    Notification::make()
                                        ->title('Datos del SRI cargados correctamente.')
                                        ->success()
                                        ->send();
                                })
                        ),

                    Forms\Components\TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('nombre_comercial', $state);
                        }),

                    Forms\Components\TextInput::make('nombre_comercial')
                        ->label('Nombre Comercial')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('telefono')
                        ->label('Telefono')
                        ->required()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('correo')
                        ->label('Email')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('direcccion')
                        ->label('Dirección')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Toggle::make('aplica_retencion_sn')
                        ->label('¿Aplica Retención?')
                        ->default(false),
                ])
                ->columns(3),

            Forms\Components\Section::make('Clasificación')
                ->schema([
                    Forms\Components\Select::make('grupo')
                        ->label('Grupo')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saegrpv')
                                    ->where('grpv_cod_empr', $amdgIdEmpresaCode)
                                    ->where('grpv_cod_modu', 4)
                                    ->pluck('grpv_nom_grpv', 'grpv_nom_grpv')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('zona')
                        ->label('Zona')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saezona')
                                    ->where('zona_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('zona_nom_zona', 'zona_nom_zona')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('flujo_caja')
                        ->label('Flujo de Caja')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saecact')
                                    ->where('cact_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('cact_nom_cact', 'cact_nom_cact')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('tipo_proveedor')
                        ->label('Tipo de proveedor')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saetprov')
                                    ->where('tprov_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('tprov_des_tprov', 'tprov_des_tprov')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Condiciones de Pago')
                ->schema([
                    Forms\Components\Select::make('forma_pago')
                        ->label('Forma de Pago')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saefpagop')
                                    ->where('fpagop_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('fpagop_des_fpagop', 'fpagop_des_fpagop')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('destino_pago')
                        ->label('Destino Pago')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saetpago')
                                    ->where('tpago_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('tpago_des_tpago', 'tpago_des_tpago')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('pais_pago')
                        ->label('Pais de Pago')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saepaisp')
                                    ->pluck('paisp_des_paisp', 'paisp_des_paisp')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('dias_pago')
                        ->numeric()
                        ->label('Días de Pago'),

                    Forms\Components\TextInput::make('limite_credito')
                        ->numeric()
                        ->label('Límite de Crédito')
                        ->step('0.01'),

                    Forms\Components\Select::make('lineasNegocio')
                        ->label('Líneas de Negocio')
                        ->relationship('lineasNegocio', 'nombre')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->live()
                        ->required(),
                ])
                ->columns(3),

            Forms\Components\Section::make('Empresas')
                ->schema([
                    Forms\Components\CheckboxList::make('empresas_proveedor')
                        ->label('Empresas para replicar')
                        ->options(function (Get $get) {
                            $lineasNegocioIds = $get('lineasNegocio');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');
                            $ruc = $get('ruc');

                            if (empty($lineasNegocioIds)) {
                                return [];
                            }

                            $empresas = Empresa::whereIn('linea_negocio_id', $lineasNegocioIds)
                                ->where('status_conexion', true)->get();

                            $empresasOptions = [];

                            foreach ($empresas as $empresa) {
                                $connectionName = self::getExternalConnectionName($empresa->id);
                                if (!$connectionName) {
                                    continue;
                                }

                                try {
                                    $externalEmpresas = DB::connection($connectionName)
                                        ->table('saeempr')
                                        ->get();

                                    foreach ($externalEmpresas as $data_empresa) {
                                        $optionKey = $empresa->id . '-' . trim($data_empresa->empr_cod_empr);
                                        $optionLabel = $empresa->nombre_empresa . ' - ' . $data_empresa->empr_nom_empr;
                                        $empresasOptions[$optionKey] = $optionLabel;
                                    }
                                } catch (\Exception $e) {
                                    \Log::error('Error al conectar con la base de datos externa para la empresa ID ' . $empresa->id . ': ' . $e->getMessage());
                                    continue;
                                }
                            }

                            return $empresasOptions;
                        })
                        ->afterStateHydrated(function (Get $get, callable $set) {
                            $lineasNegocioIds = $get('lineasNegocio');
                            $amdgIdEmpresaCode = $get('admg_id_empresa');
                            $ruc = $get('ruc');

                            if (empty($lineasNegocioIds)) {
                                return;
                            }

                            $seleccionados = [];

                            $empresas = Empresa::whereIn('linea_negocio_id', $lineasNegocioIds)
                                ->where('status_conexion', true)
                                ->get();

                            foreach ($empresas as $empresa) {
                                $connectionName = self::getExternalConnectionName($empresa->id);
                                if (!$connectionName) {
                                    continue;
                                }

                                try {
                                    $externalEmpresas = DB::connection($connectionName)
                                        ->table('saeempr')
                                        ->get();

                                    foreach ($externalEmpresas as $data_empresa) {
                                        $optionKey = $empresa->id . '-' . trim($data_empresa->empr_cod_empr);

                                        $existeProveedor = DB::connection($connectionName)
                                            ->table('saeclpv')
                                            ->where('clpv_cod_empr', $amdgIdEmpresaCode)
                                            ->where('clpv_ruc_clpv', $ruc)
                                            ->where('clpv_clopv_clpv', 'PV')
                                            ->exists();

                                        if ($existeProveedor) {
                                            $seleccionados[] = $optionKey;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    \Log::error("Error en conexión externa empresa {$empresa->id}: " . $e->getMessage());
                                    continue;
                                }
                            }

                            $set('empresas_proveedor', $seleccionados);
                        })
                        ->columns(2)
                ])->columns(1),
        ];
    }

    private static function getProductoFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Conexion e informacion principal')
                ->schema([
                    Forms\Components\Select::make('id_empresa')
                        ->label('Conexion')
                        ->relationship('empresa', 'nombre_empresa')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('amdg_id_empresa')
                        ->label('Empresa')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            if (!$empresaId) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
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
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),

                    Forms\Components\Select::make('amdg_id_sucursal')
                        ->label('Sucursal')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('amdg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saesucu')
                                    ->where('sucu_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('sucu_nom_sucu', 'sucu_cod_sucu')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('linea')
                        ->label('Línea')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $amdgIdEmpresaCode = $get('amdg_id_empresa');

                            if (!$empresaId || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saelinp')
                                    ->where('linp_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('linp_des_linp', 'linp_cod_linp')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('grupo')
                        ->label('Grupo')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $lineaCode = $get('linea');
                            $amdgIdEmpresaCode = $get('amdg_id_empresa');

                            if (!$empresaId || !$lineaCode || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saegrpr')
                                    ->where('grpr_cod_linp', $lineaCode)
                                    ->where('grpr_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('grpr_des_grpr', 'grpr_cod_grpr')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('categoria')
                        ->label('Categoria')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $grupoCode = $get('grupo');
                            $amdgIdEmpresaCode = $get('amdg_id_empresa');

                            if (!$empresaId || !$grupoCode || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saecate')
                                    ->where('cate_cod_grpr', $grupoCode)
                                    ->where('cate_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('cate_nom_cate', 'cate_cod_cate')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('marca')
                        ->label('Marca')
                        ->options(function (Get $get) {
                            $empresaId = $get('id_empresa');
                            $categoriaCode = $get('categoria');
                            $amdgIdEmpresaCode = $get('amdg_id_empresa');

                            if (!$empresaId || !$categoriaCode || !$amdgIdEmpresaCode) {
                                return [];
                            }

                            $connectionName = self::getExternalConnectionName($empresaId);
                            if (!$connectionName) {
                                return [];
                            }

                            try {
                                return DB::connection($connectionName)
                                    ->table('saemarc')
                                    ->where('marc_cod_cate', $categoriaCode)
                                    ->where('marc_cod_empr', $amdgIdEmpresaCode)
                                    ->pluck('marc_des_marc', 'marc_cod_marc')
                                    ->all();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\Actions::make([
                        Action::make('search_inventory_tree_action')
                            ->label('Buscar Árbol Inventario')
                            ->disabled(fn(Get $get) => !$get('amdg_id_empresa'))
                            ->mountUsing(function ($form, Get $get) {
                                $form->fill(['id_empresa' => $get('id_empresa'), 'amdg_id_empresa' => $get('amdg_id_empresa')]);
                            })
                            ->action(function () {
                            })
                            ->form([
                                Forms\Components\TextInput::make('search_term')
                                    ->label('Buscar Coincidencia')
                                    ->live(debounce: '500ms')
                                    ->extraAttributes(['wire:keydown.enter.prevent' => ''])
                                    ->autofocus(),
                                Forms\Components\Hidden::make('id_empresa'),
                                Forms\Components\Hidden::make('amdg_id_empresa'),

                                Forms\Components\View::make('filament.hooks.set-product-tree-values'),

                                Placeholder::make('search_results')
                                    ->disableLabel()
                                    ->content(function (Get $get) {
                                        $empresaId = $get('id_empresa');
                                        $amdgIdEmpresaCode = $get('amdg_id_empresa');
                                        $searchTerm = $get('search_term');

                                        if (!$empresaId || !$amdgIdEmpresaCode) {
                                            return 'Seleccione una empresa antes de buscar.';
                                        }
                                        if (!$searchTerm) {
                                            return 'Ingrese un término de búsqueda.';
                                        }

                                        try {
                                            $connectionName = self::getExternalConnectionName($empresaId);
                                            if (!$connectionName) {
                                                return 'Error de conexión.';
                                            }

                                            $searchTermUpper = strtoupper($searchTerm);
                                            $results = DB::connection($connectionName)
                                                ->table('saelinp as l')
                                                ->leftJoin('saegrpr as g', function ($join) use ($amdgIdEmpresaCode) {
                                                    $join->on('l.linp_cod_linp', '=', 'g.grpr_cod_linp')
                                                        ->where('g.grpr_cod_empr', '=', $amdgIdEmpresaCode);
                                                })
                                                ->leftJoin('saecate as c', function ($join) use ($amdgIdEmpresaCode) {
                                                    $join->on('g.grpr_cod_grpr', '=', 'c.cate_cod_grpr')
                                                        ->where('c.cate_cod_empr', '=', $amdgIdEmpresaCode);
                                                })
                                                ->leftJoin('saemarc as m', function ($join) use ($amdgIdEmpresaCode) {
                                                    $join->on('c.cate_cod_cate', '=', 'm.marc_cod_cate')
                                                        ->where('m.marc_cod_empr', '=', $amdgIdEmpresaCode);
                                                })
                                                ->select(
                                                    'l.linp_cod_linp',
                                                    'l.linp_des_linp',
                                                    'g.grpr_cod_grpr',
                                                    'g.grpr_des_grpr',
                                                    'c.cate_cod_cate',
                                                    'c.cate_nom_cate',
                                                    'm.marc_cod_marc',
                                                    'm.marc_des_marc'
                                                )
                                                ->where('l.linp_cod_empr', $amdgIdEmpresaCode)
                                                ->where(function ($query) use ($searchTermUpper) {
                                                    $query->whereRaw('UPPER(l.linp_des_linp) LIKE ?', ["%{$searchTermUpper}%"])
                                                        ->orWhereRaw('UPPER(g.grpr_des_grpr) LIKE ?', ["%{$searchTermUpper}%"])
                                                        ->orWhereRaw('UPPER(c.cate_nom_cate) LIKE ?', ["%{$searchTermUpper}%"])
                                                        ->orWhereRaw('UPPER(m.marc_des_marc) LIKE ?', ["%{$searchTermUpper}%"]);
                                                })
                                                ->distinct()
                                                ->limit(50)
                                                ->get();

                                            if ($results->isEmpty()) {
                                                return 'No se encontraron resultados.';
                                            }

                                            $tableHtml = '<table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">';
                                            $tableHtml .= '<thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400"><tr>';
                                            $tableHtml .= '<th scope="col" class="px-6 py-3">Línea</th><th scope="col" class="px-6 py-3">Grupo</th><th scope="col" class="px-6 py-3">Categoría</th><th scope="col" class="px-6 py-3">Marca</th><th scope="col" class="px-6 py-3">Acción</th>';
                                            $tableHtml .= '</tr></thead><tbody>';

                                            foreach ($results as $row) {
                                                $data = htmlspecialchars(json_encode([
                                                    'linea' => trim($row->linp_cod_linp ?? ''),
                                                    'grupo' => trim($row->grpr_cod_grpr ?? ''),
                                                    'categoria' => trim($row->cate_cod_cate ?? ''),
                                                    'marca' => trim($row->marc_cod_marc ?? ''),
                                                ]), ENT_QUOTES, 'UTF-8');

                                                $tableHtml .= '<tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">';
                                                $tableHtml .= '<td class="px-6 py-4">' . ($row->linp_des_linp ?? '') . '</td>';
                                                $tableHtml .= '<td class="px-6 py-4">' . ($row->grpr_des_grpr ?? '') . '</td>';
                                                $tableHtml .= '<td class="px-6 py-4">' . ($row->cate_nom_cate ?? '') . '</td>';
                                                $tableHtml .= '<td class="px-6 py-4">' . ($row->marc_des_marc ?? '') . '</td>';
                                                $tableHtml .= '<td class="px-6 py-4"><button type="button" x-on:click.prevent="$dispatch(\'fill-from-tree\', { data: ' . $data . ' });close()" class="filament-button filament-button-size-sm inline-flex items-center justify-center py-1 gap-1 font-medium rounded-lg border transition-colors focus:outline-none focus:ring-offset-2 focus:ring-2 focus:ring-inset dark:focus:ring-offset-0 min-h-[2rem] px-3 text-sm text-white shadow focus:ring-white border-transparent bg-primary-600 hover:bg-primary-500 focus:bg-primary-700 focus:ring-offset-primary-700">Seleccionar</button></td>';
                                                $tableHtml .= '</tr>';
                                            }

                                            $tableHtml .= '</tbody></table>';
                                            return new HtmlString($tableHtml);
                                        } catch (\Exception $e) {
                                            \Log::error('Error en búsqueda de árbol de inventario: ' . $e->getMessage());
                                            return 'Error al realizar la búsqueda.';
                                        }
                                    }),
                            ])
                            ->modalWidth('4xl')
                            ->modalHeading('Buscar en Árbol de Inventario')
                    ])

                ])->columns(2),
            Forms\Components\Section::make('Información Producto')
                ->schema([
                    Forms\Components\TextInput::make('sku')
                        ->label('Codigo')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('nombre')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Textarea::make('detalle')
                        ->label('Detalle')
                        ->rows(3)
                        ->maxLength(65535)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('tipo')
                        ->label('Tipo')
                        ->required()
                        ->options([
                            1 => 'Servicio',
                            2 => 'Producto',
                        ])
                        ->default(2),

                    Forms\Components\Select::make('id_unidad_medida')
                        ->label('Unidad de Medida')
                        ->relationship('unidadMedida', 'nombre')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('stock_minimo')
                        ->label('Stock Mínimo')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Forms\Components\TextInput::make('stock_maximo')
                        ->label('Stock Máximo')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Forms\Components\Checkbox::make('iva_sn')
                        ->label('¿Aplica IVA?')
                        ->default(false),
                    Forms\Components\TextInput::make('porcentaje_iva')
                        ->label('Porcentaje IVA (%)')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Forms\Components\Select::make('lineasNegocio')
                        ->label('Líneas de Negocio')
                        ->relationship('lineasNegocio', 'nombre')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->live()
                        ->required(),
                ])->columns(2),
            Forms\Components\Section::make('Sucursales y Bodegas Externas')
                ->schema([
                    Forms\Components\CheckboxList::make('bodegas')
                        ->label('Bodegas para replicar')
                        ->options(function (Get $get) {
                            $lineasNegocioIds = $get('lineasNegocio');
                            if (empty($lineasNegocioIds)) {
                                return [];
                            }

                            $empresas = Empresa::whereIn('linea_negocio_id', $lineasNegocioIds)
                                ->where('status_conexion', true)->get();

                            $bodegasOptions = [];

                            foreach ($empresas as $empresa) {
                                $connectionName = self::getExternalConnectionName($empresa->id);
                                if (!$connectionName) {
                                    continue;
                                }

                                try {
                                    $externalBodegas = DB::connection($connectionName)
                                        ->table('saebode as b')
                                        ->join('saesubo as sb', 'b.bode_cod_bode', '=', 'sb.subo_cod_bode')
                                        ->join('saesucu as s', 'sb.subo_cod_sucu', '=', 's.sucu_cod_sucu')
                                        ->select('b.bode_cod_bode', 'b.bode_nom_bode', 's.sucu_nom_sucu')
                                        ->get();

                                    foreach ($externalBodegas as $bodega) {
                                        $optionKey = $empresa->id . '-' . trim($bodega->bode_cod_bode);
                                        $optionLabel = $empresa->nombre_empresa . ' - ' . $bodega->sucu_nom_sucu . ' - ' . $bodega->bode_nom_bode;
                                        $bodegasOptions[$optionKey] = $optionLabel;
                                    }
                                } catch (\Exception $e) {
                                    \Log::error('Error al conectar con la base de datos externa para la empresa ID ' . $empresa->id . ': ' . $e->getMessage());
                                    continue;
                                }
                            }

                            return $bodegasOptions;
                        })
                        ->afterStateHydrated(function (Get $get, callable $set) {
                            $lineasNegocioIds = $get('lineasNegocio');
                            $sku = $get('sku');

                            if (empty($lineasNegocioIds)) {
                                return;
                            }

                            $seleccionados = [];

                            $empresas = Empresa::whereIn('linea_negocio_id', $lineasNegocioIds)
                                ->where('status_conexion', true)
                                ->get();

                            foreach ($empresas as $empresa) {
                                $connectionName = self::getExternalConnectionName($empresa->id);
                                if (!$connectionName) {
                                    continue;
                                }

                                try {
                                    $externalBodegas = DB::connection($connectionName)
                                        ->table('saebode as b')
                                        ->join('saesubo as sb', 'b.bode_cod_bode', '=', 'sb.subo_cod_bode')
                                        ->join('saesucu as s', 'sb.subo_cod_sucu', '=', 's.sucu_cod_sucu')
                                        ->select('b.bode_cod_bode', 'b.bode_nom_bode', 's.sucu_nom_sucu', 's.sucu_cod_empr')
                                        ->get();

                                    foreach ($externalBodegas as $bodega) {
                                        $optionKey = $empresa->id . '-' . trim($bodega->bode_cod_bode);
                                        $optionLabel = $empresa->nombre_empresa . ' - ' . $bodega->sucu_nom_sucu . ' - ' . $bodega->bode_nom_bode;
                                        $bodegasOptions[$optionKey] = $optionLabel;

                                        $existeProdBode = DB::connection($connectionName)
                                            ->table('saeprbo')
                                            ->where('prbo_cod_empr', $bodega->sucu_cod_empr)
                                            ->where('prbo_cod_bode', trim($bodega->bode_cod_bode))
                                            ->where('prbo_cod_prod', $sku)
                                            ->exists();

                                        if ($existeProdBode) {
                                            $seleccionados[] = $optionKey;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    \Log::error("Error en conexión externa empresa {$empresa->id}: " . $e->getMessage());
                                    continue;
                                }
                            }

                            $set('bodegas', $seleccionados);
                        })
                        ->columns(2)
                ])->columns(1),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                /*
                |--------------------------------------------------------------------------
                | MODAL "Ver Productos de la Orden" (DESACTIVADO TEMPORALMENTE)
                | Motivo: en tu entorno está fallando por métodos no disponibles.
                |--------------------------------------------------------------------------
                */
                // Actions::make([
                //     Action::make('verProductos')
                //         ->label('Ver Productos de la Orden')
                //         ->action(function (OrdenCompra $record) {
                //             // No action needed here, it just opens the modal
                //         })
                //         ->modalContent(fn(OrdenCompra $record): \Illuminate\Contracts\View\View => view(
                //             'filament.resources.orden-compra-resource.actions.ver-productos',
                //             ['detalles' => $record->detalles],
                //         ))
                //         ->modalSubmitAction(false)
                //         ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar'))
                //         ->color('info')
                //         ->icon('heroicon-o-eye'),
                // ])
                //     ->columnSpanFull()
                //     ->visible(fn($record) => $record !== null),

                Forms\Components\Section::make('Conexión y Empresa')
                    ->schema([
                        Forms\Components\Select::make('id_empresa')
                            ->label('Conexión')
                            ->relationship('empresa', 'nombre_empresa')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('pedidos_importados', null);
                                $set('detalles', []);
                            })

                            ->required(),

                        Forms\Components\Select::make('amdg_id_empresa')
                            ->label('Empresa')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                if (!$empresaId) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
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
                            })
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('pedidos_importados', null);
                                $set('detalles', []);
                            })

                            ->required(),

                        Forms\Components\Select::make('amdg_id_sucursal')
                            ->label('Sucursal')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdgIdEmpresaCode = $get('amdg_id_empresa');

                                if (!$empresaId || !$amdgIdEmpresaCode) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saesucu')
                                        ->where('sucu_cod_empr', $amdgIdEmpresaCode)
                                        ->pluck('sucu_nom_sucu', 'sucu_cod_sucu')
                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('pedidos_importados', null);
                                $set('detalles', []);
                            })

                            ->required(),
                    ])->columns(3),

                /*
                |--------------------------------------------------------------------------
                | ESTE MODAL SÍ SE REACTIVA (Importar desde Pedido)
                |--------------------------------------------------------------------------
                */
                Forms\Components\Section::make('Información Presupuesto')
                    ->headerActions([
                        Action::make('importar_pedido')
                            ->label('Importar desde Pedido')
                            ->icon('heroicon-o-magnifying-glass')

                            ->modalContent(function (Get $get) {
                                $id_empresa = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');
                                $amdg_id_sucursal = $get('amdg_id_sucursal');
                                $pedidos_importados = $get('pedidos_importados');

                                return view('livewire.buscar-pedidos-compra-container', compact(
                                    'id_empresa',
                                    'amdg_id_empresa',
                                    'amdg_id_sucursal',
                                    'pedidos_importados'
                                ));
                            })
                            ->modalHeading('Buscar Pedidos de Compra para Importar')
                            ->modalSubmitAction(false)
                            ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar'))
                            ->visible(fn(Get $get) => !empty($get('id_empresa')) && !empty($get('amdg_id_empresa')) && !empty($get('amdg_id_sucursal')))
                    ])
                    ->schema([

                        Forms\Components\TextInput::make('pedidos_importados')
                            ->label('Pedidos Importados')
                            ->readOnly()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('uso_compra')
                            ->label('Para Uso De:')
                            ->required()
                            ->maxLength(2550)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('solicitado_por')
                            ->label('Solicitado Por:')
                            ->required()
                            ->maxLength(2550)
                            ->columnSpan(2),

                        Forms\Components\Select::make(name: 'formato')
                            ->label('Formato:')
                            ->options(['F' => 'FACTURA', 'P' => 'PROFORMA'])
                            ->required(),

                        Forms\Components\TextInput::make('numero_factura_proforma')
                            ->label(fn(Get $get) => $get('formato') === 'P' ? 'Número de proforma' : 'Número de factura')
                            ->helperText('Ingrese el número según el formato seleccionado.')
                            ->visible(fn(Get $get) => filled($get('formato')))
                            ->maxLength(255)
                            ->extraAttributes([
                                'style' => 'max-width: 220px; white-space: normal; word-break: break-word;',
                            ]),

                        Forms\Components\Select::make(name: 'tipo_oc')
                            ->label('Tipo Orden Compra:')
                            ->options([
                                'REEMB' => 'REEMBOLSO',
                                'COMPRA' => 'COMPRA',
                                'PAGO' => 'PAGO',
                                'REGUL' => 'REGULARIZACIÓN',
                                'CAJAC' => 'CAJA CHICA'
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('nombre_reembolso')
                            ->label('Nombre de a quien se reembolsa')
                            ->visible(fn(Get $get) => $get('tipo_oc') === 'REEMB')
                            ->maxLength(255),

                        Forms\Components\Select::make(name: 'presupuesto')
                            ->label('Presupuesto:')
                            ->options(['AZ' => 'AZ', 'PB' => 'PB'])
                            ->required(),

                    ])->columns(4),

                Forms\Components\Section::make('Información General')
                    ->schema([
                        Forms\Components\Select::make('info_proveedor')
                            ->label('Proveedor')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');

                                if (!$empresaId) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saeclpv')
                                        ->where('clpv_cod_empr', $amdg_id_empresa)
                                        ->where('clpv_clopv_clpv', 'PV')
                                        ->select([
                                            'clpv_cod_clpv',
                                            DB::raw("clpv_nom_clpv || ' (' || clpv_ruc_clpv || ')' AS proveedor_etiqueta")
                                        ])
                                        ->pluck('proveedor_etiqueta', 'clpv_cod_clpv')
                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->required()
                            ->columnSpan(2)
                            ->suffixAction(
                                /*
                                |--------------------------------------------------------------------------
                                | Modal para crear proveedor (habilitado en línea).
                                |--------------------------------------------------------------------------
                                */
                                Action::make('crear_proveedor')
                                    ->label('+')
                                    ->tooltip('Crear proveedor')
                                    ->icon('heroicon-o-plus')
                                    ->modalHeading('Crear proveedor')
                                    ->modalWidth('7xl')
                                    ->modalSubmitActionLabel('Crear proveedor')
                                    ->form(function (Form $form): Form {
                                        $schema = self::getProveedorFormSchema();

                                        $labels = [
                                            'Información General',
                                            'Clasificación',
                                            'Condiciones de Pago',
                                            'Empresas',
                                        ];

                                        $steps = [];

                                        foreach ($labels as $i => $label) {
                                            if (isset($schema[$i])) {
                                                $steps[] = Step::make($label)->schema([$schema[$i]]);
                                            }
                                        }

                                        if (empty($steps)) {
                                            $steps[] = Step::make('Proveedor')
                                                ->schema([
                                                    \Filament\Forms\Components\Placeholder::make('error_schema')
                                                        ->label('Error')
                                                        ->content('No se pudo cargar el formulario del proveedor.'),
                                                ]);
                                        }

                                        return $form
                                            ->schema([
                                                Wizard::make($steps),
                                            ])
                                            ->model(Proveedores::class);
                                    })
                                    ->mountUsing(function (Action $action): void {
                                        // En un modal dentro de un form, esto suele estar en $livewire->data
                                        $data = data_get($action->getLivewire(), 'data', []);

                                        $action->fillForm([
                                            'id_empresa'       => $data['id_empresa'] ?? null,
                                            'admg_id_empresa'  => $data['amdg_id_empresa'] ?? null,
                                            'admg_id_sucursal' => $data['amdg_id_sucursal'] ?? null,
                                        ]);
                                    })

                                    ->action(function (array $data, Set $set, Get $get): void {
                                        $record = Proveedores::create($data);
                                        $lineasNegocioIds = $data['lineasNegocio'] ?? [];
                                        if (!empty($lineasNegocioIds)) {
                                            $record->lineasNegocio()->attach($lineasNegocioIds);
                                        }

                                        ProveedorSyncService::sincronizar($record, $data);

                                        $empresaId = $data['id_empresa'] ?? $get('id_empresa');
                                        $admgIdEmpresa = $data['admg_id_empresa'] ?? $get('amdg_id_empresa');
                                        $connectionName = self::getExternalConnectionName((int) $empresaId);

                                        if ($connectionName) {
                                            $proveedor = DB::connection($connectionName)
                                                ->table('saeclpv')
                                                ->where('clpv_cod_empr', $admgIdEmpresa)
                                                ->where('clpv_ruc_clpv', $data['ruc'])
                                                ->where('clpv_clopv_clpv', 'PV')
                                                ->select('clpv_cod_clpv', 'clpv_nom_clpv', 'clpv_ruc_clpv')
                                                ->first();

                                            if ($proveedor) {
                                                $set('info_proveedor', $proveedor->clpv_cod_clpv);
                                                $set('identificacion', $proveedor->clpv_ruc_clpv);
                                                $set('id_proveedor', $proveedor->clpv_cod_clpv);
                                                $set('proveedor', $proveedor->clpv_nom_clpv);
                                            }
                                        }

                                        Notification::make()
                                            ->title('Proveedor creado correctamente.')
                                            ->success()
                                            ->send();
                                    })
                            )
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                if (empty($state)) {
                                    $set('identificacion', null);
                                    return;
                                }

                                $empresaId = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    $set('identificacion', null);
                                    return;
                                }

                                $data = DB::connection($connectionName)
                                    ->table('saeclpv')
                                    ->where('clpv_cod_clpv', $state)
                                    ->where('clpv_cod_empr', $amdg_id_empresa)
                                    ->select('clpv_ruc_clpv', 'clpv_cod_clpv', 'clpv_nom_clpv')
                                    ->first();

                                if ($data) {
                                    $set('identificacion', $data->clpv_ruc_clpv);
                                    $set('id_proveedor', $data->clpv_cod_clpv);
                                    $set('proveedor', $data->clpv_nom_clpv);
                                } else {
                                    $set('identificacion', null);
                                    $set('id_proveedor', null);
                                    $set('proveedor', null);
                                }
                            }),

                        Forms\Components\Hidden::make('proveedor'),

                        Forms\Components\TextInput::make('id_proveedor')
                            ->numeric()
                            ->required()
                            ->label('ID Proveedor')
                            ->readOnly()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('identificacion')
                            ->maxLength(20)
                            ->label('Identificación (RUC/DNI)')
                            ->readOnly()
                            ->columnSpan(1),

                        Forms\Components\Select::make('trasanccion')
                            ->label('Transacción')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');
                                $amdg_id_sucursal = $get('amdg_id_sucursal');

                                if (!$empresaId) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saetran as t')
                                        ->join('saedefi as d', 't.tran_cod_tran', '=', 'd.defi_cod_tran')
                                        ->where('t.tran_cod_empr', $amdg_id_empresa)
                                        ->where('t.tran_cod_sucu', $amdg_id_sucursal)
                                        ->where('t.tran_cod_modu', 10)
                                        ->where('d.defi_cod_empr', $amdg_id_empresa)
                                        ->where('d.defi_tip_defi', '4')
                                        ->where('d.defi_cod_modu', 10)
                                        ->select([
                                            't.tran_des_tran',
                                            DB::raw("t.tran_des_tran || ' (' || t.tran_cod_tran || ')' AS transaccion_etiqueta")
                                        ])
                                        ->groupBy('t.tran_des_tran', 'transaccion_etiqueta')
                                        ->orderBy('transaccion_etiqueta', 'asc')
                                        ->pluck('transaccion_etiqueta', 't.tran_cod_tran')

                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->default('ORDEN DE COMPRA')
                            ->required()
                            ->columnSpan(2),

                        Forms\Components\DatePicker::make('fecha_pedido')
                            ->label('Fecha del Pedido')
                            ->default(now())
                            ->required(),

                        Forms\Components\DatePicker::make('fecha_entrega')
                            ->label('Fecha de Entrega Estimada')
                            ->default(now()->addWeek())
                            ->required(),

                        Forms\Components\Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->maxLength(65535)
                            ->reactive()
                            ->afterStateUpdated(function (string|null $state, Set $set): void {
                                $set('observaciones', $state ? mb_strtoupper($state) : $state);
                            })
                            ->dehydrateStateUsing(fn(?string $state) => $state ? mb_strtoupper($state) : $state)
                            ->columnSpanFull(),
                    ])->columns(4),

                Forms\Components\Section::make('Productos')
                    ->headerActions([
                        /*
                        |--------------------------------------------------------------------------
                        | Modal para registrar producto (habilitado en línea).
                        |--------------------------------------------------------------------------
                        */
                        Action::make('ir_crear_producto')
                            ->label('+ Registrar nuevo producto')
                            ->icon('heroicon-o-plus')
                            ->modalHeading('Registrar nuevo producto')
                            ->modalWidth('7xl')
                            ->modalSubmitActionLabel('Registrar producto')
                            ->form(function (Form $form): Form {
                                return $form
                                    ->schema(self::getProductoFormSchema())
                                    ->model(Producto::class);
                            })

                            ->mountUsing(function (Action $action): void {
                                $data = data_get($action->getLivewire(), 'data', []);

                                $action->fillForm([
                                    'id_empresa' => $data['id_empresa'] ?? null,
                                    'amdg_id_empresa' => $data['amdg_id_empresa'] ?? null,
                                    'amdg_id_sucursal' => $data['amdg_id_sucursal'] ?? null,
                                ]);
                            })
                            ->action(function (array $data): void {
                                $record = Producto::create($data);
                                $lineasNegocioIds = $data['lineasNegocio'] ?? [];
                                if (!empty($lineasNegocioIds)) {
                                    $record->lineasNegocio()->attach($lineasNegocioIds);
                                }

                                ProductoSyncService::sincronizar($record, $data);

                                Notification::make()
                                    ->title('Producto creado correctamente.')
                                    ->success()
                                    ->send();
                            }),
                    ])
                    ->schema([
                        Forms\Components\Repeater::make('detalles')
                            ->schema([
                                Grid::make(14)
                                    ->schema([
                                        Forms\Components\Hidden::make('es_auxiliar'),
                                        Forms\Components\Hidden::make('es_servicio'),
                                        Forms\Components\Hidden::make('detalle'),
                                        Forms\Components\Hidden::make('pedido_codigo'),
                                        Forms\Components\Hidden::make('pedido_detalle_id'),

                                        Forms\Components\TextInput::make('producto_auxiliar')
                                            ->label('Producto auxiliar')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->visible(fn(Get $get) => (bool) $get('es_auxiliar'))
                                            ->columnSpan(['default' => 12, 'lg' => 14]),
                                        Forms\Components\Hidden::make('unidad'),

                                        Forms\Components\TextInput::make('producto_servicio')
                                            ->label('Servicio')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->visible(fn(Get $get) => (bool) $get('es_servicio'))
                                            ->columnSpan(['default' => 12, 'lg' => 14]),

                                        Forms\Components\Select::make('id_bodega')
                                            ->label('Bodega')
                                            ->placeholder('Seleccione')
                                            ->options(function (Get $get) {
                                                $empresaId = $get('../../id_empresa');
                                                $amdgIdEmpresaCode = $get('../../amdg_id_empresa');
                                                $amdg_id_sucursal = $get('../../amdg_id_sucursal');

                                                if (!$empresaId || !$amdgIdEmpresaCode) {
                                                    return [];
                                                }

                                                $connectionName = self::getExternalConnectionName($empresaId);
                                                if (!$connectionName) {
                                                    return [];
                                                }

                                                try {
                                                    return DB::connection($connectionName)
                                                        ->table('saebode')
                                                        ->join('saesubo', 'subo_cod_bode', '=', 'bode_cod_bode')
                                                        ->where('subo_cod_empr', $amdgIdEmpresaCode)
                                                        ->where('bode_cod_empr', $amdgIdEmpresaCode)
                                                        ->where('subo_cod_sucu', $amdg_id_sucursal)
                                                        ->pluck('bode_nom_bode', 'bode_cod_bode')
                                                        ->all();
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->live()
                                            ->required()
                                            ->columnSpan(['default' => 12, 'lg' => 2]),

                                        Forms\Components\Select::make('codigo_producto')
                                            ->label('Producto')
                                            ->options(function (Get $get) {
                                                $empresaId = $get('../../id_empresa');
                                                $amdg_id_empresa = $get('../../amdg_id_empresa');
                                                $amdg_id_sucursal = $get('../../amdg_id_sucursal');
                                                $id_bodega = $get('id_bodega');

                                                if (!$empresaId || !$id_bodega) {
                                                    return [];
                                                }

                                                $connectionName = self::getExternalConnectionName($empresaId);
                                                if (!$connectionName) {
                                                    return [];
                                                }

                                                try {
                                                    return DB::connection($connectionName)
                                                        ->table('saeprod')
                                                        ->join('saeprbo', 'prbo_cod_prod', '=', 'prod_cod_prod')
                                                        ->where('prod_cod_sucu', $amdg_id_sucursal)
                                                        ->where('prod_cod_empr', $amdg_id_empresa)
                                                        ->where('prbo_cod_empr', $amdg_id_empresa)
                                                        ->where('prbo_cod_sucu', $amdg_id_sucursal)
                                                        ->where('prbo_cod_bode', $id_bodega)
                                                        ->select([
                                                            'prod_cod_prod',
                                                            DB::raw("prod_nom_prod || ' (' || prod_cod_prod || ')' AS productos_etiqueta")
                                                        ])
                                                        ->orderBy('productos_etiqueta', 'asc')
                                                        ->pluck('productos_etiqueta', 'prod_cod_prod');
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->live()
                                            ->required()
                                            ->helperText(fn(Get $get) => (bool) $get('es_auxiliar')
                                                ? 'Seleccione un producto real del inventario para reemplazar el auxiliar.'
                                                : ((bool) $get('es_servicio')
                                                    ? 'Seleccione un producto real del inventario para reemplazar el servicio.'
                                                    : null))
                                            ->columnSpan(['default' => 12, 'lg' => 3])
                                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                                if (empty($state)) {
                                                    $set('producto', null);
                                                    $set('costo', 0);
                                                    $set('impuesto', 0);
                                                    return;
                                                }

                                                $empresaId = $get('../../id_empresa');
                                                $amdg_id_empresa = $get('../../amdg_id_empresa');
                                                $amdg_id_sucursal = $get('../../amdg_id_sucursal');
                                                $id_bodega = $get('id_bodega');

                                                $connectionName = self::getExternalConnectionName($empresaId);
                                                if (!$connectionName) {
                                                    return;
                                                }

                                                $data = DB::connection($connectionName)
                                                    ->table('saeprod')
                                                    ->join('saeprbo', 'prbo_cod_prod', '=', 'prod_cod_prod')
                                                    ->where('prod_cod_sucu', $amdg_id_sucursal)
                                                    ->where('prod_cod_empr', $amdg_id_empresa)
                                                    ->where('prbo_cod_empr', $amdg_id_empresa)
                                                    ->where('prbo_cod_sucu', $amdg_id_sucursal)
                                                    ->where('prbo_cod_bode', $id_bodega)
                                                    ->where('prbo_cod_prod', $state)
                                                    ->where('prod_cod_prod', $state)
                                                    ->select('prbo_uco_prod', 'prbo_iva_porc', 'prod_nom_prod')
                                                    ->first();

                                                if ($data) {
                                                    $set('costo', number_format($data->prbo_uco_prod, 6, '.', ''));
                                                    $impuesto = round($data->prbo_iva_porc, 2);
                                                    $set('impuesto', $impuesto == 8.0 ? 18 : $impuesto);
                                                    $set('producto', $data->prod_nom_prod . ' (' . $state . ')');
                                                }
                                            }),

                                        Forms\Components\Hidden::make('producto'),

                                        Forms\Components\TextInput::make('cantidad')
                                            ->numeric()
                                            ->required()
                                            ->live(onBlur: true)
                                            ->default(1)
                                            ->columnSpan(['default' => 12, 'lg' => 1]),

                                        Forms\Components\TextInput::make('costo')
                                            ->numeric()
                                            ->required()
                                            ->live(onBlur: true)
                                            ->prefix('$')
                                            ->columnSpan(['default' => 12, 'lg' => 2]),

                                        Forms\Components\TextInput::make('descuento')
                                            ->numeric()
                                            ->required()
                                            ->live(onBlur: true)
                                            ->default(0)
                                            ->prefix('$')
                                            ->columnSpan(['default' => 12, 'lg' => 2]),

                                        Forms\Components\Placeholder::make('subtotal_linea')
                                            ->label('Subtotal')
                                            ->content(function (Get $get) {
                                                $cantidad = floatval($get('cantidad'));
                                                $costo = floatval($get('costo'));
                                                $subtotal = $cantidad * $costo;

                                                return '$' . number_format($subtotal, 4, '.', '');
                                            })
                                            ->columnSpan(['default' => 12, 'lg' => 1]),

                                        Forms\Components\Select::make('impuesto')
                                            ->options(['0' => '0%', '5' => '5%', '8' => '8%', '15' => '15%', '18' => '18%'])
                                            ->required()
                                            ->live()
                                            ->columnSpan(['default' => 12, 'lg' => 1]),

                                        /*    Forms\Components\Placeholder::make('valor_iva')
                                            ->label('IVA')
                                            ->content(function (Get $get) {
                                                $cantidad = floatval($get('cantidad'));
                                                $costo = floatval($get('costo'));
                                                $iva = floatval($get('impuesto'));
                                                $valorIva = ($cantidad * $costo) * ($iva / 100);
                                                return '$' . number_format($valorIva, 4, '.', '');
                                            })
                                            ->columnSpan(['default' => 12, 'lg' => 1]), */







                                        Forms\Components\Placeholder::make('total_linea')
                                            ->label('Total Item')
                                            ->content(function (Get $get) {
                                                $cantidad = floatval($get('cantidad'));
                                                $costo = floatval($get('costo'));
                                                $descuento = floatval($get('descuento'));
                                                $iva = floatval($get('impuesto'));

                                                $subtotal = $cantidad * $costo;
                                                $valorIva = $subtotal * ($iva / 100);
                                                $total = ($subtotal + $valorIva) - $descuento;

                                                return '$' . number_format($total, 4, '.', '');
                                            })
                                            ->columnSpan(['default' => 12, 'lg' => 2]),
                                    ]),
                            ])
                            ->relationship()
                            ->columns(1)
                            ->addActionLabel('Agregar Producto')
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $detalles = $get('detalles');
                                $subtotalGeneral = 0;
                                $descuentoGeneral = 0;
                                $impuestoGeneral = 0;

                                foreach ($detalles as $detalle) {
                                    $cantidad = floatval($detalle['cantidad'] ?? 0);
                                    $costo = floatval($detalle['costo'] ?? 0);
                                    $descuento = floatval($detalle['descuento'] ?? 0);
                                    $porcentajeIva = floatval($detalle['impuesto'] ?? 0);

                                    $subtotalItem = $cantidad * $costo;
                                    $valorIva = $subtotalItem * ($porcentajeIva / 100);

                                    $subtotalGeneral += $subtotalItem;
                                    $descuentoGeneral += $descuento;
                                    $impuestoGeneral += $valorIva;
                                }

                                $totalGeneral = ($subtotalGeneral - $descuentoGeneral) + $impuestoGeneral;

                                $set('subtotal', number_format($subtotalGeneral, 2, '.', ''));
                                $set('total_descuento', number_format($descuentoGeneral, 2, '.', ''));
                                $set('total_impuesto', number_format($impuestoGeneral, 2, '.', ''));
                                $set('total', number_format($totalGeneral, 2, '.', ''));
                            })
                            ->live(),
                    ]),

                // Hidden fields for totals
                Forms\Components\Hidden::make('subtotal')->default(0),
                Forms\Components\Hidden::make('total_descuento')->default(0),
                Forms\Components\Hidden::make('total_impuesto')->default(0),
                Forms\Components\Hidden::make('total')->default(0),

                Section::make('Resumen de Totales')
                    ->schema([
                        Grid::make()
                            ->columns(1)
                            ->extraAttributes(['class' => 'w-full'])
                            ->schema([
                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_subtotal')
                                            ->content('Subtotal')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_subtotal')
                                            ->content(function (Get $get) {
                                                $subtotal = collect($get('detalles'))->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($subtotal, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_desc')
                                            ->content('Total Descuentos')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_desc')
                                            ->content(function (Get $get) {
                                                $totalDescuentos = collect($get('detalles'))->sum(fn($item) => floatval($item['descuento']));
                                                return '$' . number_format($totalDescuentos, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva0')
                                            ->content('Subtotal IVA 0%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva0')
                                            ->content(function (Get $get) {
                                                $baseIva0 = collect($get('detalles'))->where('impuesto', '0')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva0, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva0 = collect($get('detalles'))->where('impuesto', '0')->reduce(function ($carry, $item) {
                                            $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                            return $carry + ($subtotal * 0);
                                        }, 0);

                                        return $totalIva0 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva0')
                                            ->content('IVA 0%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva0')
                                            ->content(function () {
                                                return '$' . number_format(0, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva0 = collect($get('detalles'))->where('impuesto', '0')->reduce(function ($carry, $item) {
                                            $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                            return $carry + ($subtotal * 0);
                                        }, 0);

                                        return $totalIva0 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva5')
                                            ->content('Subtotal IVA 5%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva5')
                                            ->content(function (Get $get) {
                                                $baseIva5 = collect($get('detalles'))->where('impuesto', '5')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva5, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva5 = collect($get('detalles'))->where('impuesto', '5')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.05);
                                        }, 0);
                                        return $totalIva5 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva5')
                                            ->content('IVA 5%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva5')
                                            ->content(function (Get $get) {
                                                $totalIva = collect($get('detalles'))->where('impuesto', '5')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.05);
                                                }, 0);
                                                return '$' . number_format($totalIva, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva = collect($get('detalles'))->where('impuesto', '5')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.05);
                                        }, 0);
                                        return $totalIva > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva8')
                                            ->content('Subtotal IVA 8%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva8')
                                            ->content(function (Get $get) {
                                                $baseIva8 = collect($get('detalles'))->where('impuesto', '8')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);

                                                return '$' . number_format($baseIva8, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva8 = collect($get('detalles'))->where('impuesto', '8')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.08);
                                        }, 0);

                                        return $totalIva8 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva8')
                                            ->content('IVA 8%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva8')
                                            ->content(function (Get $get) {
                                                $totalIva8 = collect($get('detalles'))->where('impuesto', '8')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.08);
                                                }, 0);

                                                return '$' . number_format($totalIva8, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva8 = collect($get('detalles'))->where('impuesto', '8')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.08);
                                        }, 0);

                                        return $totalIva8 > 0;
                                    }),



                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva15')
                                            ->content('Subtotal IVA 15%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva15')
                                            ->content(function (Get $get) {
                                                $baseIva15 = collect($get('detalles'))->where('impuesto', '15')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva15, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva15 = collect($get('detalles'))->where('impuesto', '15')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.15);
                                        }, 0);
                                        return $totalIva15 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva15')
                                            ->content('IVA 15%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva15')
                                            ->content(function (Get $get) {
                                                $totalIva15 = collect($get('detalles'))->where('impuesto', '15')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.15);
                                                }, 0);
                                                return '$' . number_format($totalIva15, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva15 = collect($get('detalles'))->where('impuesto', '15')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.15);
                                        }, 0);
                                        return $totalIva15 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva18')
                                            ->content('Subtotal IVA 18%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva18')
                                            ->content(function (Get $get) {
                                                $baseIva18 = collect($get('detalles'))->where('impuesto', '18')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva18, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva18 = collect($get('detalles'))->where('impuesto', '18')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.18);
                                        }, 0);
                                        return $totalIva18 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva18')
                                            ->content('IVA 18%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva18')
                                            ->content(function (Get $get) {
                                                $totalIva18 = collect($get('detalles'))->where('impuesto', '18')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.18);
                                                }, 0);
                                                return '$' . number_format($totalIva18, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalIva18 = collect($get('detalles'))->where('impuesto', '18')->reduce(function ($carry, $item) {
                                            return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.18);
                                        }, 0);
                                        return $totalIva18 > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_total_impuesto')
                                            ->content('Total Impuestos')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_total_impuesto')
                                            ->content(function (Get $get) {
                                                $totalImpuestos = collect($get('detalles'))->reduce(function ($carry, $item) {
                                                    $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                                    return $carry + ($subtotal * (floatval($item['impuesto']) / 100));
                                                }, 0);
                                                return '$' . number_format($totalImpuestos, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ])
                                    ->visible(function (Get $get) {
                                        $totalImpuestos = collect($get('detalles'))->reduce(function ($carry, $item) {
                                            $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                            return $carry + ($subtotal * (floatval($item['impuesto']) / 100));
                                        }, 0);
                                        return $totalImpuestos > 0;
                                    }),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end mt-2 border-t border-gray-300 dark:border-gray-700 pt-2 gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_total')
                                            ->content('Total General')
                                            ->extraAttributes(['class' => 'text-right font-extrabold text-lg text-primary-600'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_total')
                                            ->content(function (Get $get) {
                                                $total = collect($get('detalles'))->reduce(function ($carry, $item) {
                                                    $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                                    $valorIva = $subtotal * (floatval($item['impuesto']) / 100);
                                                    $descuento = floatval($item['descuento']);
                                                    return $carry + ($subtotal + $valorIva - $descuento);
                                                }, 0);
                                                return '$' . number_format($total, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-extrabold text-xl text-primary-600 w-32'])
                                            ->hiddenLabel(),
                                    ]),
                            ]),
                    ])->columns(1),

            ])->live();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->actionsPosition(\Filament\Tables\Enums\ActionsPosition::BeforeColumns)

            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('Código OC')
                    ->searchable()
                    ->searchable(isIndividual: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('empresa.nombre_empresa')
                    ->label('Conexión')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('formato')
                    ->label('Formato')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'P' => 'PROFORMA',
                        'F' => 'FACTURA',
                        default => 'Desconocido',
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'P' => 'warning',
                        'F' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('numero_factura_proforma')
                    ->label('N° Fact/Proforma')
                    ->searchable()
                    ->sortable()
                    ->searchable(isIndividual: true)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amdg_id_empresa')
                    ->label('Empresa')
                    ->sortable()
                    ->searchable()
                    ->getStateUsing(function (object $record) {
                        $empresaId = $record->id_empresa;
                        $amdg_id_empresa = $record->amdg_id_empresa;

                        if (!$empresaId || !$amdg_id_empresa) {
                            return 'N/A (Faltan IDs)';
                        }

                        $connectionName = self::getExternalConnectionName($empresaId);

                        if (!$connectionName) {
                            return 'N/A (No hay conexión)';
                        }

                        try {
                            $empresa = DB::connection($connectionName)
                                ->table('saeempr')
                                ->where('empr_cod_empr', $amdg_id_empresa)
                                ->select(DB::raw(" '(' || empr_cod_empr || ') ' || empr_nom_empr AS nombre_empresa"))
                                ->first();

                            return $empresa->nombre_empresa ?? 'Empresa no encontrada';
                        } catch (\Exception $e) {
                            return 'Error DB';
                        }
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('presupuesto')
                    ->label('Presupuesto')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'PB' => 'warning',
                        'AZ' => 'success',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pedidos_importados')
                    ->label('Pedidos Importados')
                    ->searchable()
                    ->searchable(isIndividual: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('amdg_id_sucursal')
                    ->label('Sucursal')
                    ->sortable()
                    ->searchable()
                    ->getStateUsing(function (object $record) {
                        $empresaId = $record->id_empresa;
                        $amdg_id_sucursal = $record->amdg_id_sucursal;

                        if (!$empresaId || !$amdg_id_sucursal) {
                            return 'N/A (Faltan IDs)';
                        }

                        $connectionName = self::getExternalConnectionName($empresaId);

                        if (!$connectionName) {
                            return 'N/A (No hay conexión)';
                        }

                        try {
                            $sucursal = DB::connection($connectionName)
                                ->table('saesucu')
                                ->where('sucu_cod_sucu', $amdg_id_sucursal)
                                ->select(DB::raw(" '(' || sucu_cod_sucu || ') ' || sucu_nom_sucu AS nombre_sucursal"))
                                ->first();

                            return $sucursal->nombre_sucursal ?? 'Sucursal no encontrada';
                        } catch (\Exception $e) {
                            return 'Error DB';
                        }
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('identificacion')
                    ->label('Identificación')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('proveedor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('usuario.name')
                    ->label('Creado Por')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('trasanccion')
                    ->label('Transacción')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('fecha_pedido')
                    ->date()
                    ->label('F. Pedido')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('fecha_entrega')
                    ->date()
                    ->label('F. Entrega')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('uso_compra')
                    ->label('Uso Compra')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('solicitado_por')
                    ->label('Solicitado Por')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->sortable(),



                Tables\Columns\TextColumn::make('tipo_oc')
                    ->label('Tipo Orden Compra')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'REEMB' => 'REEMBOLSO',
                        'COMPRA' => 'COMPRA',
                        'PAGO' => 'PAGO',
                        'REGUL' => 'REGULARIZACIÓN',
                        'CAJAC' => 'CAJA CHICA',
                        default => 'Desconocido',
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'REEMB' => 'warning',
                        'COMPRA' => 'success',
                        'PAGO' => 'info',
                        'REGUL' => 'danger',
                        'CAJAC' => 'primary',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),



                Tables\Columns\TextColumn::make('observaciones')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('subtotal')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_descuento')
                    ->money('USD')
                    ->label('Descuento')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_impuesto')
                    ->money('USD')
                    ->label('Impuesto')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total')
                    ->money('USD')
                    ->label('Total')
                    ->sortable(),

                Tables\Columns\TextColumn::make('resumenDetalle.resumenPedido.descripcion')
                    ->label('Grupo Resumen')
                    ->getStateUsing(fn(OrdenCompra $record) => $record->resumenDetalle?->resumenPedido?->descripcion ?? 'Sin grupo de resumen')
                    ->toggleable(isToggledHiddenByDefault: true),



                Tables\Columns\IconColumn::make('anulada')
                    ->label('Anulada')
                    ->boolean(),
            ])
            ->filters([
                //ademas selecionada por defecto

                Filter::make('mis_ordenes')
                    ->label('Mis órdenes')
                    ->query(
                        fn(Builder $query): Builder =>
                        $query->whereBelongsTo(auth()->user(), 'usuario')
                    )
                    ->default(),

            ])
            ->actions([

                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->url(fn(OrdenCompra $record) => route('orden-compra.pdf', $record))
                    ->openUrlInNewTab(),

                /*
                |--------------------------------------------------------------------------
                | MODAL "Ver Productos" (DESACTIVADO TEMPORALMENTE)
                |--------------------------------------------------------------------------
                */
                // Tables\Actions\Action::make('verProductos')
                //     ->label('Ver Productos')
                //     ->icon('heroicon-o-eye')
                //     ->color('info')
                //     ->modalContent(fn(OrdenCompra $record): \Illuminate\Contracts\View\View => view(
                //         'filament.resources.orden-compra-resource.actions.ver-productos',
                //         ['detalles' => $record->detalles],
                //     ))
                //     ->modalSubmitAction(false)
                //     ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar')),


                Tables\Actions\Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn(OrdenCompra $record) => auth()->user()->can('Actualizar') && !$record->anulada)
                    ->action(function (OrdenCompra $record) {
                        $record->update(['anulada' => true]);

                        Notification::make()
                            ->title('Orden de compra anulada')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->requiresConfirmation()
                    ->visible(fn(OrdenCompra $record) => self::userIsAdmin())
                    ->authorize(fn() => self::userIsAdmin())
                //->disabled(fn(OrdenCompra $record) => $record->anulada),

            ])
            ->bulkActions([
                // Acciones masivas
                //Accion masiva para eliminar registros

            ]);
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('Actualizar') && !$record->anulada;
    }

    public static function canDelete(Model $record): bool
    {
        return self::userIsAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['usuario', 'resumenDetalle.resumenPedido']);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdenCompras::route('/'),
            'create' => Pages\CreateOrdenCompra::route('/create'),
            'edit' => Pages\EditOrdenCompra::route('/{record}/edit'),
        ];
    }
}
