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
        Schema::create('simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('venue_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('status')->default('paused')->index();
            $table->unsignedInteger('attendee_count');
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('intervention_at')->nullable();
            $table->timestamp('last_tick_at')->nullable();
            $table->json('definition');
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_runs');
    }
};
