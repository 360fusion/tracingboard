<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $types = [
            'province_group' => ['Province group', ['communications', 'website_builder'], ['club' => 'Province', 'members' => 'People'], 'slate'],
            'province_section' => ['Province section', ['accounting', 'communications', 'events', 'newsletters', 'website_builder'], ['club' => 'Section', 'members' => 'People'], 'violet'],
        ];

        foreach ($types as $code => [$name, $modules, $terminology, $colour]) {
            if (DB::table('club_types')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('club_types')->insert([
                'code' => $code,
                'colour' => $colour,
                'name' => $name,
                'available_modules' => json_encode($modules),
                'default_settings' => json_encode([]),
                'terminology' => json_encode($terminology),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('clubs', function (Blueprint $table) {
            $table->foreignId('parent_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('order_club_type_id')->nullable()->constrained('club_types');
            $table->unique(['parent_club_id', 'order_club_type_id']);
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropUnique(['parent_club_id', 'order_club_type_id']);
            $table->dropConstrainedForeignId('parent_club_id');
            $table->dropConstrainedForeignId('order_club_type_id');
        });

        DB::table('club_types')->whereIn('code', ['province_group', 'province_section'])->delete();
    }
};
