<?php

use App\Models\CoordinatorProgram;
use App\Models\ExamApplication;
use App\Models\ExamApplicationSubject;
use App\Models\PaymentSubmission;
use App\Models\StudentResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExamReviewPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
    }

    private function application(array $overrides = []): array
    {
        $student = User::factory()->create(['role' => 'Student', 'program' => 'Master of Arts', 'school_id' => 'EXAM-REVIEW-STUDENT']);
        $application = ExamApplication::create(array_merge([
            'student_id' => $student->school_id,
            'school_year' => '2026-2027',
            'program' => $student->program,
            'permit_status' => 'pending',
            'final_approval_status' => 'pending',
            'created_at' => now(),
        ], $overrides));

        return [$application, $student];
    }

    private function receipt(User $student): PaymentSubmission
    {
        return PaymentSubmission::create(['student_id' => $student->id, 'or_number' => 'OR-AUDIT', 'amount_paid' => 500, 'payment_date' => today(), 'payment_type' => 'exam', 'receipt_image' => 'test-receipt.png', 'status' => 'pending']);
    }

    public function test_assistant_can_view_and_approve_an_application_without_coordinator_program_assignments(): void
    {
        [$application, $student] = $this->application();
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->get('/coordinator/compre-exam')->assertOk()->assertInertia(fn ($page) => $page
            ->where('notEligible.0.application_id', $application->application_id)
            ->where('notEligible.0.registrar_approval_status', 'pending'));

        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertConflict();
        $this->assertDatabaseHas('exam_application', [
            'application_id' => $application->application_id,
            'final_approval_status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'Registrar']))
            ->post('/registrar/comprehensive-exam/'.$application->application_id.'/approve')
            ->assertRedirect('/dashboard');
        $this->actingAs($assistant)
            ->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])
            ->assertOk();
        $this->get('/coordinator/compre-exam')
            ->assertInertia(fn ($page) => $page
                ->where('notEligible.0.registrar_approval_status', 'approved'));

        $this->assertDatabaseHas('exam_application', ['application_id' => $application->application_id, 'final_approval_status' => 'approved', 'reviewed_by' => $assistant->id]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'type' => 'comprehensive-exam-reviewed',
            'message' => 'Your Comprehensive Exam application has been approved. You may now submit your exam payment.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $assistant->id,
            'type' => 'comprehensive-exam-pending-aa-review',
        ]);
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'rejected', 'remarks' => 'Try again'])->assertConflict();
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_assistant_comprehensive_exam_view_includes_student_response_and_evaluation_action(): void
    {
        [$application, $student] = $this->application();
        ExamApplicationSubject::create([
            'application_id' => $application->application_id,
            'subject_name' => 'Research Methods',
            'subject_code' => 'RES 501',
            'exam_date' => '2026-11-12',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
        $response = StudentResponse::create([
            'user_id' => $student->id,
            'family_name' => $student->last_name,
            'first_name' => $student->first_name,
            'program' => $student->program,
            'contact_number' => '09123456789',
            'email_address' => $student->email,
            'id_number' => $student->school_id,
        ]);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);

        $this->actingAs($assistant)
            ->get('/coordinator/compre-exam')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notEligible.0.application_id', $application->application_id)
                ->where('notEligible.0.response.id', $response->id)
                ->where('notEligible.0.response.contact_number', '09123456789')
                ->where('notEligible.0.subjects.0.name', 'Research Methods')
                ->where('notEligible.0.subjects.0.code', 'RES 501')
                ->where('notEligible.0.response.evaluated_at', null));

        $this->post('/aa/responses/'.$response->id.'/evaluate')
            ->assertRedirect('/coordinator/compre-exam');
        $this->assertDatabaseHas('student_responses', [
            'id' => $response->id,
            'evaluated_by' => $assistant->id,
        ]);
    }

    public function test_legacy_assistant_responses_url_redirects_to_comprehensive_exams(): void
    {
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);

        $this->actingAs($assistant)
            ->get('/aa/responses')
            ->assertRedirect('/coordinator/compre-exam');
    }

    public function test_assistant_application_rejection_requires_and_preserves_a_reason(): void
    {
        [$application] = $this->application();
        $this->actingAs(User::factory()->create(['role' => 'Registrar']))
            ->post('/registrar/comprehensive-exam/'.$application->application_id.'/approve');
        $this->actingAs(User::factory()->create(['role' => 'Administrative Assistant']))->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'rejected'])->assertUnprocessable();
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'rejected', 'remarks' => 'Incomplete supporting documents'])->assertOk();
        $this->assertSame('Incomplete supporting documents', $application->fresh()->review_remarks);
    }

    public function test_coordinator_application_reviews_remain_scoped_to_their_programs(): void
    {
        [$application] = $this->application();
        $this->actingAs(User::factory()->create(['role' => 'Registrar']))
            ->post('/registrar/comprehensive-exam/'.$application->application_id.'/approve');
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => 'Another Program']);
        $this->actingAs($coordinator)->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertForbidden();
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => 'Master of Arts']);
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertOk();
    }

    public function test_coordinator_comprehensive_exam_list_includes_applications_for_assigned_programs(): void
    {
        [$application] = $this->application();
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => 'Master of Arts']);

        $this->actingAs($coordinator)
            ->get('/coordinator/compre-exam')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notEligible.0.application_id', $application->application_id));
    }

    public function test_students_cannot_review_exam_applications_or_receipts(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $this->actingAs($student)->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertForbidden();
        $this->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertForbidden();
    }

    public function test_assistant_can_review_receipts_and_records_the_reviewer(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->get('/coordinator/compre-payment')->assertOk()->assertInertia(fn ($page) => $page->where('counts.pending', 1));
        $this->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertRedirect('/coordinator/compre-payment');
        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertEquals($assistant->id, $receipt->fresh()->checked_by);
    }

    public function test_coordinator_cannot_review_payment_receipts(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);
        $this->actingAs($coordinator)->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertForbidden();
        $this->post('/coordinator/compre-payment/'.$receipt->payment_id.'/reject', ['remarks' => 'Invalid receipt'])->assertForbidden();
        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_assistant_can_reject_a_receipt_with_a_reason(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->post('/coordinator/compre-payment/'.$receipt->payment_id.'/reject', ['remarks' => 'Receipt number does not match'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $receipt->fresh()->status);
        $this->assertSame('Receipt number does not match', $receipt->fresh()->remarks);
        $this->assertEquals($assistant->id, $receipt->fresh()->checked_by);
    }

    public function test_only_assistant_can_set_exam_clearance_after_application_and_payment_approval(): void
    {
        [$application, $student] = $this->application([
            'registrar_approval_status' => 'approved',
            'final_approval_status' => 'approved',
        ]);
        $receipt = $this->receipt($student);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);

        $this->actingAs($assistant)
            ->postJson('/coordinator/compre-exam/'.$application->application_id.'/clearance', ['decision' => 'allowed'])
            ->assertConflict();

        $receipt->update(['status' => 'approved']);
        $this->actingAs($coordinator)
            ->postJson('/coordinator/compre-exam/'.$application->application_id.'/clearance', ['decision' => 'allowed'])
            ->assertForbidden();

        $this->actingAs($assistant)
            ->postJson('/coordinator/compre-exam/'.$application->application_id.'/clearance', ['decision' => 'allowed'])
            ->assertOk()
            ->assertJsonPath('status', 'allowed');

        $this->assertDatabaseHas('exam_application', [
            'application_id' => $application->application_id,
            'exam_clearance_status' => 'allowed',
            'exam_cleared_by' => $assistant->id,
        ]);

        $this->actingAs($coordinator)
            ->get('/coordinator/compre-exam')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('notEligible.0.exam_clearance_status', 'allowed'));
    }

    public function test_assistant_can_mark_student_not_allowed_and_send_a_note_to_coordinator(): void
    {
        [$application, $student] = $this->application([
            'registrar_approval_status' => 'approved',
            'final_approval_status' => 'approved',
        ]);
        $receipt = $this->receipt($student);
        $receipt->update(['status' => 'approved']);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);

        $this->actingAs($assistant)
            ->postJson('/coordinator/compre-exam/'.$application->application_id.'/clearance', [
                'decision' => 'not_allowed',
                'remarks' => 'Please confirm exam requirements.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'not_allowed');

        $this->assertDatabaseHas('exam_application', [
            'application_id' => $application->application_id,
            'exam_clearance_status' => 'not_allowed',
            'exam_clearance_remarks' => 'Please confirm exam requirements.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $coordinator->id,
            'type' => 'comprehensive-exam-clearance',
        ]);
    }
}
