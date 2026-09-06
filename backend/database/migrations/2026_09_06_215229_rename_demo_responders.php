<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->rename([
            0 => 'Baraa El Baba',
            1 => 'Mohammed Khayyat',
            2 => 'Hussien Ghaddar',
            3 => 'Siraj Alaeli',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->rename([
            0 => 'East Marshal',
            1 => 'Concourse Marshal',
            2 => 'West Marshal',
            3 => 'Reserve Marshal',
        ]);
    }

    /** @param array<int, string> $names */
    private function rename(array $names): void
    {
        foreach ($names as $index => $name) {
            DB::table('responders')
                ->where('demo_key', 'like', '%:responder:'.$index)
                ->update(['name' => $name]);
        }
    }
};
