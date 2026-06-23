<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_packages', function (Blueprint $table) {
            $table->unsignedInteger('target_total_questions')->default(0)->after('total_questions');
        });

        Schema::table('exam_sections', function (Blueprint $table) {
            $table->unsignedInteger('target_question_count')->default(0)->after('question_count');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sections', function (Blueprint $table) {
            $table->dropColumn('target_question_count');
        });

        Schema::table('question_packages', function (Blueprint $table) {
            $table->dropColumn('target_total_questions');
        });
    }
};
