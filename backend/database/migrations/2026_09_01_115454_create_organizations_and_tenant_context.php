<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 140)->unique();
                $table->string('status', 20)->default('pending')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'organization_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->string('role', 20)->default('viewer')->after('password')->index();
                $table->string('status', 20)->default('active')->after('role')->index();
            });
        }

        if (! Schema::hasTable('venue_events')) {
            Schema::create('venue_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 140);
                $table->string('venue_name', 140);
                $table->timestampTz('starts_at')->nullable();
                $table->timestampTz('ends_at')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->timestampsTz();
                $table->index(['organization_id', 'starts_at']);
            });
        }

        if (! Schema::hasTable('organization_invitations')) {
            Schema::create('organization_invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
                $table->string('email');
                $table->string('role', 20);
                $table->string('token_hash', 64)->unique();
                $table->timestampTz('expires_at');
                $table->timestampTz('accepted_at')->nullable();
                $table->timestampsTz();
                $table->unique(['organization_id', 'email']);
                $table->index(['email', 'expires_at']);
            });
        }

        foreach (['zones', 'responders', 'incidents', 'domain_events'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
                    if (in_array($tableName, ['zones', 'responders', 'incidents'], true)) {
                        $table->foreignUuid('venue_event_id')->nullable()->constrained('venue_events')->nullOnDelete();
                    }
                    $table->index(['organization_id', $tableName === 'domain_events' ? 'occurred_at' : 'created_at']);
                });
            }
        }
        if (! Schema::hasIndex('domain_events', ['organization_id', 'occurred_at'])) {
            Schema::table('domain_events', fn (Blueprint $table) => $table->index(['organization_id', 'occurred_at']));
        }
        foreach (['zones', 'responders'] as $tableName) {
            if (Schema::hasIndex($tableName, $tableName.'_demo_key_unique')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique($tableName.'_demo_key_unique'));
            }
            if (! Schema::hasIndex($tableName, ['organization_id', 'demo_key'])) {
                Schema::table($tableName, fn (Blueprint $table) => $table->unique(['organization_id', 'demo_key']));
            }
        }

        $hasLegacyData = collect(['users', 'zones', 'responders', 'incidents', 'domain_events'])
            ->contains(fn (string $tableName) => DB::table($tableName)->exists());
        if ($hasLegacyData) {
            $organizationId = DB::table('organizations')->where('slug', 'aman-demo')->value('id');
            $organizationId ??= DB::table('organizations')->insertGetId([
                'name' => 'AMAN Demo Organization', 'slug' => 'aman-demo', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['users', 'zones', 'responders', 'incidents', 'domain_events'] as $tableName) {
                DB::table($tableName)->whereNull('organization_id')->update(['organization_id' => $organizationId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'demo_key']);
            $table->unique('demo_key');
        });
        Schema::table('responders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'demo_key']);
            $table->unique('demo_key');
        });
        foreach (['domain_events', 'incidents', 'responders', 'zones'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign(['organization_id']);
                $table->dropColumn('organization_id');
                if (in_array($tableName, ['zones', 'responders', 'incidents'], true)) {
                    $table->dropForeign(['venue_event_id']);
                    $table->dropColumn('venue_event_id');
                }
            });
        }
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('venue_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'role', 'status']);
        });
        Schema::dropIfExists('organizations');
    }
};
