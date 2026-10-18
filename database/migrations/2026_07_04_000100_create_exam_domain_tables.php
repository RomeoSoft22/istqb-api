<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->string('description')->nullable(); $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->string('description')->nullable(); $table->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('chapters', function (Blueprint $table) {
            $table->id(); $table->string('code', 20)->unique(); $table->string('name'); $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->id(); $table->foreignId('chapter_id')->constrained()->cascadeOnDelete(); $table->string('code', 50)->nullable();
            $table->string('name'); $table->text('description')->nullable(); $table->boolean('active')->default(true); $table->timestamps(); $table->softDeletes();
            $table->unique(['chapter_id', 'name']);
        });
        Schema::create('questions', function (Blueprint $table) {
            $table->id(); $table->foreignId('chapter_id')->constrained()->restrictOnDelete();
            $table->foreignId('learning_objective_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('content'); $table->longText('comment')->nullable(); $table->enum('k_level', ['K1','K2','K3']);
            $table->enum('status', ['draft','active','inactive'])->default('draft')->index(); $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('question_options', function (Blueprint $table) {
            $table->id(); $table->foreignId('question_id')->constrained()->cascadeOnDelete(); $table->longText('content');
            $table->boolean('is_correct')->default(false)->index(); $table->boolean('active')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('exam_configurations', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedSmallInteger('total_questions')->default(40);
            $table->unsignedSmallInteger('duration_minutes')->default(60); $table->unsignedSmallInteger('passing_correct_answers')->default(26);
            $table->unsignedSmallInteger('daily_attempt_limit')->default(10); $table->boolean('show_feedback_at_end')->default(true);
            $table->boolean('captcha_enabled')->default(true); $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('exam_chapter_distributions', function (Blueprint $table) {
            $table->foreignId('exam_configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->restrictOnDelete(); $table->unsignedSmallInteger('question_count');
            $table->primary(['exam_configuration_id', 'chapter_id']);
        });
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id(); $table->string('attempt_number', 20)->nullable()->unique(); $table->string('resume_token_hash', 64)->unique();
            $table->foreignId('exam_configuration_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['in_progress','completed','expired','abandoned'])->default('in_progress')->index();
            $table->timestamp('started_at')->index(); $table->timestamp('expires_at')->index(); $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable(); $table->string('ip_address', 45)->index(); $table->text('user_agent')->nullable();
            $table->string('browser')->nullable(); $table->string('browser_version')->nullable(); $table->string('operating_system')->nullable();
            $table->string('device_type', 30)->nullable(); $table->json('metadata')->nullable(); $table->timestamp('captcha_verified_at')->nullable();
            $table->unsignedSmallInteger('correct_answers')->default(0); $table->unsignedSmallInteger('incorrect_answers')->default(0);
            $table->unsignedSmallInteger('unanswered_questions')->default(40); $table->unsignedSmallInteger('score')->default(0);
            $table->boolean('passed')->default(false); $table->string('completion_type', 30)->nullable(); $table->timestamps();
            $table->index(['ip_address', 'started_at']);
        });
        Schema::create('attempt_questions', function (Blueprint $table) {
            $table->id(); $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete(); $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('learning_objective_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('question_snapshot'); $table->longText('comment_snapshot')->nullable(); $table->string('k_level_snapshot', 2);
            $table->unsignedSmallInteger('position'); $table->boolean('is_correct')->nullable(); $table->timestamp('answered_at')->nullable(); $table->timestamps();
            $table->unique(['exam_attempt_id','position']);
        });
        Schema::create('attempt_options', function (Blueprint $table) {
            $table->id(); $table->foreignId('attempt_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_option_id')->nullable()->constrained()->nullOnDelete(); $table->longText('option_snapshot');
            $table->boolean('is_correct_snapshot'); $table->unsignedSmallInteger('position'); $table->timestamps();
            $table->unique(['attempt_question_id','position']);
        });
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id(); $table->foreignId('attempt_question_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_correct')->default(false); $table->timestamp('answered_at'); $table->unsignedInteger('response_time_seconds')->nullable(); $table->timestamps();
        });
        Schema::create('attempt_selected_options', function (Blueprint $table) {
            $table->foreignId('attempt_answer_id')->constrained()->cascadeOnDelete(); $table->foreignId('attempt_option_id')->constrained()->cascadeOnDelete();
            $table->primary(['attempt_answer_id','attempt_option_id']);
        });
        Schema::create('question_imports', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('original_name');
            $table->string('stored_path'); $table->enum('status', ['processing','completed','failed'])->default('processing');
            $table->unsignedInteger('total_rows')->default(0); $table->unsignedInteger('imported_rows')->default(0); $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamps();
        });
        Schema::create('question_import_errors', function (Blueprint $table) {
            $table->id(); $table->foreignId('question_import_id')->constrained()->cascadeOnDelete(); $table->unsignedInteger('row_number');
            $table->string('column_name')->nullable(); $table->text('message'); $table->json('row_data')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('action');
            $table->string('entity_type'); $table->unsignedBigInteger('entity_id')->nullable(); $table->json('old_values')->nullable();
            $table->json('new_values')->nullable(); $table->string('ip_address',45)->nullable(); $table->text('user_agent')->nullable(); $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type','entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs','question_import_errors','question_imports','attempt_selected_options','attempt_answers','attempt_options','attempt_questions','exam_attempts','exam_chapter_distributions','exam_configurations','question_options','questions','learning_objectives','chapters','role_permissions','user_roles','permissions','roles'] as $table) Schema::dropIfExists($table);
    }
};
