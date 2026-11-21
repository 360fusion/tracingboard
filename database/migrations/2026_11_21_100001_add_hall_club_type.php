<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('club_types')->where('code', 'hall')->exists()) {
            return;
        }

        DB::table('club_types')->insert([
            'code' => 'hall',
            'colour' => 'sky',
            'name' => 'Masonic hall',
            'available_modules' => json_encode(['accounting', 'communications', 'events', 'newsletters', 'website_builder']),
            'default_settings' => json_encode([]),
            'terminology' => json_encode(['club' => 'Hall', 'members' => 'Board', 'master' => 'Chairman']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('club_types')->where('code', 'hall')->delete();
    }
};
