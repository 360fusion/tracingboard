<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Craft lodges take the app's navy instead of light blue, and the club types that are not
 * orders (rowing, rugby, boating) are black. Order colours are the same for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('club_types')->where('code', 'craft_lodge')->update(['colour' => 'navy']);
        DB::table('club_types')->whereIn('code', ['rowing', 'rugby', 'boating'])->update(['colour' => 'slate']);
    }

    public function down(): void
    {
        DB::table('club_types')->where('code', 'craft_lodge')->update(['colour' => 'sky']);
        DB::table('club_types')->where('code', 'rowing')->update(['colour' => 'blue']);
        DB::table('club_types')->where('code', 'rugby')->update(['colour' => 'green']);
    }
};
