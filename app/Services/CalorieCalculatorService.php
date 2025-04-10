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
    private const KCAL_PER_PROTEIN = 4;
    private const KCAL_PER_FAT = 9;
    private const KCAL_PER_CARB = 4;
    private const MIN_CALORIES_MALE = 1400;
    private const MIN_CALORIES_FEMALE = 1200;
    private const PROTEIN_PER_KG = [
        'Сбросить вес' => 1.8,
        'Удержать вес' => 1.4,
        'Нарастить мышцы' => 1.8,
    ];
    private const MIN_FAT_PER_KG = 0.8;
    private const FAT_PERCENT_DEFAULT = 25;

    public function calculateNorm(CustomerInfo $info): ?array
    {
        try {
            Log::debug("CalculatorService: Checking data for CustomerInfo.", ['info_object' => $info->toArray()]);

            if (!$this->hasRequiredData($info)) {
                Log::warning("Calorie calculation failed: Missing or invalid data for CustomerInfo ID {$info->id}");
                return null;
            }

            $age = Carbon::now()->year - $info->birth_year;
            $weight = (float)$info->weight;
            $height = (int)$info->height;
            $gender = $info->gender;
            $activityLevel = $info->activity_level;
            $goal = $info->goal;

            $bmr = $this->calculateBMR($weight, $height, $age, $gender);
            if ($bmr === null) {
                return null;
            }

            $activityFactor = self::ACTIVITY_FACTORS[$activityLevel] ?? self::ACTIVITY_FACTORS['Сидячий образ жизни'];
            $tdee = $bmr * $activityFactor;

            $goalAdjustment = self::GOAL_ADJUSTMENTS[$goal] ?? self::GOAL_ADJUSTMENTS['Удержать вес'];
            $calculatedNorm = $tdee + $goalAdjustment;

            $minCalories = $gender === 'Мужской' ? self::MIN_CALORIES_MALE : self::MIN_CALORIES_FEMALE;
            if ($calculatedNorm < $minCalories) {
                $calculatedNorm = $minCalories;
                Log::info("Calorie norm adjusted to minimum ({$minCalories}) for CustomerInfo ID {$info->id}");
            }

            $calculatedNorm = (int)round($calculatedNorm);

            $proteinGrams = $weight * self::PROTEIN_PER_KG[$goal];
            $proteinCalories = $proteinGrams * self::KCAL_PER_PROTEIN;

            $minFatGrams = $weight * self::MIN_FAT_PER_KG;
            $remainingCalories = $calculatedNorm - $proteinCalories;
            $fatCalories = max($minFatGrams * self::KCAL_PER_FAT, $remainingCalories * (self::FAT_PERCENT_DEFAULT / 100));
            $fatGrams = $fatCalories / self::KCAL_PER_FAT;

            $carbCalories = $calculatedNorm - $proteinCalories - $fatCalories;
            $carbGrams = $carbCalories / self::KCAL_PER_CARB;

            if ($carbGrams < 0) {
                $fatGrams = $minFatGrams;
                $fatCalories = $fatGrams * self::KCAL_PER_FAT;
                $carbCalories = $calculatedNorm - $proteinCalories - $fatCalories;
                $carbGrams = max(0, $carbCalories / self::KCAL_PER_CARB);
            }

            return [
                'calories' => $calculatedNorm,
                'protein' => (int)round($proteinGrams),
                'fat' => (int)round($fatGrams),
                'carbs' => (int)round($carbGrams),
            ];
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
        $checks = [];
        $checks['weight_check'] = isset($info->weight) && $info->weight > 0;
        $checks['height_check'] = isset($info->height) && $info->height > 0;
        $checks['birth_year_check'] = isset($info->birth_year) && $info->birth_year > (Carbon::now()->year - 120) && $info->birth_year <= Carbon::now()->year;
        $checks['gender_check'] = isset($info->gender) && in_array($info->gender, ['Мужской', 'Женский']);
        $checks['activity_check'] = isset($info->activity_level) && array_key_exists($info->activity_level, self::ACTIVITY_FACTORS);
        $checks['goal_check'] = isset($info->goal) && array_key_exists($info->goal, self::GOAL_ADJUSTMENTS);

        $finalResult = !in_array(false, $checks, true);

        Log::debug("CalculatorService: hasRequiredData check results.", [
            'info_id' => $info->id,
            'checks' => $checks,
            'final_result' => $finalResult 
        ]);
    
        return $finalResult;
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