<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ResumenPedidos;
use App\Models\User;

class OrdenCompra extends Model
{
    protected $fillable = [
        'id_empresa',
        'id_usuario',
        'amdg_id_empresa',
        'amdg_id_sucursal',
        'uso_compra',
        'solicitado_por',
        'numero_factura_proforma',
        'formato',
        'tipo_oc',
        'nombre_reembolso',
        'presupuesto',
        'trasanccion',
        'id_proveedor',
        'identificacion',
        'proveedor',
        'fecha_pedido',
        'fecha_entrega',
        'observaciones',
        'pedidos_importados',
        'subtotal',
        'total_descuento',
        'total_impuesto',
        'total',
    ];

    protected $casts = [
        'fecha_pedido' => 'date',
        'fecha_entrega' => 'date',
        'subtotal' => 'float',
        'total_descuento' => 'float',
        'total_impuesto' => 'float',
        'total' => 'float',
    ];

    // Relación con empresas
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleOrdenCompra::class, 'id_orden_compra');
    }

    public function resumenPedidos()
    {
        return $this->belongsToMany(
            ResumenPedidos::class,
            'detalle_resumen_pedidos',
            'id_orden_compra',
            'id_resumen_pedidos'
        );
    }

    protected static function booted()
    {
        static::deleting(function ($ordenCompra) {
            // Eliminar detalles asociados
            $ordenCompra->detalles()->each(function($detalle) {
                $detalle->delete();
            });
        });
    }
}
