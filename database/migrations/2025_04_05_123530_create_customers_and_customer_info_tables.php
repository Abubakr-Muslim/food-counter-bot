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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('login')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('goal');
            $table->string('gender');
            $table->date('birthdate');
            $table->string('activity_level');
            $table->integer('height');
            $table->decimal('weight');
            $table->timestamp('created_at'); 
            $table->timestamp('updated_at'); 
            $table->timestamp('archive_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_info');
    }
};
