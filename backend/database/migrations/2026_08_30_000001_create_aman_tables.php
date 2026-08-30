<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_stream_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('event_stream_locks')->insert(['id' => 1]);
        Schema::create('zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->double('area_sqm');
            $table->double('warning_density');
            $table->double('critical_density');
            $table->double('people_per_device')->nullable();
            $table->string('calibration_note', 500)->nullable();
            $table->double('latitude');
            $table->double('longitude');
            $table->string('risk_level')->default('unknown');
            $table->timestampTz('last_observed_at', 6)->nullable();
            $table->json('latest_reading')->nullable();
            $table->timestampsTz();
        });

        Schema::create('responders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('role');
            $table->text('phone_number'); // encrypted cast; never broadcast
            $table->boolean('authorized')->default(false);
            $table->boolean('available')->default(true);
            $table->json('signals')->nullable();
            $table->timestampsTz();
            $table->index(['available', 'role']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('zone_id')->constrained();
            // One open incident per zone; NULL permits multiple historical incidents.
            $table->uuid('active_zone_id')->nullable()->unique();
            $table->foreignUuid('responder_id')->nullable()->constrained();
            $table->uuid('assigned_responder_id')->nullable()->unique();
            $table->string('status')->default('detected')->index();
            $table->string('required_role')->default('crowd_marshal');
            $table->string('source');
            $table->json('decision')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('crowd_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('zone_id')->constrained();
            $table->uuid('sample_id')->unique();
            $table->string('payload_hash', 64);
            $table->unsignedInteger('device_count');
            $table->string('source');
            $table->timestampTz('observed_at', 6);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['zone_id', 'observed_at']);
        });

        Schema::create('domain_events', function (Blueprint $table) {
            $table->id(); // Monotonic replay cursor, serialized as a string for JS clients.
            $table->uuid('event_id')->unique();
            $table->string('type')->index();
            $table->uuid('zone_id')->nullable();
            $table->uuid('incident_id')->nullable()->index();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->json('payload');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('published_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('crowd_observations');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('responders');
        Schema::dropIfExists('zones');
        Schema::dropIfExists('event_stream_locks');
    }
};
