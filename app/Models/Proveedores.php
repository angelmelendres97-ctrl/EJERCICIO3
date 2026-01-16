<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proveedores extends Model
{
    use HasFactory;

    protected $table = 'proveedores';

    protected $fillable = [
        'id_empresa',
        'amdg_id_empresa',
        'amdg_id_sucursal',
        'tipo',
        'ruc',
        'nombre',
        'nombre_comercial',
        'grupo',
        'zona',
        'flujo_caja',
        'tipo_proveedor',
        'forma_pago',
        'destino_pago',
        'pais_pago',
        'dias_pago',
        'limite_credito',
        'aplica_retencion_sn',
        'telefono',
        'direcccion',
        'correo',
    ];

    public function getAmdgIdEmpresaAttribute(): ?int
    {
        return $this->attributes['admg_id_empresa'] ?? null;
    }

    public function setAmdgIdEmpresaAttribute($value): void
    {
        $this->attributes['admg_id_empresa'] = $value;
    }

    public function getAmdgIdSucursalAttribute(): ?int
    {
        return $this->attributes['admg_id_sucursal'] ?? null;
    }

    public function setAmdgIdSucursalAttribute($value): void
    {
        $this->attributes['admg_id_sucursal'] = $value;
    }

    public function lineasNegocio()
    {
        return $this->belongsToMany(LineaNegocio::class, 'proveedor_linea_negocios', 'proveedor_id', 'linea_negocio_id');
    }

    // Relación con empresas
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }
}
