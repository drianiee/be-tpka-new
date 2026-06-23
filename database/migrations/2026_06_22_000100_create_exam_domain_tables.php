<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedInteger('total_duration_minutes')->default(0);
            $table->unsignedInteger('total_questions')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_package_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order_number');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedInteger('question_count')->default(0);
            $table->timestamps();
            $table->unique(['question_package_id', 'order_number']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('multiple_choice');
            $table->text('question_text');
            $table->string('question_image')->nullable();
            $table->text('explanation')->nullable();
            $table->string('difficulty')->nullable();
            $table->decimal('score', 8, 2)->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order_number');
            $table->timestamps();
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('option_label', 10);
            $table->text('option_text');
            $table->string('option_image')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('order_number');
            $table->timestamps();
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_package_id')->constrained();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->timestamp('registration_start_at')->nullable();
            $table->timestamp('registration_end_at')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('quota')->default(100);
            $table->enum('status', ['draft', 'published', 'closed', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('schedule_id')->constrained();
            $table->string('order_id')->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->string('transaction_status')->default('pending');
            $table->string('payment_type')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('schedule_id')->constrained();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending_payment', 'paid', 'approved', 'cancelled', 'expired']);
            $table->enum('access_status', ['locked', 'unlocked'])->default('locked');
            $table->timestamp('registered_at');
            $table->timestamps();
            $table->unique(['user_id', 'schedule_id']);
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('schedule_id')->constrained();
            $table->foreignId('question_package_id')->constrained();
            $table->enum('status', ['in_progress', 'submitted', 'expired', 'cancelled']);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score_total', 10, 2)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained();
            $table->foreignId('exam_section_id')->constrained();
            $table->unsignedInteger('order_number');
            $table->text('snapshot_question_text');
            $table->string('snapshot_question_image')->nullable();
            $table->json('snapshot_options');
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'question_id']);
        });

        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained();
            $table->foreignId('question_option_id')->nullable()->constrained()->nullOnDelete();
            $table->text('answer_text')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'question_id']);
        });

        Schema::create('attempt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('correct_count');
            $table->unsignedInteger('wrong_count');
            $table->unsignedInteger('unanswered_count');
            $table->decimal('raw_score', 10, 2);
            $table->decimal('final_score', 10, 2);
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'exam_section_id']);
        });
    }

    public function down(): void
    {
        foreach (['exam_scores', 'attempt_events', 'attempt_answers', 'attempt_questions', 'exam_attempts', 'exam_registrations', 'payments', 'schedules', 'partners', 'question_options', 'questions', 'exam_sections', 'question_packages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
