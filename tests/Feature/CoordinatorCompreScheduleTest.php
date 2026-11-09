<?php

use App\Models\ComprehensiveExamSchedule;
use App\Models\CoordinatorProgram;
use App\Models\ExamApplication;
use App\Models\PaymentSubmission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function createAllowedExamApplicant(): array
{
    $student = User::factory()->create([
        'role' => 'Student',
        'program' => 'Master in Technology',
        'school_id' => 'SCHEDULE-STUDENT',
    ]);
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);

    $application = ExamApplication::create([
        'student_id' => $student->school_id,
        'school_year' => '2026-2027',
        'program' => $student->program,
        'permit_status' => 'pending',
        'final_approval_status' => 'approved',
        'registrar_approval_status' => 'approved',
        'exam_clearance_status' => 'allowed',
        'created_at' => now(),
    ]);

    $payment = PaymentSubmission::create([
        'student_id' => $student->id,
        'or_number' => 'SCHEDULE-OR',
        'amount_paid' => 500,
        'payment_date' => today(),
        'payment_type' => 'exam',
        'receipt_image' => 'test-receipt.png',
        'status' => 'approved',
    ]);

    return [$student, $coordinator, $application, $payment];
}

function validStudentScheduleData(ExamApplication $application, array $overrides = []): array
{
    $proctor = User::factory()->create(['role' => 'Proctor']);

    return array_merge([
        'application_id' => $application->application_id,
        'date' => '2026-04-05',
        'time' => '17:41',
        'venue' => 'Room 15',
        'proctor_ids' => [$proctor->id],
        'teacher_assigned' => 'John',
    ], $overrides);
}

it('rejects schedule dates with years longer than four digits', function () {
    [, $coordinator, $application] = createAllowedExamApplicant();

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validStudentScheduleData($application, ['date' => '55555-04-05']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('creates an individual schedule for an allowed student and notifies them', function () {
    [$student, $coordinator, $application] = createAllowedExamApplicant();
    $proctor = User::factory()->create([
        'role' => 'Proctor',
        'first_name' => 'Demo Proctor',
        'middle_name' => null,
        'last_name' => '1',
    ]);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validStudentScheduleData($application, ['proctor_ids' => [$proctor->id]]))
        ->assertCreated()
        ->assertJsonPath('schedule.venue', 'Room 15')
        ->assertJsonPath('schedule.proctor_ids.0', $proctor->id)
        ->assertJsonPath('schedule.proctors.0', $proctor->display_name);

    $schedule = ComprehensiveExamSchedule::firstOrFail();
    expect($schedule->exam_application_id)->toBe($application->application_id)
        ->and($schedule->proctorUsers->modelKeys())->toBe([$proctor->id]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $student->id,
        'type' => 'comprehensive-exam-schedule',
        'title' => 'Comprehensive exam scheduled',
    ]);

    $this->actingAs($student)
        ->get('/comprehensive-exam')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('application.exam_schedule.venue', 'Room 15')
            ->where('application.exam_schedule.proctors.0', $proctor->display_name));
});

it('shows proctor options and application-specific schedules on the Coordinator page', function () {
    [, $coordinator, $application] = createAllowedExamApplicant();
    $proctor = User::factory()->create(['role' => 'Proctor', 'middle_name' => null]);
    $schedule = ComprehensiveExamSchedule::create([
        'exam_id' => 'test-exam-room',
        'exam_application_id' => $application->application_id,
        'program' => $application->program,
        'exam_date' => '2026-04-05',
        'exam_time' => '17:41',
        'venue' => 'Room 204B',
        'proctor_name' => $proctor->display_name,
        'proctors' => [$proctor->display_name],
        'teacher_assigned' => 'John',
    ]);
    $schedule->proctorUsers()->attach($proctor);

    $this->actingAs($coordinator)
        ->get('/coordinator/compre-exam')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('coordinator/compre-exam/Index')
            ->where('notEligible.0.exam_schedule.id', $schedule->id)
            ->where('notEligible.0.exam_schedule.venue', 'Room 204B')
            ->has('proctorOptions', 1)
            ->where('proctorOptions.0.id', $proctor->id)
            ->where('proctorOptions.0.name', $proctor->display_name));
});

it('rejects assigning a non-Proctor account to a schedule', function () {
    [, $coordinator, $application] = createAllowedExamApplicant();
    $student = User::factory()->create(['role' => 'Student']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validStudentScheduleData($application, ['proctor_ids' => [$student->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proctor_ids.0');

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('does not allow scheduling until the AA allows the student and payment is approved', function () {
    [$student, $coordinator, $application, $payment] = createAllowedExamApplicant();

    $application->update(['exam_clearance_status' => 'not_allowed']);
    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validStudentScheduleData($application))
        ->assertConflict();

    $application->update(['exam_clearance_status' => 'allowed']);
    $payment->update(['status' => 'pending']);
    $this->postJson('/coordinator/compre-schedule', validStudentScheduleData($application))
        ->assertConflict();

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('updates the student schedule and sends an update notification', function () {
    [$student, $coordinator, $application] = createAllowedExamApplicant();
    $data = validStudentScheduleData($application);

    $this->actingAs($coordinator)->postJson('/coordinator/compre-schedule', $data)->assertCreated();
    $data['date'] = '2026-05-06';

    $this->postJson('/coordinator/compre-schedule', $data)
        ->assertOk()
        ->assertJsonPath('schedule.exam_date', '2026-05-06');

    expect(ComprehensiveExamSchedule::where('exam_application_id', $application->application_id)->count())->toBe(1);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $student->id,
        'type' => 'comprehensive-exam-schedule',
        'title' => 'Comprehensive exam schedule updated',
    ]);
});

it('allows only a Coordinator to create an exam schedule', function () {
    [, , $application] = createAllowedExamApplicant();
    $assistant = User::factory()->create(['role' => 'Administrative Assistant']);

    $this->actingAs($assistant)
        ->postJson('/coordinator/compre-schedule', validStudentScheduleData($application))
        ->assertForbidden();
});

it('prevents a Coordinator from scheduling an application outside their assigned programs', function () {
    [, $coordinator, $application] = createAllowedExamApplicant();
    $application->update(['program' => 'Master in Business']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validStudentScheduleData($application))
        ->assertForbidden();

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});
