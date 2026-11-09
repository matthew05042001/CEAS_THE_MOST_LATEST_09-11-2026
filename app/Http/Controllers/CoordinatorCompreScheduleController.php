<?php

namespace App\Http\Controllers;

use App\Models\ComprehensiveExamSchedule;
use App\Models\ExamApplication;
use App\Models\Notification;
use App\Models\User;
use App\Services\ExamReviewAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CoordinatorCompreScheduleController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeCoordinator($request);

        return to_route('coordinator.compre-exam.index');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCoordinator($request);

        $data = $request->validate([
            'application_id' => ['required', 'integer', Rule::exists('exam_application', 'application_id')],
            'date' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}$/', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'proctor_ids' => ['required', 'array', 'min:1'],
            'proctor_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('role', 'Proctor')],
            'teacher_assigned' => ['required', 'string', 'max:255'],
        ]);
        $result = DB::transaction(function () use ($data, $request) {
            $application = ExamApplication::query()
                ->whereKey($data['application_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $programs = app(ExamReviewAccess::class)->programs($request->user());
            abort_unless(in_array($application->program, $programs, true), 403);

            abort_unless(
                strtolower($application->registrar_approval_status ?? 'pending') === 'approved'
                    && strtolower($application->final_approval_status ?? 'pending') === 'approved'
                    && strtolower($application->exam_clearance_status ?? '') === 'allowed',
                409,
                'Only a student approved to take the exam can be scheduled.'
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
            abort_unless($payment && strtolower($payment->status) === 'approved', 409, 'An approved exam payment is required before scheduling.');

            $proctors = User::query()->whereIn('id', $data['proctor_ids'])->get()->keyBy('id');
            $proctorNames = collect($data['proctor_ids'])->map(fn (int $id) => $proctors->get($id)->display_name)->all();
            $schedule = ComprehensiveExamSchedule::query()->firstOrNew(['exam_application_id' => $application->application_id]);
            $created = ! $schedule->exists;
            $schedule->fill([
                'exam_id' => $schedule->exam_id ?: (string) Str::uuid(),
                'program' => $application->program,
                'exam_date' => $data['date'],
                'exam_time' => $data['time'],
                'venue' => $data['venue'],
                'proctor_name' => $proctorNames[0],
                'proctors' => $proctorNames,
                'teacher_assigned' => $data['teacher_assigned'],
            ]);
            $schedule->save();
            $schedule->proctorUsers()->sync($data['proctor_ids']);
            $schedule->load('proctorUsers:id,first_name,last_name');

            $date = $schedule->exam_date?->format('F j, Y');
            $time = substr((string) $schedule->exam_time, 0, 5);
            $message = 'Your comprehensive exam is scheduled for '.$date.' at '.$time
                .', room(s): '.$schedule->venue
                .'. Proctor(s): '.implode(', ', $proctorNames)
                .'. Teacher: '.$schedule->teacher_assigned.'.';

            Notification::create([
                'user_id' => $student->id,
                'type' => 'comprehensive-exam-schedule',
                'title' => $created ? 'Comprehensive exam scheduled' : 'Comprehensive exam schedule updated',
                'message' => $message,
                'link' => '/comprehensive-exam',
                'read' => false,
            ]);

            return ['schedule' => $this->scheduleData($schedule), 'created' => $created];
        });

        return response()->json(['schedule' => $result['schedule']], $result['created'] ? 201 : 200);
    }

    private function scheduleData(ComprehensiveExamSchedule $schedule): array
    {
        $assignedProctors = $schedule->proctorUsers;

        return [
            'id' => $schedule->id,
            'program' => $schedule->program,
            'exam_date' => $schedule->exam_date?->format('Y-m-d'),
            'exam_time' => $schedule->exam_time,
            'venue' => $schedule->venue,
            'proctors' => $assignedProctors->isNotEmpty()
                ? $assignedProctors->map(fn (User $proctor) => $proctor->display_name)->all()
                : ($schedule->proctors ?: [$schedule->proctor_name]),
            'proctor_ids' => $assignedProctors->pluck('id')->all(),
            'teacher_assigned' => $schedule->teacher_assigned,
        ];
    }

    private function authorizeCoordinator(Request $request): void
    {
        abort_unless($request->user()?->role === 'Coordinator', 403);
    }
}
