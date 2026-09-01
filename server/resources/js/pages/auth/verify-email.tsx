import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Verify email — SYNAPSE" />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <MailCheck className="size-5" />
                </div>

                {status === 'verification-link-sent' && (
                    <Alert variant="success">
                        <CheckCircle2 />
                        <AlertDescription>
                            A new verification link has been sent to the email
                            address you provided during registration.
                        </AlertDescription>
                    </Alert>
                )}

                <Form {...send.form()} className="w-full space-y-4">
                    {({ processing }) => (
                        <Button
                            className="w-full"
                            disabled={processing}
                            variant="secondary"
                        >
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>
                    )}
                </Form>

                <TextLink href={logout()} className="text-sm">
                    Log out
                </TextLink>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
};
