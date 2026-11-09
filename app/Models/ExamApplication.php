<?php

// filepath: c:\GSPS\Graduate_School_System\app\Models\ExamApplication.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamApplication extends Model
{
    protected $table = 'exam_application';

    protected $primaryKey = 'application_id';

    public $timestamps = false;

    protected $casts = [
        'registrar_approved_at' => 'datetime',
        'exam_cleared_at' => 'datetime',
    ];

    protected $fillable = [
        'student_id',
        'school_year',
        'permit_status',
        'permit_DATE',
        'permit_reason',
        'final_approval_status',
        'final_approval_DATE',
        'registrar_approval_status',
        'registrar_approved_at',
        'registrar_approved_by',
        'approved_by',
        'reviewed_by', 'reviewed_at', 'review_remarks',
        'exam_clearance_status', 'exam_clearance_remarks', 'exam_cleared_by', 'exam_cleared_at',
        'contact_number',
        'telephone_number',
        'office_address',
        'program',
        'created_at',
    ];

    public function subjects(): HasMany
    {
        return $this->hasMany(ExamApplicationSubject::class, 'application_id', 'application_id');
    }

    public function examSchedule(): HasOne
    {
        return $this->hasOne(ComprehensiveExamSchedule::class, 'exam_application_id', 'application_id');
    }
}
