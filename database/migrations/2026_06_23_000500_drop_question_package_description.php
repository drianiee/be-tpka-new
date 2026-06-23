<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_packages', function (Blueprint $table) {
            if (Schema::hasColumn('question_packages', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('question_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('question_packages', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });
    }
};
