<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateCode extends Model
{
    protected $table = 'affiliates_codes';
    public $timestamps = false;
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public function getAffiliateUrl()
    {
        return url('/reff/' . $this->code);
    }

    public function user()
    {
        return $this->belongsTo('App\User', 'user_id', 'id');
    }
}
