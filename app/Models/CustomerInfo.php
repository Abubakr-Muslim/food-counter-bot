<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerInfo extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'goal',
        'gender',
        'birthdate',
        'activity_level',
        'height',
        'weight',
        'archive_at',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
