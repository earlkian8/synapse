import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, Copy, Info, Lock, Mail } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { QRCodeSVG } from 'qrcode.react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { CodeInput } from '@/components/code-input';
import IconInput from '@/components/icon-input';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePageTitle } from '@/hooks/use-page-title';
import { ApiError } from '@/lib/api';
import { getSession, startSession, takeEndReason } from '@/lib/session';
import type { EndReason } from '@/lib/session';
import { confirmCode, login } from './api';
import type { LoginResponse, MeResponse } from './api';
import { AuthLayout } from './components/auth-layout';

const END_MESSAGES: Record<EndReason, string> = {
    idle: 'You were signed out after 30 minutes with nothing happening. Sign in again to carry on.',
    expired: 'Your session ended. Sign in again to carry on.',
    signed_out: 'You signed out.',
};

type Step =
    | { kind: 'password' }
    | { kind: 'code'; pending: LoginResponse }
    | { kind: 'done' };

/**
 * Signing in, in two steps that never hand out access until both are done:
 * the password earns a short-lived challenge, the authenticator code turns it
 * into a session. A first sign-in sets the authenticator up on the way.
 */
export function SignInPage() {
    usePageTitle('Sign in');

    const navigate = useNavigate();
    const location = useLocation();
    const queryClient = useQueryClient();
    const reduceMotion = useReducedMotion();

    const [ended] = useState(takeEndReason);
    const [step, setStep] = useState<Step>({ kind: 'password' });
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    // The code field can report "complete" more than once for one code, faster
    // than `busy` re-renders; a second request would spend the challenge (or a
    // wrong-code attempt) for nothing. One code, one request.
    const submitting = useRef(false);
    const [notice, setNotice] = useState<string | null>(
        ended ? END_MESSAGES[ended] : null,
    );

    if (getSession() && step.kind !== 'done') {
        return <Navigate to="/" replace />;
    }

    const from = (location.state as { from?: string } | null)?.from ?? '/';

    const submitPassword = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setNotice(null);

        try {
            const pending = await login(email.trim(), password);
            setPassword('');
            setCode('');
            setStep({ kind: 'code', pending });
        } catch (failure) {
            setError(failure instanceof ApiError ? failure : null);
        } finally {
            setBusy(false);
        }
    };

    const submitCode = async (value: string) => {
        if (step.kind !== 'code' || submitting.current || value.length !== 6) {
            return;
        }

        submitting.current = true;
        setBusy(true);
        setError(null);

        try {
            const session = await confirmCode(step.pending.challenge, value);

            startSession(
                session.token,
                session.expires_at,
                session.idle_minutes,
            );
            queryClient.setQueryData<MeResponse>(['me', session.token], {
                admin: session.admin,
                expires_at: session.expires_at,
                idle_minutes: session.idle_minutes,
            });
            setStep({ kind: 'done' });

            // Let the third gate light before the portal opens.
            window.setTimeout(
                () => navigate(from, { replace: true }),
                reduceMotion ? 0 : 650,
            );
        } catch (failure) {
            const apiError = failure instanceof ApiError ? failure : null;
            submitting.current = false;
            setCode('');
            setBusy(false);

            // The challenge is gone (timed out, or too many wrong codes): start over.
            if (apiError?.field('challenge')) {
                setStep({ kind: 'password' });
                setNotice(apiError.field('challenge') ?? null);

                return;
            }

            setError(apiError);
        }
    };

    const startOver = () => {
        setStep({ kind: 'password' });
        setCode('');
        setError(null);
    };

    const stage =
        step.kind === 'password'
            ? 'password'
            : step.kind === 'code'
              ? 'code'
              : 'session';
    const general =
        error && !error.field('email') && !error.field('code')
            ? error.message
            : null;

    return (
        <AuthLayout stage={stage}>
            <AnimatePresence mode="wait" initial={false}>
                <motion.div
                    key={step.kind}
                    initial={reduceMotion ? false : { opacity: 0, x: 12 }}
                    animate={{ opacity: 1, x: 0 }}
                    exit={reduceMotion ? undefined : { opacity: 0, x: -12 }}
                    transition={{ duration: 0.18, ease: 'easeOut' }}
                >
                    {notice && step.kind === 'password' && (
                        <Alert className="mb-6">
                            <Info />
                            <AlertDescription>{notice}</AlertDescription>
                        </Alert>
                    )}

                    {general && (
                        <Alert variant="destructive" className="mb-6">
                            <Info />
                            <AlertDescription>{general}</AlertDescription>
                        </Alert>
                    )}

                    {step.kind === 'password' && (
                        <form
                            onSubmit={submitPassword}
                            className="flex flex-col gap-6"
                            noValidate
                        >
                            <Heading
                                title="Sign in to the admin portal"
                                description="For the people who run SYNAPSE. Your ERP account doesn't work here."
                            />

                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <IconInput
                                        icon={Mail}
                                        id="email"
                                        type="email"
                                        required
                                        autoFocus
                                        autoComplete="username"
                                        value={email}
                                        onChange={(event) =>
                                            setEmail(event.target.value)
                                        }
                                        aria-invalid={Boolean(
                                            error?.field('email'),
                                        )}
                                        aria-describedby="email-error"
                                    />
                                    <InputError
                                        id="email-error"
                                        message={error?.field('email')}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        icon={Lock}
                                        id="password"
                                        required
                                        autoComplete="current-password"
                                        value={password}
                                        onChange={(event) =>
                                            setPassword(event.target.value)
                                        }
                                    />
                                    <InputError
                                        message={error?.field('password')}
                                    />
                                </div>

                                <Button
                                    type="submit"
                                    className="mt-1 w-full"
                                    disabled={busy || !email || !password}
                                >
                                    {busy && <Spinner />}
                                    Continue
                                </Button>
                            </div>

                            <p className="text-center text-xs text-muted-foreground">
                                Forgot your password? Ask a super admin to reset
                                it.
                            </p>
                        </form>
                    )}

                    {step.kind === 'code' &&
                        step.pending.status === 'two_factor' && (
                            <div className="flex flex-col gap-6">
                                <Heading
                                    title="Enter your authenticator code"
                                    description="Open your authenticator app and type the six digits it shows for SYNAPSE Admin."
                                />
                                <CodeStep
                                    code={code}
                                    setCode={setCode}
                                    onComplete={submitCode}
                                    busy={busy}
                                    error={error}
                                    label="Verify and sign in"
                                />
                                <BackLink onClick={startOver} />
                            </div>
                        )}

                    {step.kind === 'code' &&
                        step.pending.status === 'two_factor_setup' &&
                        step.pending.setup && (
                            <div className="flex flex-col gap-6">
                                <Heading
                                    title="Set up your authenticator"
                                    description="Admin accounts need a code from your phone at every sign-in. Scan this with an authenticator app, then enter the six digits it shows."
                                />
                                <SetupKey
                                    otpauthUrl={step.pending.setup.otpauth_url}
                                    secret={step.pending.setup.secret}
                                />
                                <CodeStep
                                    code={code}
                                    setCode={setCode}
                                    onComplete={submitCode}
                                    busy={busy}
                                    error={error}
                                    label="Confirm and sign in"
                                />
                                <BackLink onClick={startOver} />
                            </div>
                        )}

                    {step.kind === 'done' && (
                        <div
                            className="flex flex-col items-center gap-3 py-10 text-center"
                            role="status"
                        >
                            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/12 text-[#08767c] dark:text-[#0ABFBF]">
                                <Check className="size-5" strokeWidth={2.5} />
                            </span>
                            <p className="text-base font-medium">Signed in</p>
                            <p className="text-sm text-muted-foreground">
                                Opening the portal…
                            </p>
                        </div>
                    )}
                </motion.div>
            </AnimatePresence>
        </AuthLayout>
    );
}

