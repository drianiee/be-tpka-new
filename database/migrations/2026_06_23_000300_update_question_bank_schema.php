<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sections', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
            $table->unique(['question_package_id', 'code']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->text('question_text')->nullable()->change();
        });

        Schema::table('question_options', function (Blueprint $table) {
            $table->text('option_text')->nullable()->change();
        });

        Schema::table('attempt_questions', function (Blueprint $table) {
            $table->text('snapshot_question_text')->nullable()->change();
        });

        DB::table('questions')->where('type', 'single_choice')->update(['type' => 'multiple_choice']);
    }

    public function down(): void
    {
        Schema::table('exam_sections', function (Blueprint $table) {
            $table->dropUnique(['question_package_id', 'code']);
            $table->dropColumn('code');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->text('question_text')->nullable(false)->change();
        });

        Schema::table('question_options', function (Blueprint $table) {
            $table->text('option_text')->nullable(false)->change();
        });

        Schema::table('attempt_questions', function (Blueprint $table) {
            $table->text('snapshot_question_text')->nullable(false)->change();
        });
    }
};
