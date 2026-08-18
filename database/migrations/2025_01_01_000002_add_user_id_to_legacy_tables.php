<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add user_id FK to existing legacy tables (students, staff).
 * Uses IF NOT EXISTS — safe to run on production.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add user_id to students table (legacy PK is SID varchar)
        if (Schema::hasTable('students') && !Schema::hasColumn('students', 'user_id')) {
            Schema::table('students', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('SID');
                $table->index('user_id');
            });
        }

        // Add user_id to staff table (legacy PK is staff_id varchar)
        if (Schema::hasTable('staff') && !Schema::hasColumn('staff', 'user_id')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('staff_id');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'user_id')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
        if (Schema::hasTable('staff') && Schema::hasColumn('staff', 'user_id')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
    }
};
