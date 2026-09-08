<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The operator's current zone focus, so the Unity second screen can follow the
     * console without the two clients talking to each other.
     */
    public function up(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table): void {
            $table->json('focus')->nullable()->after('interventions');
        });
    }

    public function down(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table): void {
            $table->dropColumn('focus');
        });
    }
};
