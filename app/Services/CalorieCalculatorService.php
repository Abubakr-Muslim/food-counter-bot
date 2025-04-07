<?php

namespace App\Services;

use App\Models\CustomerInfo;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class CalorieCalculatorService
{
    private const ACTIVITY_FACTORS = [
        'Сидячий образ жизни' => 1.2,
        'Минимум активности' => 1.375,
        'Средняя активность' => 1.55,
        'Высокая активность' => 1.725,
    ];
    private const GOAL_ADJUSTMENTS = [
        'Сбросить вес' => -500,
        'Удержать вес' => 0,
        'Нарастить мышцы' => 400,
    ];
    private const MIN_CALORIES_MALE = 1400;
    private const MIN_CALORIES_FEMALE = 1200;

    public function calculateNorm(CustomerInfo $info): ?int
    {
        try {
            if (!$this->hasRequiredData($info)) {
                Log::warning("Calorie calculation failed: Missing or invalid data for CustomerInfo ID {$info->id}");
                return null;
            }

            $age = Carbon::parse($info->birthdate)->age;
            $weight = (float)$info->weight;
            $height = (int)$info->height;
            $gender = $info->gender;
            $activityLevel = $info->activity_level;
            $goal = $info->goal;

            $bmr = $this->calculateBMR($weight, $height, $age, $gender);
            if ($bmr === null) {
                return null;
            }

            $activityFactor = self::ACTIVITY_FACTORS[$activityLevel] ?? 1.2;
            $tdee = $bmr * $activityFactor;

            $goalAdjustment = self::GOAL_ADJUSTMENTS[$goal] ?? 0;
            $calculatedNorm = $tdee + $goalAdjustment;

            $minCalories = $gender === 'Мужской' ? self::MIN_CALORIES_MALE : self::MIN_CALORIES_FEMALE;
            if ($calculatedNorm < $minCalories) {
                $calculatedNorm = $minCalories;
                Log::info("Calorie norm adjusted to minimum ({$minCalories}) for CustomerInfo ID {$info->id}");
            }

            return (int)round($calculatedNorm);
        } catch (Exception $e) {
            Log::error("Error calculating calorie norm for CustomerInfo ID {$info->id}", [
                'error' => $e->getMessage(),
                'info_data' => $info->toArray()
            ]);
            return null;
        }
    }

    public function hasRequiredData(CustomerInfo $info): bool
    {
        return isset($info->weight) && $info->weight > 0 &&
               isset($info->height) && $info->height > 0 &&
               isset($info->birthdate) && Carbon::parse($info->birthdate)->isPast() &&
               isset($info->gender) && in_array($info->gender, ['Мужской', 'Женский']) &&
               isset($info->activity_level) && array_key_exists($info->activity_level, self::ACTIVITY_FACTORS) &&
               isset($info->goal) && array_key_exists($info->goal, self::GOAL_ADJUSTMENTS);
    }

    private function calculateBMR(float $weight, int $height, int $age, string $gender): ?float
    {
        if ($weight <= 0 || $height <= 0 || $age < 0) {
            Log::warning("Invalid BMR input: weight={$weight}, height={$height}, age={$age}");
            return null;
        }

        if ($gender === 'Мужской') {
            return (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        } elseif ($gender === 'Женский') {
            return (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        } else {
            Log::warning("Calculating BMR with undefined gender: {$gender}");
            return null;
        }
    }
}