import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { csrfHeaders } from '@/lib/csrf';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, GraduationCap, Mail, User2, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import type { SharedData } from '@/types';
import type { CompreExamApplicationSummary } from './Index';

export default function Details({ application }: { application: CompreExamApplicationSummary }) {
    const { props } = usePage<SharedData & { proctorOptions?: { id: number; name: string; email: string }[] }>();
    const isAssistant = props.auth.user.role === 'Administrative Assistant';
    const isCoordinator = props.auth.user.role === 'Coordinator';
    const elig = application.eligible;
    const [status, setStatus] = useState(application.application_status);
    const [clearanceStatus, setClearanceStatus] = useState(application.exam_clearance_status);
    const [clearanceRemarks, setClearanceRemarks] = useState(application.exam_clearance_remarks ?? '');
    const [scheduleDate, setScheduleDate] = useState(application.exam_schedule?.date ?? '');
    const [scheduleTime, setScheduleTime] = useState(application.exam_schedule?.time ?? '');
    const [scheduleVenue, setScheduleVenue] = useState(application.exam_schedule?.venue ?? '');
    const [scheduleTeacher, setScheduleTeacher] = useState(application.exam_schedule?.teacher_assigned ?? '');
    const [scheduleProctors, setScheduleProctors] = useState<string[]>(
        application.exam_schedule?.proctor_ids.length ? application.exam_schedule.proctor_ids.map(String) : [''],
    );
    const [remarks, setRemarks] = useState(application.review_remarks ?? '');
    const [saving, setSaving] = useState(false);
    const [savingClearance, setSavingClearance] = useState(false);
    const [savingSchedule, setSavingSchedule] = useState(false);
    const [evaluatedAt, setEvaluatedAt] = useState(application.response?.evaluated_at ?? null);
    const [evaluating, setEvaluating] = useState(false);

    useEffect(() => {
        setStatus(application.application_status);
        setClearanceStatus(application.exam_clearance_status);
        setClearanceRemarks(application.exam_clearance_remarks ?? '');
        setScheduleDate(application.exam_schedule?.date ?? '');
        setScheduleTime(application.exam_schedule?.time ?? '');
        setScheduleVenue(application.exam_schedule?.venue ?? '');
        setScheduleTeacher(application.exam_schedule?.teacher_assigned ?? '');
        setScheduleProctors(application.exam_schedule?.proctor_ids.length ? application.exam_schedule.proctor_ids.map(String) : ['']);
        setRemarks(application.review_remarks ?? '');
    }, [application]);

    function markResponseEvaluated() {
        if (!application.response || evaluatedAt) return;
        setEvaluating(true);
        router.post(
            `/aa/responses/${application.response.id}/evaluate`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setEvaluatedAt(new Date().toISOString()),
                onFinish: () => setEvaluating(false),
            },
        );
    }

    async function review(decision: 'approved' | 'rejected') {
        if (!window.confirm(`Confirm that this application should be ${decision}?`)) return;
        setSaving(true);
        try {
            const response = await fetch(`/coordinator/compre-exam/${application.application_id}/review`, {
                method: 'POST',
                headers: csrfHeaders(true),
                body: JSON.stringify({ decision, remarks: remarks.trim() || null }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Unable to review this application.');
            setStatus(decision);
            toast.success(`Application ${decision}.`);
            router.reload({ only: ['eligible', 'notEligible', 'counts'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Unable to connect. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    async function setExamClearance(decision: 'allowed' | 'not_allowed') {
        if (!application.application_id) return;
        setSavingClearance(true);
        try {
            const response = await fetch(`/coordinator/compre-exam/${application.application_id}/clearance`, {
                method: 'POST',
                headers: csrfHeaders(true),
                body: JSON.stringify({ decision, remarks: clearanceRemarks.trim() || null }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Unable to save the exam decision.');
            setClearanceStatus(decision);
            toast.success(decision === 'allowed' ? 'Student marked allowed to take the exam.' : 'Student marked not allowed to take the exam.');
            router.reload({ only: ['eligible', 'notEligible', 'counts'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Unable to save the exam decision.');
        } finally {
            setSavingClearance(false);
        }
    }

    async function saveExamSchedule() {
        if (!application.application_id) return;
        setSavingSchedule(true);
        try {
            const response = await fetch('/coordinator/compre-schedule', {
                method: 'POST',
                headers: csrfHeaders(true),
                body: JSON.stringify({
                    application_id: application.application_id,
                    date: scheduleDate,
                    time: scheduleTime,
                    venue: scheduleVenue,
                    proctor_ids: scheduleProctors.filter(Boolean).map(Number),
                    teacher_assigned: scheduleTeacher,
                }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || Object.values(result.errors ?? {}).flat()[0] || 'Unable to save the exam schedule.');
            toast.success(application.exam_schedule ? 'Exam schedule updated and student notified.' : 'Exam schedule saved and student notified.');
            router.reload({ only: ['eligible', 'notEligible', 'counts'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Unable to save the exam schedule.');
        } finally {
            setSavingSchedule(false);
        }
    }

    const statusBadge = (v: CompreExamApplicationSummary['application_status']) => {
        const s = v || 'not_yet_applied';
        const label = s === 'not_yet_applied' ? 'Not yet applied' : s[0].toUpperCase() + s.slice(1);
        const cls =
            s === 'approved'
                ? 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-900'
                : s === 'rejected'
                  ? 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-950 dark:text-rose-300 dark:border-rose-900'
                  : s === 'pending'
                    ? 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900'
                    : 'bg-zinc-100 text-zinc-700 border-zinc-200 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-800';
        return (
            <Badge variant="outline" className={`rounded-full ${cls}`}>
                {label}
            </Badge>
        );
    };

    const permitBadge = (v: CompreExamApplicationSummary['permit_status']) => {
        if (!v)
            return (
                <Badge variant="outline" className="rounded-full">
                    —
                </Badge>
            );
        const s = v;
        const cls =
            s === 'approved'
                ? 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-900'
                : s === 'rejected'
                  ? 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-950 dark:text-rose-300 dark:border-rose-900'
                  : 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900';
        return (
            <Badge variant="outline" className={`rounded-full ${cls}`}>
                {s[0].toUpperCase() + s.slice(1)}
            </Badge>
        );
    };

    return (
        <div className="p-3 text-sm">
            {/* Header */}
            <div className="mb-3 flex items-center gap-2">
                <div className="bg-primary/10 text-primary inline-flex h-8 w-8 items-center justify-center rounded-md">
                    <ClipboardList className="h-5 w-5" />
                </div>
                <h2 className="text-lg font-semibold tracking-tight">Details</h2>
            </div>

            {/* Top summary */}
            <div className="dark:bg-background rounded-lg border bg-white/60 p-3">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <div className="flex items-center gap-2 text-[15px] font-medium">
                            <User2 className="text-muted-foreground h-4 w-4" />
                            <span className="text-base font-semibold md:text-[17px]">
                                {application.last_name}, {application.first_name} {application.middle_name ? `${application.middle_name[0]}.` : ''}
                            </span>
                        </div>
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-3 text-[13px]">
                            <span className="inline-flex items-center gap-1">
                                <Mail className="h-3.5 w-3.5" />
                                {application.email || '—'}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                ID:
                                <span className="text-foreground font-medium">{application.school_id || '—'}</span>
                            </span>
                        </div>
                    </div>

                    <div>
                        <div className="flex items-center gap-2 text-[15px]">
                            <GraduationCap className="text-muted-foreground h-4 w-4" />
                            <span className="text-foreground">{application.program || '—'}</span>
                        </div>

                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {/* Eligibility */}
                            <Badge
                                variant="outline"
                                className={
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 ' +
                                    (elig
                                        ? 'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300'
                                        : 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300')
                                }
                            >
                                {elig ? <CheckCircle2 className="h-3.5 w-3.5" /> : <XCircle className="h-3.5 w-3.5" />}
                                {elig ? 'Eligible' : 'Not eligible'}
                            </Badge>

                            {/* Applied */}
                            <Badge variant="outline" className="rounded-full px-2.5 py-1">
                                {application.applied ? 'Applied' : 'Not applied'}
                            </Badge>

                            {/* Application Status */}
                            {statusBadge(status)}

                            {/* Permit Status */}
                            {permitBadge(application.permit_status)}
                        </div>
                    </div>
                </div>
            </div>

            {application.subjects.length > 0 && (
                <section className="mt-5 space-y-3 border-t pt-4">
                    <h3 className="font-semibold">Exam subjects and schedules</h3>
                    <div className="space-y-2">
                        {application.subjects.map((subject, index) => (
                            <div key={`${subject.code || subject.name || 'subject'}-${index}`} className="rounded-md border p-3">
                                <div className="font-medium">{subject.name || 'Subject not specified'}</div>
                                {subject.code && <div className="text-muted-foreground text-xs">Code: {subject.code}</div>}
                                <div className="text-muted-foreground mt-1 text-xs">
                                    {subject.date || 'Date not set'}
                                    {subject.start_time && subject.end_time
                                        ? ` · ${subject.start_time.slice(0, 5)}–${subject.end_time.slice(0, 5)}`
                                        : ''}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {application.response && (
                <section className="mt-5 space-y-3 border-t pt-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 className="font-semibold">Student response details</h3>
                            <p className="text-muted-foreground text-xs">Submitted {new Date(application.response.created_at).toLocaleString()}</p>
                        </div>
                        {evaluatedAt ? (
                            <Badge variant="outline" className="border-green-600 text-green-700">
                                Evaluated
                            </Badge>
                        ) : (
                            <Button size="sm" disabled={evaluating} onClick={markResponseEvaluated}>
                                {evaluating ? 'Saving...' : 'Mark evaluated'}
                            </Button>
                        )}
                    </div>
                    <dl className="grid gap-3 rounded-md border p-3 sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">Family name</dt>
                            <dd>{application.response.family_name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">First name</dt>
                            <dd>{application.response.first_name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">Program</dt>
                            <dd>{application.response.program}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">Contact number</dt>
                            <dd>{application.response.contact_number}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">Email address</dt>
                            <dd>{application.response.email_address}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">ID number</dt>
                            <dd>{application.response.id_number}</dd>
                        </div>
                    </dl>
                </section>
            )}

            {application.application_id && status === 'pending' && application.registrar_approval_status === 'approved' && (
                <section className="mt-5 space-y-3 border-t pt-4">
                    <h3 className="font-semibold">Review application</h3>
                    <Input
                        aria-label="Application review remarks"
                        placeholder="Review remarks (required when rejecting)"
                        value={remarks}
                        onChange={(event) => setRemarks(event.target.value)}
                        maxLength={2000}
                    />
                    <div className="flex gap-3">
                        <Button disabled={saving} onClick={() => review('approved')}>
                            Approve application
                        </Button>
                        <Button variant="outline" disabled={saving || remarks.trim().length < 3} onClick={() => review('rejected')}>
                            Reject application
                        </Button>
                    </div>
                </section>
            )}
            {application.application_id && status === 'pending' && application.registrar_approval_status !== 'approved' && (
                <p className="text-muted-foreground mt-5 border-t pt-4 text-sm">
                    This application is awaiting Registrar approval before it can be reviewed here.
                </p>
            )}
            {application.application_id && (
                <section className="mt-5 space-y-3 border-t pt-4">
                    <h3 className="font-semibold">Final exam decision</h3>
                    <div className="flex flex-wrap items-center gap-2">
                        {clearanceStatus === 'allowed' ? (
                            <Badge variant="outline" className="border-green-200 bg-green-100 text-green-700">Allowed to take exam</Badge>
                        ) : clearanceStatus === 'not_allowed' ? (
                            <Badge variant="outline" className="border-rose-200 bg-rose-100 text-rose-700">Not allowed to take exam</Badge>
                        ) : (
                            <Badge variant="outline" className="border-amber-200 bg-amber-100 text-amber-700">
                                {status !== 'approved'
                                    ? 'Waiting for application approval'
                                    : application.payment_status !== 'approved'
                                      ? 'Waiting for payment approval'
                                      : 'Waiting for AA decision'}
                            </Badge>
                        )}
                        {application.application_id && (
                            <section className="mt-5 space-y-3 border-t pt-4">
                                <h3 className="font-semibold">Exam schedule</h3>
                                {application.exam_schedule && (
                                    <div className="rounded-md border p-3 text-sm">
                                        <p><span className="font-medium">Date:</span> {application.exam_schedule.date}</p>
                                        <p><span className="font-medium">Time:</span> {application.exam_schedule.time}</p>
                                        <p><span className="font-medium">Number of rooms:</span> {application.exam_schedule.venue}</p>
                                        <p><span className="font-medium">Proctors:</span> {application.exam_schedule.proctors.join(', ') || '—'}</p>
                                        <p><span className="font-medium">Teacher assigned:</span> {application.exam_schedule.teacher_assigned || '—'}</p>
                                    </div>
                                )}
                                {isCoordinator && clearanceStatus === 'allowed' && application.payment_status === 'approved' && (
                                    <div className="space-y-3">
                                        <p className="text-muted-foreground text-sm">
                                            {application.exam_schedule ? 'Update this student’s exam schedule. The student will be notified.' : 'Set this student’s exam schedule. The student will be notified.'}
                                        </p>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <label className="grid gap-1 text-sm">
                                                Date
                                                <Input type="date" min="1000-01-01" value={scheduleDate} onChange={(event) => setScheduleDate(event.target.value)} />
                                            </label>
                                            <label className="grid gap-1 text-sm">
                                                Time
                                                <Input type="time" value={scheduleTime} onChange={(event) => setScheduleTime(event.target.value)} />
                                            </label>
                                            <label className="grid gap-1 text-sm">
                                                Number of rooms
                                                <Input value={scheduleVenue} onChange={(event) => setScheduleVenue(event.target.value)} placeholder="Enter number of rooms" />
                                            </label>
                                            <label className="grid gap-1 text-sm">
                                                Teacher assigned
                                                <Input value={scheduleTeacher} onChange={(event) => setScheduleTeacher(event.target.value)} placeholder="Enter teacher name" />
                                            </label>
                                            <div className="grid gap-2 text-sm sm:col-span-2">
                                                Proctors
                                                {scheduleProctors.map((proctorId, index) => (
                                                    <div key={index} className="flex gap-2">
                                                        <select
                                                            aria-label={`Proctor ${index + 1}`}
                                                            value={proctorId}
                                                            onChange={(event) => setScheduleProctors((current) => current.map((value, itemIndex) => itemIndex === index ? event.target.value : value))}
                                                            className="bg-background text-foreground w-full rounded-md border px-3 py-2"
                                                        >
                                                            <option value="">Select a proctor</option>
                                                            {(props.proctorOptions ?? []).map((proctor) => (
                                                                <option
                                                                    key={proctor.id}
                                                                    value={proctor.id}
                                                                    disabled={scheduleProctors.some((selected, selectedIndex) => selectedIndex !== index && selected === String(proctor.id))}
                                                                >
                                                                    {proctor.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {index > 0 && (
                                                            <Button type="button" variant="outline" onClick={() => setScheduleProctors((current) => current.filter((_, itemIndex) => itemIndex !== index))}>
                                                                Remove
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="w-fit"
                                                    disabled={scheduleProctors.length >= (props.proctorOptions ?? []).length}
                                                    onClick={() => setScheduleProctors((current) => [...current, ''])}
                                                >
                                                    + Add proctor
                                                </Button>
                                            </div>
                                        </div>
                                        {(props.proctorOptions ?? []).length === 0 && (
                                            <p className="text-muted-foreground text-sm">No Proctor accounts are available to assign.</p>
                                        )}
                                        <Button
                                            disabled={savingSchedule || !scheduleDate || !scheduleTime || !scheduleVenue.trim() || !scheduleTeacher.trim() || !scheduleProctors.some(Boolean)}
                                            onClick={saveExamSchedule}
                                        >
                                            {savingSchedule ? 'Saving...' : application.exam_schedule ? 'Update schedule' : 'Save schedule'}
                                        </Button>
                                    </div>
                                )}
                                {isCoordinator && clearanceStatus !== 'allowed' && (
                                    <p className="text-muted-foreground text-sm">A schedule can be created after the AA marks the student allowed to take the exam.</p>
                                )}
                                {isCoordinator && clearanceStatus === 'allowed' && application.payment_status !== 'approved' && (
                                    <p className="text-muted-foreground text-sm">An approved exam payment is required before scheduling.</p>
                                )}
                            </section>
                        )}
                    </div>
                    {clearanceStatus === 'not_allowed' && application.exam_clearance_remarks && (
                        <p className="text-muted-foreground text-sm">AA note: {application.exam_clearance_remarks}</p>
                    )}
                    {isAssistant && status === 'approved' && application.payment_status === 'approved' && (
                        <>
                            <Textarea
                                aria-label="Exam clearance note"
                                placeholder="Optional note for the Coordinator"
                                value={clearanceRemarks}
                                onChange={(event) => setClearanceRemarks(event.target.value)}
                                maxLength={2000}
                            />
                            <div className="flex flex-wrap gap-2">
                                <Button disabled={savingClearance} onClick={() => setExamClearance('allowed')}>
                                    Mark allowed
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={savingClearance}
                                    onClick={() => setExamClearance('not_allowed')}
                                >
                                    Mark not allowed
                                </Button>
                            </div>
                        </>
                    )}
                    {isAssistant && status === 'approved' && application.payment_status !== 'approved' && (
                        <p className="text-muted-foreground text-sm">
                            The exam payment must be approved before you can make the final decision.
                        </p>
                    )}
                </section>
            )}
            {/* Lacking requirements */}
            <div className="mt-4">
                <div className="text-muted-foreground mb-2 text-xs tracking-wide uppercase">Lacking requirements</div>
                {application.eligible || application.lacking.length === 0 ? (
                    <div className="text-foreground text-[13px]">None</div>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {application.lacking.map((x, i) => (
                            <Badge
                                key={i}
                                variant="outline"
                                className="rounded-full border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
                            >
                                <XCircle className="mr-1 h-3.5 w-3.5" />
                                {x}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
