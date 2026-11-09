<?php

namespace App\Http\Controllers;

use App\Models\ExamApplication;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrarExamApprovalController extends Controller
{
    public function approve(Request $request, ExamApplication $application)
    {
        abort_unless($request->user()?->role === 'Registrar', 403);

        return DB::transaction(function () use ($request, $application) {
            $application = ExamApplication::whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            abort_unless(in_array(strtolower($application->final_approval_status ?? 'pending'), ['pending'], true), 409, 'This application has already been reviewed by the Administrative Assistant.');
            abort_unless(strtolower($application->registrar_approval_status ?? 'pending') === 'pending', 409, 'This application has already received Registrar review.');

            $application->update([
                'registrar_approval_status' => 'approved',
                'registrar_approved_at' => now(),
                'registrar_approved_by' => $request->user()->id,
            ]);

            $student = User::query()
                ->where('school_id', (string) $application->student_id)
                ->when(ctype_digit((string) $application->student_id), fn ($query) => $query->orWhereKey((int) $application->student_id))
                ->first();

            foreach (User::query()->where('role', 'Administrative Assistant')->get() as $assistant) {
                Notification::create([
                    'user_id' => $assistant->id,
                    'type' => 'comprehensive-exam-pending-aa-review',
                    'title' => 'Comprehensive exam needs AA review',
                    'message' => ($student?->display_name ?? 'A student').' has a Registrar-approved comprehensive exam application waiting for administrative review.',
                    'link' => '/coordinator/compre-exam',
                    'read' => false,
                ]);
            }

            return to_route('dashboard')->with('success', 'Comprehensive exam application approved for Administrative Assistant review.');
        });
    }
}
