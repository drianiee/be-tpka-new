<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_packages', function (Blueprint $table) {
            $table->unsignedInteger('base_score')->default(200)->after('target_total_questions');
            $table->unsignedInteger('correct_answer_score')->default(2)->after('base_score');
        });
    }

    public function down(): void
    {
        Schema::table('question_packages', function (Blueprint $table) {
            $table->dropColumn(['base_score', 'correct_answer_score']);
        });
    }
};
