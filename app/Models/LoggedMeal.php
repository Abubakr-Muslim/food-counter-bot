<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoggedMeal extends Model
{
    protected $fillable = [
        'customer_id',
        'food_name',
        'grams',
        'calories',
        'protein',
        'fat',
        'carbs',
        'logged_at',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
