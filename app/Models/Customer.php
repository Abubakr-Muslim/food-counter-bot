<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\LoggedMeal;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tg_id',
        'first_name',
        'last_name',
        'login',
        'state'
    ];

    public function customerInfo()
    {
        return $this->hasMany(customerInfo::class);
    }

    public function getDailyTotals() 
    {
        $targetDate = $date ?? Carbon::today();

        $dayStart = $targetDate->copy()->startOfDay(); 
        $dayEnd = $targetDate->copy()->endOfDay();

        $totals = LoggedMeal::where('customer_id', $this->id)
            ->whereBetween('logged_at', [$dayStart, $dayEnd])
            ->selectRaw('SUM(calories) as total_calories, SUM(protein) as total_protein, SUM(fat) as total_fat, SUM(carbs) as total_carbs')
            ->first();

        return [
            'total_calories' => (int)($totals->total_calories ?? 0),
            'total_protein' => round((float)($totals->total_protein ?? 0.0), 1),
            'total_fat' => round((float)($totals->total_fat ?? 0.0), 1),
            'total_carbs' => round((float)($totals->total_carbs ?? 0.0), 1),
        ];
    }
}
