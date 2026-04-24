import { Head } from '@inertiajs/react';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            <div className="space-y-4 text-center text-sm text-muted-foreground">
                <p>
                    Email verification is disabled for this panel. Authentication
                    is handled by username, password, session, and role access.
                </p>

                {status && (
                    <p className="font-medium text-foreground">{status}</p>
                )}
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description: 'Panel authentication is username based.',
};
