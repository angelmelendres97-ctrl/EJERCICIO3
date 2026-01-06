<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResumenPedidos extends Model
{
    protected $fillable = [
        'id_empresa',
        'amdg_id_empresa',
        'amdg_id_sucursal',
        'codigo_secuencial',
        'tipo',
        'descripcion',
    ];

    // Relación con empresas
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleResumenPedidos::class, 'id_resumen_pedidos');
    }

}
