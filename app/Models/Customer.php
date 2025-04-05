<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tg_id',
        'first_name',
        'last_name',
        'login'
    ];

    public function customerInfo()
    {
        return $this->hasMany(CustomerInfo::class);
    }
}