function Heading({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="space-y-1.5">
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
            <p className="text-sm text-balance text-muted-foreground">
                {description}
            </p>
        </div>
    );
}

function CodeStep({
    code,
    setCode,
    onComplete,
    busy,
    error,
    label,
}: {
    code: string;
    setCode: (value: string) => void;
    onComplete: (value: string) => void;
    busy: boolean;
    error: ApiError | null;
    label: string;
}) {
    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                onComplete(code);
            }}
        >
            <div className="grid gap-2">
                <CodeInput
                    value={code}
                    onChange={setCode}
                    onComplete={onComplete}
                    disabled={busy}
                    invalid={Boolean(error?.field('code'))}
                />
                <InputError
                    className="text-center"
                    message={error?.field('code')}
                />
            </div>
            <Button
                type="submit"
                className="w-full"
                disabled={busy || code.length !== 6}
            >
                {busy && <Spinner />}
                {label}
            </Button>
        </form>
    );
}

/** The QR code, and the key itself for an app that cannot scan. */
function SetupKey({
    otpauthUrl,
    secret,
}: {
    otpauthUrl: string;
    secret: string;
}) {
    const [copied, setCopied] = useState(false);
    const grouped = secret.match(/.{1,4}/g)?.join(' ') ?? secret;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(secret);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="flex flex-col items-center gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 sm:flex-row sm:items-start">
            <div className="shrink-0 rounded-lg bg-white p-2.5 ring-1 ring-black/5">
                <QRCodeSVG
                    value={otpauthUrl}
                    size={132}
                    level="M"
                    marginSize={0}
                    title="Authenticator setup code"
                />
            </div>
            <div className="min-w-0 space-y-2 text-center sm:text-left">
                <p className="text-xs text-muted-foreground">
                    Can't scan it? Type this key into the app instead.
                </p>
                <p className="font-mono text-[13px] leading-relaxed tracking-wide">
                    {grouped}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => void copy()}
                >
                    {copied ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {copied ? 'Copied' : 'Copy key'}
                </Button>
            </div>
        </div>
    );
}

function BackLink({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center justify-center gap-1.5 self-center rounded-md px-2 py-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <ArrowLeft className="size-4" />
            Use a different account
        </button>
    );
}
