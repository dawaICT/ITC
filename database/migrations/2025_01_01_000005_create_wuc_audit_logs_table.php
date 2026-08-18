<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the WUC Laravel audit log table.
 * Named wuc_audit_logs to avoid conflict with legacy audit_log table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wuc_audit_logs')) {
            Schema::create('wuc_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable()->comment('users.id');
                $table->string('actor_type')->nullable()->comment('student|staff|system');
                $table->string('action')->index()->comment('e.g. login, role_change, result_publish');
                $table->string('subject_type')->nullable()->comment('e.g. App\\Models\\Student');
                $table->string('subject_id')->nullable()->comment('The subject\'s primary key');
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('correlation_id')->nullable()->index();
                $table->enum('portal', ['academic', 'elearning', 'admin', 'system'])->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
                $table->index('actor_id');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wuc_audit_logs');
    }
};
