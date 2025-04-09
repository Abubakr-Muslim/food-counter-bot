<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerInfo extends Model
{
    use HasFactory;

    protected $table = 'customer_info';
    protected $fillable = [
        'customer_id',
        'goal',
        'gender',
        'birth_year',
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
