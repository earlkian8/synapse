import { Form, Head, useForm, usePage } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { CheckCircle2, MailCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { logout } from '@/routes';
import { code as verifyCode, send } from '@/routes/verification';
import type { Auth } from '@/types';

/**
 * Confirming a new account's email address from the code it was emailed.
 *
 * There is no "verify" button: the code *is* the action, so the form posts itself
 * the moment the sixth digit lands. The only button on the screen sends a new
 * code, which is the one thing typing cannot do. A rejected code clears the boxes
 * and puts the caret back in the first one, so the next attempt is a straight
 * retype rather than a select-all-and-delete.
 */
export default function VerifyEmail({ status }: { status?: string }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        clearErrors,
        transform,
    } = useForm({ code: '' });

    // Cleared and refocused after a rejection; remounting the OTP input is the
    // only reliable way to reset a controlled one that keeps its own caret.
    const [attempt, setAttempt] = useState(0);

    const submit = (code: string) => {
        if (processing) {
            return;
        }

        // `setData` only lands on the next render, so the completed code goes
        // straight into the payload rather than being read back out of state —
        // otherwise the submit that fires on the sixth digit posts five of them.
        transform(() => ({ code }));

        post(verifyCode().url, {
            // `preserveState` keeps the code on screen while the request is in
            // flight, so the boxes don't flash empty on a slow connection.
            preserveState: true,
            onError: () => {
                reset('code');
                setAttempt((count) => count + 1);
            },
        });
    };

    return (
        <>
            <Head title="Verify email — SYNAPSE" />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <MailCheck className="size-5" />
                </div>

                <p className="text-sm text-muted-foreground">
                    We sent a {OTP_MAX_LENGTH}-digit code to{' '}
                    <span className="font-medium text-foreground">
                        {auth.user.email}
                    </span>
                    .
                </p>

                {status === 'verification-link-sent' && (
                    <Alert variant="success">
                        <CheckCircle2 />
                        <AlertDescription>
                            A new code is on its way. The previous one no longer
                            works.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex w-full flex-col items-center gap-3">
                    <InputOTP
                        key={attempt}
                        name="code"
                        maxLength={OTP_MAX_LENGTH}
                        value={data.code}
                        onChange={(value) => {
                            clearErrors('code');
                            setData('code', value);
                        }}
                        onComplete={submit}
                        disabled={processing}
                        pattern={REGEXP_ONLY_DIGITS}
                        autoComplete="one-time-code"
                        autoFocus
                    >
                        <InputOTPGroup className="gap-2">
                            {Array.from(
                                { length: OTP_MAX_LENGTH },
                                (_, index) => (
                                    <InputOTPSlot
                                        key={index}
                                        index={index}
                                        className="h-11 w-10 rounded-lg border border-input text-base font-semibold"
                                    />
                                ),
                            )}
                        </InputOTPGroup>
                    </InputOTP>

                    {processing ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner className="size-3.5" />
                            Checking your code
                        </p>
                    ) : (
                        <InputError message={errors.code} />
                    )}
                </div>

                <div className="flex w-full flex-col items-center gap-3">
                    <p className="text-sm text-muted-foreground">
                        Didn't get it? Check your spam folder.
                    </p>
                    <Form
                        {...send.form()}
                        className="w-full"
                        onSuccess={() => {
                            reset('code');
                            clearErrors('code');
                            setAttempt((count) => count + 1);
                        }}
                    >
                        {({ processing: sending }) => (
                            <Button
                                className="w-full"
                                disabled={sending || processing}
                                variant="secondary"
                            >
                                {sending && <Spinner />}
                                Send a new code
                            </Button>
                        )}
                    </Form>
                </div>

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
        'Enter the code we emailed you to confirm your address and finish setting up your account.',
};
