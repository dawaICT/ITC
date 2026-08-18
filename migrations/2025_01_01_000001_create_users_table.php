<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the central Laravel users table.
 * This is NEW — it bridges legacy student/staff tables to unified auth.
 * It does NOT replace legacy students or staff tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->enum('user_type', ['student', 'staff', 'employer', 'alumni'])->default('student');
            $table->string('legacy_id')->nullable()->comment('SID or staff_id from legacy tables');
            $table->string('legacy_table')->nullable()->comment('students or staff');
            $table->enum('status', ['active', 'suspended', 'pending', 'inactive'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->integer('failed_login_count')->default(0);
            $table->timestamp('lockout_until')->nullable();
            $table->timestamps();

            $table->index(['user_type', 'status']);
            $table->index('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
