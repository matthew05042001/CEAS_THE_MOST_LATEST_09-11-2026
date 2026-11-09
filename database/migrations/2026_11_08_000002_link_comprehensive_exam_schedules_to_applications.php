<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('comprehensive_exam_schedules', 'exam_application_id')) {
                $table->foreignId('exam_application_id')
                    ->nullable()
                    ->unique()
                    ->constrained('exam_application', 'application_id')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('comprehensive_exam_schedules', 'exam_application_id')) {
            Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_application_id');
            });
        }
    }
};
