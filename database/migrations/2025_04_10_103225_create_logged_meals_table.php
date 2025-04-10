<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('logged_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('food_name', 500);
            $table->integer('grams')->unsigned()->nullable();
            $table->integer('calories')->unsigned();
            $table->decimal('protein', 8, 2)->unsigned()->default(0);
            $table->decimal('fat', 8, 2)->unsigned()->default(0);
            $table->decimal('carbs', 8, 2)->unsigned()->default(0);
            
            $table->timestamp('logged_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logged_meals');
    }
};
