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
        Schema::table('zones', function (Blueprint $table) {
            $table->string('demo_key')->nullable()->unique();
            $table->json('boundary')->nullable();
            $table->json('scenario')->nullable();
            $table->json('population_context')->nullable();
        });
        Schema::table('responders', function (Blueprint $table) {
            $table->string('demo_key')->nullable()->unique();
            $table->json('demo_position')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('responder_id')->nullable()->unique()->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('responder_id'));
        Schema::table('responders', fn (Blueprint $table) => $table->dropColumn(['demo_key', 'demo_position']));
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn(['demo_key', 'boundary', 'scenario', 'population_context']));
    }
};
