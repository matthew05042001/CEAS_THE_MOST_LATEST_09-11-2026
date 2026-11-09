<?php

namespace App\Http\Controllers;

use App\Models\ExamApplication;
use App\Models\ComprehensiveExamSchedule;
use App\Models\CoordinatorProgram;
use App\Models\Notification;
use App\Models\StudentResponse;
use App\Models\User;
use App\Services\ExamReviewAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CoordinatorCompreExamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $access = app(ExamReviewAccess::class);
        $programs = $access->programs($user);

        if (empty($programs) && $user->role !== 'Administrative Assistant') {
            return Inertia::render('coordinator/compre-exam/Index', [
                'programs' => [],
                'eligible' => [],
                'notEligible' => [],
                'counts' => ['eligible' => 0, 'notEligible' => 0],
                'proctorOptions' => $this->scheduleManagementData($user),
            ]);
        }

        // Load students in my programs (role check: role_id=Student or role='Student')
        $useRoleString = Schema::hasColumn('users', 'role');
        $studentRoleIds = [];

        if (Schema::hasTable('roles')) {
            $studentRoleIds = DB::table('roles')
                ->whereIn('name', ['Student', 'student', 'STUDENT'])
                ->pluck('id')
                ->all();
        }

        $students = DB::table('users as u')
            ->select('u.id', 'u.first_name', 'u.middle_name', 'u.last_name', 'u.email', 'u.program', 'u.school_id')
            ->when($user->role !== 'Administrative Assistant', fn ($query) => $query->whereIn('u.program', $programs))
            // Only real students
            ->when($useRoleString, function ($q) {
                $q->whereIn(DB::raw('LOWER(u.role)'), ['student']);
            }, function ($q) use ($studentRoleIds) {
                if (! empty($studentRoleIds)) {
                    $q->whereIn('u.role_id', $studentRoleIds);
                } else {
                    // final fallback: no roles table, still require role string if present
                    $q->whereIn(DB::raw('LOWER(u.role)'), ['student']);
                }
            })
            // Never include the current coordinator account
            ->where('u.id', '<>', $user->id)
            ->orderBy('u.last_name')
            ->get();

        $hasExamApp = Schema::hasTable('exam_application');
        $orderCol = $hasExamApp
            ? (Schema::hasColumn('exam_application', 'application_id') ? 'application_id'
                : (Schema::hasColumn('exam_application', 'id') ? 'id' : 'created_at'))
            : null;

        $hasExamPayments = Schema::hasTable('payment_submissions');
        $rows = $students->map(function ($s) use ($hasExamApp, $orderCol, $hasExamPayments) {
            $sid = $s->school_id ?? $s->id;

            $latest = null;
            if ($hasExamApp) {
                $latest = DB::table('exam_application')
                    ->whereIn('student_id', array_filter([$sid, $s->id], fn ($id) => filled($id)))
                    ->orderByDesc($orderCol)
                    ->first();
            }
            $examSchedule = $latest
                ? ComprehensiveExamSchedule::query()
                    ->with('proctorUsers:id,first_name,middle_name,last_name')
                    ->where('exam_application_id', $latest->application_id)
                    ->first()
                : null;

            $response = Schema::hasTable('student_responses')
                ? StudentResponse::query()->where('user_id', $s->id)->latest('id')->first()
                : null;

            $subjects = $latest && Schema::hasTable('exam_application_subject')
                ? DB::table('exam_application_subject')
                    ->where('application_id', $latest->application_id)
                    ->orderBy('id')
                    ->get(['subject_name', 'subject_code', 'exam_date', 'start_time', 'end_time'])
                    ->map(fn ($subject) => [
                        'name' => $subject->subject_name,
                        'code' => $subject->subject_code,
                        'date' => $subject->exam_date,
                        'start_time' => $subject->start_time,
                        'end_time' => $subject->end_time,
                    ])
                : [];

            $elig = $this->computeEligibility($sid);
            $paymentStatus = null;
            if ($hasExamPayments) {
                $paymentStatus = DB::table('payment_submissions')
                    ->where('payment_type', 'exam')
                    ->whereIn('student_id', array_filter([$sid, $s->id], fn ($id) => filled($id)))
                    ->orderByDesc('payment_id')
                    ->value('status');
            }

            return [
                'id' => (int) $s->id,
                'application_id' => $latest ? (int) $latest->application_id : null,
                'review_remarks' => $latest->review_remarks ?? null,
                'reviewed_at' => $latest->reviewed_at ?? null,
                'registrar_approval_status' => $latest->registrar_approval_status ?? 'pending',
                'first_name' => $s->first_name ?? '',
                'middle_name' => $s->middle_name ?? null,
                'last_name' => $s->last_name ?? '',
                'email' => $s->email ?? null,
                'school_id' => $s->school_id ?? null,
                'program' => $s->program ?? null,

                'eligible' => $elig['eligible'],
                'lacking' => $elig['lacking'],

                'applied' => (bool) $latest,
                'submitted_at' => $latest->created_at ?? null,
                'application_status' => $latest
                    ? (in_array(strtolower($latest->final_approval_status ?? 'pending'), ['rejected'], true)
                        || strtolower($latest->registrar_approval_status ?? 'pending') === 'rejected'
                        ? 'rejected'
                        : (strtolower($latest->registrar_approval_status ?? 'pending') !== 'approved'
                            ? 'pending'
                            : strtolower($latest->final_approval_status ?? 'pending')))
                    : 'not_yet_applied',
                'permit_status' => $latest ? strtolower($latest->permit_status ?? 'pending') : null,
                'payment_status' => $paymentStatus ? strtolower($paymentStatus) : null,
                'exam_clearance_status' => $latest?->exam_clearance_status,
                'exam_clearance_remarks' => $latest?->exam_clearance_remarks,
                'exam_schedule' => $examSchedule ? [
                    'id' => $examSchedule->id,
                    'date' => $examSchedule->exam_date?->format('Y-m-d'),
                    'time' => substr((string) $examSchedule->exam_time, 0, 5),
                    'venue' => $examSchedule->venue,
                    'proctor_ids' => $examSchedule->proctorUsers->pluck('id')->all(),
                    'proctors' => $examSchedule->proctorUsers->map(fn (User $proctor) => $proctor->display_name)->all(),
                    'teacher_assigned' => $examSchedule->teacher_assigned,
                ] : null,
                'subjects' => $subjects,
                'response' => $response ? [
                    'id' => (int) $response->id,
                    'family_name' => $response->family_name,
                    'first_name' => $response->first_name,
                    'program' => $response->program,
                    'contact_number' => $response->contact_number,
                    'email_address' => $response->email_address,
                    'id_number' => $response->id_number,
                    'created_at' => $response->created_at,
                    'evaluated_at' => $response->evaluated_at,
                ] : null,
            ];
        });

        $eligible = $rows->where('eligible', true)->values();
        $notEligible = $rows->where('eligible', false)->values();

        return Inertia::render('coordinator/compre-exam/Index', [
            'programs' => $programs,
            'eligible' => $eligible,
            'notEligible' => $notEligible,
            'counts' => ['eligible' => $eligible->count(), 'notEligible' => $notEligible->count()],
            'proctorOptions' => $this->scheduleManagementData($user),
        ]);
    }

    private function scheduleManagementData(User $user): array
    {
        if ($user->role !== 'Coordinator') {
            return [];
        }

        $proctorOptions = User::query()
            ->where('role', 'Proctor')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $proctor) => [
                'id' => $proctor->id,
                'name' => $proctor->display_name,
                'email' => $proctor->email,
            ])
            ->all();

        return $proctorOptions;
    }

    public function review(Request $request, ExamApplication $application)
    {
        $user = $request->user();
        $access = app(ExamReviewAccess::class);
        $access->authorize($user);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'remarks' => 'nullable|required_if:decision,rejected|string|min:3|max:2000']);

        return DB::transaction(function () use ($application, $user, $access, $data) {
            $application = ExamApplication::whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $student = User::where('role', 'Student')->where('school_id', (string) $application->student_id)->first();
            if (! $student && ctype_digit((string) $application->student_id)) {
                $student = User::where('role', 'Student')->find((int) $application->student_id);
            }
            abort_unless($student, 404, 'The student account was not found.');
            if ($user->role !== 'Administrative Assistant') {
                abort_unless(in_array($student->program, $access->programs($user), true), 403);
            }
            abort_unless(strtolower($application->registrar_approval_status ?? 'pending') === 'approved', 409, 'The Registrar must approve this application first.');
            abort_unless(strtolower($application->final_approval_status ?? 'pending') === 'pending', 409, 'This application has already been reviewed.');
            $application->update([
                'final_approval_status' => $data['decision'], 'final_approval_DATE' => now(),
                'approved_by' => $data['decision'] === 'approved' ? $user->id : null,
                'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_remarks' => $data['remarks'] ?? null,
            ]);
            Notification::create([
                'user_id' => $student->id, 'type' => 'comprehensive-exam-reviewed',
                'title' => 'Comprehensive exam application '.$data['decision'],
                'message' => $data['decision'] === 'approved'
                    ? 'Your Comprehensive Exam application has been approved. You may now submit your exam payment.'
                    : $user->display_name.' rejected your comprehensive exam application.'.(! empty($data['remarks']) ? ' '.$data['remarks'] : ''),
                'link' => '/comprehensive-exam', 'read' => false,
            ]);

            return response()->json(['ok' => true, 'status' => $data['decision']]);
        });
    }

    public function setExamClearance(Request $request, ExamApplication $application)
    {
        $user = $request->user();
        abort_unless($user->role === 'Administrative Assistant', 403);

        $data = $request->validate([
            'decision' => ['required', 'in:allowed,not_allowed'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($application, $user, $data) {
            $application = ExamApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                strtolower($application->registrar_approval_status ?? 'pending') === 'approved'
                    && strtolower($application->final_approval_status ?? 'pending') === 'approved',
                409,
                'The Registrar and Administrative Assistant must approve the application first.'
            );

            $studentId = (string) $application->student_id;
            $student = User::query()
                ->where('school_id', $studentId)
                ->when(ctype_digit($studentId), fn ($query) => $query->orWhereKey((int) $studentId))
                ->firstOrFail();

            $payment = DB::table('payment_submissions')
                ->where('payment_type', 'exam')
                ->whereIn('student_id', array_filter([$student->id, $student->school_id]))
                ->orderByDesc('payment_id')
                ->lockForUpdate()
                ->first(['status']);

            abort_unless($payment && strtolower($payment->status) === 'approved', 409, 'The student must have an approved exam payment first.');

            $application->update([
                'exam_clearance_status' => $data['decision'],
                'exam_clearance_remarks' => $data['remarks'] ?? null,
                'exam_cleared_by' => $user->id,
                'exam_cleared_at' => now(),
            ]);

            $coordinatorIds = CoordinatorProgram::query()
                ->where('program', $application->program)
                ->whereHas('coordinator', fn ($query) => $query->where('role', 'Coordinator'))
                ->pluck('coordinator_id')
                ->unique();

            foreach ($coordinatorIds as $coordinatorId) {
                Notification::create([
                    'user_id' => $coordinatorId,
                    'type' => 'comprehensive-exam-clearance',
                    'title' => 'Comprehensive exam clearance updated',
                    'message' => $student->display_name.' was marked '.str_replace('_', ' ', $data['decision']).' to take the comprehensive exam.'
                        .(! empty($data['remarks']) ? ' '.$data['remarks'] : ''),
                    'link' => '/coordinator/compre-exam',
                    'read' => false,
                ]);
            }

            return response()->json([
                'ok' => true,
                'status' => $data['decision'],
                'remarks' => $data['remarks'] ?? null,
            ]);
        });
    }

    // Simulation-friendly eligibility (set COMPRE_BYPASS_RULES=true in .env for local dev)
    protected function computeEligibility($studentId): array
    {
        $devBypass = app()->environment('local') && (bool) env('COMPRE_BYPASS_RULES', false);
        if ($devBypass) {
            return ['eligible' => true, 'lacking' => []];
        }

        $lacking = [];

        if (Schema::hasTable('grades')) {
            $hasGrades = DB::table('grades')->where('student_id', $studentId)->exists();
            if (! $hasGrades) {
                $lacking[] = 'Grades';
            }
        } else {
            $lacking[] = 'Grades';
        }

        if (Schema::hasTable('student_documents')) {
            $docsComplete = DB::table('student_documents')
                ->where('student_id', $studentId)
                ->where('is_complete', 1)
                ->exists();
            if (! $docsComplete) {
                $lacking[] = 'Documents';
            }
        } else {
            $lacking[] = 'Documents';
        }

        if (Schema::hasTable('student_accounts')) {
            $acct = DB::table('student_accounts')->where('student_id', $studentId)->first();
            $ok = $acct && (float) ($acct->outstanding_balance ?? 0) <= 0;
            if (! $ok) {
                $lacking[] = 'Outstanding Balance';
            }
        } else {
            $lacking[] = 'Outstanding Balance';
        }

        return ['eligible' => count($lacking) === 0, 'lacking' => $lacking];
    }
}
