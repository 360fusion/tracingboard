<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masonic_halls', function (Blueprint $table) {
            // No foreign key: clubs.masonic_hall_id already points back, and the copy command cannot order two tables that point at each other.
            $table->unsignedBigInteger('club_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('masonic_halls', function (Blueprint $table) {
            $table->dropColumn('club_id');
        });
    }
};
