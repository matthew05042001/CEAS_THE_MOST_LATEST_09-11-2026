# Integrated Graduate School system

This local integration combines the current Defense Request improvements and existing Comprehensive Exam code with the Honorarium additions from Dael-uic/Drafted-Honorarium-Tracker-System-Module, revision 90778c2. Original project folders remain available.

## Open and run

Project folder: `C:\Users\lebro\Documents\ChatGPT\Lebron Programming\Graduate_School_Integrated`

Open this folder in VS Code. In its terminal run:

```powershell
npm.cmd run dev
```

Open http://127.0.0.1:8001. The integrated server uses port8001 and Vite uses5174. If the preview server is already running, stop it before starting the dev command, or continue using the built preview.

The integration uses its own MySQL database, `grad_integration_db`, copied from the local `grad_db`. New migrations were applied only to the integration database. Start MySQL in XAMPP before running. Uploaded files were copied into the integration project; subsequent changes in either project are independent.

Existing demo accounts use password `password`: student@uic.edu.ph, adviser@uic.edu.ph, acadcoor@uic.edu.ph, aa@uic.edu.ph, dean@uic.edu.ph.

New Finance demo: `finance@uic.edu.ph` / `password`. Local demo authentication does not contact school services. To recreate the Finance demo locally use `php artisan demo:finance-account`. Super Admin can assign the Finance role through account management.

## Honorarium handoff

AA opens Honorarium Tracking, then Manage Finance Documents. Approved defenses with uploaded receipts are eligible for Finance handoff. Document1 is the payment receipt. Document2 is the panel list, which unlocks on the next Philippine calendar day and requires Finance to verify Document1 before submission. Finance can verify or reject with a reason. Rejected documents can be resent; earlier review events remain in the activity history. Current panel names are captured when Document2 is sent. Changed receipts or panel lists cannot be verified against old snapshots; Finance must reject the old document and the AA resubmit current information.

Both Honorarium screens read the same Finance document records. Faculty/Adviser visibility uses panelist email or the assigned adviser account; Coordinator visibility uses assigned requests. Student receipt entry stays in the existing Defense Request module. No real payments are executed.

The Honorarium Payments page now records prepared, approved, disbursed and claimed recipient statuses. Panel invitation acceptance and direct Finance-system integration remain separate work. See implemented-improvements-2026-10-24.md for the new ledger and review rules. Document verification is not confirmation of a paid honorarium.

## Comprehensive Exam findings

Verified locally: application creation, subject schedule storage, duplicate submission handling per school year, invalid time validation, and student-specific application display. Added missing exam subject schedule columns through a migration and made application+subjects save in one transaction. Only Student accounts can submit.

Live eligibility still depends on the school portal session; demo accounts and current Google sign-in do not establish that session. Without it the eligibility API reports that verification is unavailable. The existing coordinator local-development eligibility bypass is separate from the student's check. Exam submission now enforces the existing eligibility result on the server; unverified eligibility blocks submission. This integration does not certify Registrar/Dean approval, results release, or proctor invitation workflows as complete.

Comprehensive Exam applications go to the Registrar first and then the Administrative Assistant. Exam payment is available only after both approvals.

The inherited route audit still finds15 pre-existing route handlers that are absent. Their details are in `docs/diagram-audit-results.json`. They are not part of the newly integrated Finance endpoints.

## Notifications and configuration

Existing Defense Request corrections, progress history, local reminders, Google SSO code, and account management are preserved. Finance actions create in-app notices. Countdown emails are optional and disabled by default (DEFENSE_REMINDER_EMAILS). Mail remains `log`; no live emails are sent. The daily defense reminder and Finance document unlock schedules are both registered in Philippine time. The dev command starts the queue worker and scheduler; a plain preview server does not run those workers. Finance documents also catch up when the AA/Finance opens the page.

Google SSO on the integration URL requires adding `http://127.0.0.1:8001/auth/google/callback` to the Google OAuth client's authorized redirect URIs. Existing credentials remain private in the local ignored .env file.

## Verification

Automated tests use isolated SQLite data and fake external services; they do not change the integration or original MySQL records. Use `php vendor/bin/pest tests/Unit`, `npm.cmd run types`, and `npm.cmd run build`. `php artisan demo:audit-diagram` checks role pages against the integration database. This is not a load test or a complete production acceptance test.
