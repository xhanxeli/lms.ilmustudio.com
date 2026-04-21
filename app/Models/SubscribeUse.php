<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeUse extends Model
{
    protected $table = 'subscribe_uses';
    public $timestamps = false;
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    
    protected $casts = [
        'active' => 'boolean',
    ];

    public function subscribe()
    {
        return $this->belongsTo('App\Models\Subscribe', 'subscribe_id', 'id');
    }

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale', 'sale_id', 'id');
    }

    public function installmentOrder()
    {
        return $this->belongsTo('App\Models\InstallmentOrder', 'installment_order_id', 'id');
    }

    public function expire()
    {
        $this->active = false;
        $this->expired_at = time();
        $this->save();
    }

    public function isActive()
    {
        return $this->active && (is_null($this->expired_at) || $this->expired_at > time());
    }
}
