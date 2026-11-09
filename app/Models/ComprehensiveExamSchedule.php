<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ComprehensiveExamSchedule extends Model
{
    protected $fillable = [
        'exam_id',
        'exam_application_id',
        'program',
        'exam_date',
        'exam_time',
        'venue',
        'proctor_name',
        'proctors',
        'teacher_assigned',
    ];

    protected $casts = [
        'exam_date' => 'date:Y-m-d',
        'proctors' => 'array',
    ];

    public function proctorUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comprehensive_exam_schedule_proctors', 'schedule_id', 'user_id')
            ->withTimestamps();
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExamApplication::class, 'exam_application_id', 'application_id');
    }
}
