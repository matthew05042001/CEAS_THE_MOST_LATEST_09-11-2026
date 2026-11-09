<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_application', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_application', 'exam_clearance_status')) {
                $table->string('exam_clearance_status')->nullable();
            }

            if (! Schema::hasColumn('exam_application', 'exam_clearance_remarks')) {
                $table->text('exam_clearance_remarks')->nullable();
            }

            if (! Schema::hasColumn('exam_application', 'exam_cleared_by')) {
                $table->foreignId('exam_cleared_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('exam_application', 'exam_cleared_at')) {
                $table->timestamp('exam_cleared_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_application', function (Blueprint $table) {
            if (Schema::hasColumn('exam_application', 'exam_cleared_by')) {
                $table->dropConstrainedForeignId('exam_cleared_by');
            }

            $columns = array_filter([
                Schema::hasColumn('exam_application', 'exam_clearance_status') ? 'exam_clearance_status' : null,
                Schema::hasColumn('exam_application', 'exam_clearance_remarks') ? 'exam_clearance_remarks' : null,
                Schema::hasColumn('exam_application', 'exam_cleared_at') ? 'exam_cleared_at' : null,
            ]);

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
