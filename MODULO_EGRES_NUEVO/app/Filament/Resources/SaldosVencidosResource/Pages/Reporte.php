<?php

namespace App\Filament\Resources\SaldosVencidosResource\Pages;

use App\Filament\Resources\SaldosVencidosResource;
use App\Models\SaldoVencidoTemporal;
use Filament\Resources\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Support\Facades\DB;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Filament\Forms\Components\Actions;

class Reporte extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string $resource = SaldosVencidosResource::class;

    protected static string $view = 'filament.resources.saldos-vencidos-resource.pages.reporte';

    protected static ?string $title = 'Reporte Saldos Vencidos';

    public ?array $data = [];
    public bool $consultado = false;
    protected $listeners = ['updateTable' => '$refresh'];

    public function mount(): void
    {
        $this->form->fill([
            'fecha_desde' => now()->startOfMonth(),
            'fecha_hasta' => now()->endOfMonth(),
        ]);

        Schema::connection('sqlite_memory')->dropIfExists('temp_saldos');
        Schema::connection('sqlite_memory')->create('temp_saldos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('proveedor')->nullable();
            $table->string('documento')->nullable();
            $table->string('detalle')->nullable();
            $table->date('emision')->nullable();
            $table->date('vencimiento')->nullable();
            $table->decimal('saldo_ml', 18, 2)->nullable();
            $table->decimal('saldo_me', 18, 2)->nullable();
            $table->string('estado')->nullable();
        });
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Filtros del Reporte')
                    ->schema([
                        Forms\Components\Select::make('conexiones')
                            ->label('Conexiones')
                            ->options(Empresa::query()->pluck('nombre_empresa', 'id'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_desde')
                            ->label('Fecha Desde'),
                        Forms\Components\DatePicker::make('fecha_hasta')
                            ->label('Fecha Hasta'),
                        Actions::make([
                            Actions\Action::make('consultar')
                                ->label('Consultar')
                                ->action('consultar'),
                        ])->alignCenter(),
                    ])->columns(4),
            ]);
    }

    public function consultar()
    {
        $this->consultado = true;
        
        $formData = $this->form->getState();
        $conexiones = $formData['conexiones'] ?? [];

                
        foreach ($conexiones as $conexionId) {
            $connectionName = SaldosVencidosResource::getExternalConnectionName($conexionId);
            if (!$connectionName) continue;

            try {
                // Assuming saeclpv table for provider name from clpv_cod_clpv
                $query = DB::connection($connectionName)->table('saedmcp as mcp')
                    ->join('saeclpv as clpv', 'mcp.clpv_cod_clpv', '=', 'clpv.clpv_cod_clpv')
                    ->select(
                        'clpv.clpv_nom_clpv as proveedor',
                        'mcp.dmcp_num_fac as documento',
                        'mcp.dmcp_det_dcmp as detalle',
                        'mcp.dcmp_fec_emis as emision',
                        'mcp.dmcp_fec_ven as vencimiento',
                        'mcp.dmcp_mon_ml as saldo_ml',
                        'mcp.dmcp_mon_ext as saldo_me',
                        'mcp.dmcp_est_dcmp as estado'
                    );

                if (!empty($formData['fecha_desde']) && !empty($formData['fecha_hasta'])) {
                    $query->whereBetween('mcp.dmcp_fec_ven', [$formData['fecha_desde'], $formData['fecha_hasta']]);
                }
                
                $records = $query->get();

                // Insert into sqlite memory table
                SaldoVencidoTemporal::insert($records->map(fn($r) => (array)$r)->toArray());

            } catch (\Exception $e) {
                // You can add logging or a notification here
            }
        }

        $this->dispatch('updateTable');
    }

    protected function getTableQuery(): Builder
    {
        if (!$this->consultado) {
            return SaldoVencidoTemporal::query()->whereRaw('1=0');
        }
        return SaldoVencidoTemporal::query();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('proveedor')->label('Proveedor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('documento')->label('Documento')->searchable(),
                Tables\Columns\TextColumn::make('detalle')->label('Detalle')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('emision')->label('Emisión')->date()->sortable(),
                Tables\Columns\TextColumn::make('vencimiento')->label('Vencimiento')->date()->sortable(),
                Tables\Columns\TextColumn::make('saldo_ml')->label('Saldo ML')->money('USD')->summarize(Tables\Columns\Summarizers\Sum::make()->money('USD'))->sortable(),
                Tables\Columns\TextColumn::make('saldo_me')->label('Saldo ME')->money('USD')->summarize(Tables\Columns\Summarizers\Sum::make()->money('USD'))->sortable(),
                Tables\Columns\TextColumn::make('estado')->label('Estado')->badge()->searchable()->sortable(),
            ])
            ->paginated([10, 25, 50, 100])
            ->striped();
    }
}