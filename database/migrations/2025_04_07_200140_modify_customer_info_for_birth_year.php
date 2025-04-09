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
        Schema::table('customer_info', function (Blueprint $table) {
            $table->dropColumn('birthdate');
            $table->integer('birth_year')->unsigned()->nullable()->after('gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_info', function (Blueprint $table) {
            $table->dropColumn('birth_year');
            $table->date('birthdate')->after('gender')->nullable(); 
        });
    }
};
