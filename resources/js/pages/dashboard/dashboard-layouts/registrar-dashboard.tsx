import { Button } from '@/components/ui/button';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

type RegistrarApplication = {
    application_id: number;
    student_name: string;
    student_email: string | null;
    student_id: string;
    aa_evaluated: boolean;
    program: string;
    school_year: string | null;
    created_at: string;
    subjects: { name: string | null; date: string | null; start_time: string | null; end_time: string | null }[];
};

type EvaluatedResponse = {
    id: number;
    family_name: string;
    first_name: string;
    program: string;
    contact_number: string;
    email_address: string;
    id_number: string;
    evaluated_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PageProps = {
    auth: {
        user: {
            name: string;
        };
    };
    registrarApplications: {
        data: RegistrarApplication[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    evaluatedResponses: {
        data: EvaluatedResponse[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

export default function RegistrarDashboard() {
    const {
        auth: { user },
        registrarApplications,
        evaluatedResponses,
    } = usePage<PageProps>().props;
    const [approvingId, setApprovingId] = useState<number | null>(null);

    function approveApplication(application: RegistrarApplication) {
        if (!window.confirm(`Approve ${application.student_name}'s comprehensive exam application for payment?`)) return;
        setApprovingId(application.application_id);
        router.post(
            `/registrar/comprehensive-exam/${application.application_id}/approve`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Application approved for payment.'),
                onError: () => toast.error('The application could not be approved. Refresh and try again.'),
                onFinish: () => setApprovingId(null),
            },
        );
    }

    return (
        <div className="flex min-h-[70vh] flex-col gap-4 px-4 py-6 md:px-7">
            <div>
                <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Registrar Dashboard</h1>
                <p className="text-muted-foreground mt-1 text-sm">Welcome, {user.name}.</p>
            </div>
            <section className="bg-card text-card-foreground border-border w-full overflow-hidden rounded-lg border">
                <div className="border-border flex items-center justify-between border-b p-5">
                    <div>
                        <h2 className="text-base font-semibold">Comprehensive Exam Final Approval</h2>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Review Coordinator-approved applications. Students can submit payment after Registrar approval.
                        </p>
                    </div>
                    <span className="text-muted-foreground text-sm">{registrarApplications.total} awaiting approval</span>
                </div>
                {registrarApplications.data.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-center text-sm">No applications are awaiting Registrar approval.</p>
                ) : (
                    <div className="w-full overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Student</th>
                                    <th className="px-4 py-3 font-medium">Student ID</th>
                                    <th className="px-4 py-3 font-medium">Program</th>
                                    <th className="px-4 py-3 font-medium">School year</th>
                                    <th className="px-4 py-3 font-medium">Exam subjects</th>
                                    <th className="px-4 py-3 font-medium">Action</th>
                                    <th className="px-4 py-3 font-medium">AA evaluation</th>
                                </tr>
                            </thead>
                            <tbody>
                                {registrarApplications.data.map((application) => (
                                    <tr key={application.application_id} className="border-border border-t align-top">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{application.student_name}</div>
                                            <div className="text-muted-foreground text-xs">{application.student_email || '—'}</div>
                                        </td>
                                        <td className="px-4 py-3">{application.student_id}</td>
                                        <td className="px-4 py-3">{application.program}</td>
                                        <td className="px-4 py-3">{application.school_year || '—'}</td>
                                        <td className="px-4 py-3">
                                            {application.subjects.length === 0 ? (
                                                '—'
                                            ) : (
                                                <ul className="space-y-1">
                                                    {application.subjects.map((subject, index) => (
                                                        <li key={`${subject.name || 'subject'}-${index}`}>
                                                            {subject.name || 'Unnamed subject'}
                                                            {subject.date ? ` · ${subject.date}` : ''}
                                                            {subject.start_time && subject.end_time
                                                                ? ` · ${subject.start_time.slice(0, 5)}–${subject.end_time.slice(0, 5)}`
                                                                : ''}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Button
                                                size="sm"
                                                disabled={approvingId === application.application_id}
                                                onClick={() => approveApplication(application)}
                                            >
                                                {approvingId === application.application_id ? 'Approving...' : 'Approve'}
                                            </Button>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ${
                                                    application.aa_evaluated
                                                        ? 'border-green-200 bg-green-100 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300'
                                                        : 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300'
                                                }`}
                                            >
                                                {application.aa_evaluated ? 'Evaluated' : 'Not evaluated'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {registrarApplications.last_page > 1 && (
                    <nav aria-label="Registrar applications pagination" className="flex flex-wrap justify-end gap-1 border-t p-4">
                        {registrarApplications.links.map((link, index) => (
                            <Link
                                key={`${link.label}-${index}`}
                                href={link.url ?? '#'}
                                preserveScroll
                                aria-current={link.active ? 'page' : undefined}
                                aria-disabled={!link.url}
                                className={`rounded border px-3 py-1 text-sm ${link.active ? 'border-rose-500 bg-rose-500 text-white' : 'border-border'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                            >
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Link>
                        ))}
                    </nav>
                )}
            </section>
            <section className="bg-card text-card-foreground border-border w-full overflow-hidden rounded-lg border">
                <div className="border-border flex items-center justify-between border-b p-5">
                    <div>
                        <h2 className="text-base font-semibold">Evaluated Student Responses</h2>
                        <p className="text-muted-foreground mt-1 text-xs">Responses marked as evaluated by the Administrative Assistant.</p>
                    </div>
                    <span className="text-muted-foreground text-sm">{evaluatedResponses.total} total</span>
                </div>
                {evaluatedResponses.data.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-center text-sm">No responses have been evaluated yet.</p>
                ) : (
                    <>
                        <div className="w-full overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Evaluated at</th>
                                        <th className="px-4 py-3 font-medium">Family name</th>
                                        <th className="px-4 py-3 font-medium">First name</th>
                                        <th className="px-4 py-3 font-medium">Program</th>
                                        <th className="px-4 py-3 font-medium">Contact number</th>
                                        <th className="px-4 py-3 font-medium">Email address</th>
                                        <th className="px-4 py-3 font-medium">ID number</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {evaluatedResponses.data.map((response) => (
                                        <tr key={response.id} className="border-border border-t">
                                            <td className="px-4 py-3 whitespace-nowrap">{new Date(response.evaluated_at).toLocaleString()}</td>
                                            <td className="px-4 py-3">{response.family_name}</td>
                                            <td className="px-4 py-3">{response.first_name}</td>
                                            <td className="px-4 py-3">{response.program}</td>
                                            <td className="px-4 py-3">{response.contact_number}</td>
                                            <td className="px-4 py-3">{response.email_address}</td>
                                            <td className="px-4 py-3">{response.id_number}</td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-full border border-green-600 px-2 py-1 text-xs font-medium text-green-700">
                                                    Evaluated
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <nav aria-label="Evaluated responses pagination" className="flex flex-wrap justify-end gap-1 border-t p-4">
                            {evaluatedResponses.links.map((link, index) => (
                                <Link
                                    key={`${link.label}-${index}`}
                                    href={link.url ?? '#'}
                                    preserveScroll
                                    aria-current={link.active ? 'page' : undefined}
                                    aria-disabled={!link.url}
                                    className={`rounded border px-3 py-1 text-sm ${link.active ? 'border-rose-500 bg-rose-500 text-white' : 'border-border'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                >
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                </Link>
                            ))}
                        </nav>
                    </>
                )}
            </section>
        </div>
    );
}
