<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('period')->nullable();
            $table->unsignedInteger('year');
            $table->string('sign_name')->nullable();
            $table->string('sign_nip')->nullable();
            $table->string('letter_prefix');
            $table->string('template_file');
            $table->string('sign_file')->nullable();
            $table->string('stamp_file')->nullable();
            $table->json('layout_json')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->foreignId('certificate_id')->nullable()->after('payment_id')->constrained('certificates')->nullOnDelete();
            $table->string('certificate_no')->nullable()->after('retake_consumed_at')->unique();
            $table->uuid('certificate_verify_uuid')->nullable()->after('certificate_no')->unique();
            $table->string('certificate_verify_hash', 64)->nullable()->after('certificate_verify_uuid');
            $table->timestamp('certificate_issued_at')->nullable()->after('certificate_verify_hash');
            $table->string('certificate_file_path')->nullable()->after('certificate_issued_at');
            $table->timestamp('certificate_revoked_at')->nullable()->after('certificate_file_path');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('certificate_id')->nullable()->after('partner_id')->constrained('certificates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_id');
        });

        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_id');
            $table->dropUnique(['certificate_no']);
            $table->dropUnique(['certificate_verify_uuid']);
            $table->dropColumn([
                'certificate_no',
                'certificate_verify_uuid',
                'certificate_verify_hash',
                'certificate_issued_at',
                'certificate_file_path',
                'certificate_revoked_at',
            ]);
        });
        Schema::dropIfExists('certificates');
    }
};
