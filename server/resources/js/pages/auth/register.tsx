import { Form, Head } from '@inertiajs/react';
import { Building2, Check, Lock, Mail, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import IconInput from '@/components/icon-input';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasswordRequirements from '@/components/password-requirements';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

const STEP_ONE_FIELDS = ['organization_name', 'first_name', 'last_name'];

const STEPS = [
    { label: 'Your organization' },
    { label: 'Account & security' },
] as const;

export default function Register({ passwordRules }: Props) {
    const [step, setStep] = useState<1 | 2>(1);
    const [password, setPassword] = useState('');

    const goToStep2 = () => {
        for (const name of STEP_ONE_FIELDS) {
            const field = document.getElementById(
                name,
            ) as HTMLInputElement | null;

            if (field && !field.reportValidity()) {
                field.focus();

                return;
            }
        }

        setStep(2);
    };

    // Native `autoFocus` only fires on mount, and both step panels stay
    // mounted (just hidden) so a single submit still carries every field —
    // so move focus imperatively whenever the visible step changes.
    useEffect(() => {
        const id = step === 1 ? 'organization_name' : 'email';

        document.getElementById(id)?.focus();
    }, [step]);

    return (
        <>
            <Head title="Create account — SYNAPSE" />

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => {
                    // If the server rejects a step-one field (e.g. a duplicate
                    // organization name), surface it by returning to that step
                    // rather than leaving the error hidden on the panel behind.
                    const activeStep =
                        errors.organization_name ||
                        errors.first_name ||
                        errors.last_name
                            ? 1
                            : step;

                    return (
                        <>
                            <Stepper activeStep={activeStep} />

                            <div
                                className={cn(
                                    'grid gap-5',
                                    activeStep !== 1 && 'hidden',
                                )}
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="organization_name">
                                        Organization name
                                    </Label>
                                    <IconInput
                                        icon={Building2}
                                        id="organization_name"
                                        type="text"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="organization"
                                        name="organization_name"
                                        placeholder="Your company or team"
                                    />
                                    <InputError
                                        message={errors.organization_name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="first_name">
                                        First name
                                    </Label>
                                    <IconInput
                                        icon={User}
                                        id="first_name"
                                        type="text"
                                        required
                                        tabIndex={2}
                                        autoComplete="given-name"
                                        name="first_name"
                                        placeholder="First name"
                                    />
                                    <InputError message={errors.first_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="middle_name">
                                        Middle name{' '}
                                        <span className="text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <IconInput
                                        icon={User}
                                        id="middle_name"
                                        type="text"
                                        tabIndex={3}
                                        autoComplete="additional-name"
                                        name="middle_name"
                                        placeholder="Middle name"
                                    />
                                    <InputError message={errors.middle_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="last_name">Last name</Label>
                                    <IconInput
                                        icon={User}
                                        id="last_name"
                                        type="text"
                                        required
                                        tabIndex={4}
                                        autoComplete="family-name"
                                        name="last_name"
                                        placeholder="Last name"
                                    />
                                    <InputError message={errors.last_name} />
                                </div>

                                <Button
                                    type="button"
                                    className="mt-1 w-full"
                                    onClick={goToStep2}
                                >
                                    Continue
                                </Button>
                            </div>

                            <div
                                className={cn(
                                    'grid gap-5',
                                    activeStep !== 2 && 'hidden',
                                )}
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <IconInput
                                        icon={Mail}
                                        id="email"
                                        type="email"
                                        required
                                        tabIndex={5}
                                        autoComplete="email"
                                        name="email"
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        icon={Lock}
                                        id="password"
                                        required
                                        tabIndex={6}
                                        autoComplete="new-password"
                                        name="password"
                                        placeholder="Password"
                                        passwordrules={passwordRules}
                                        onChange={(event) =>
                                            setPassword(event.target.value)
                                        }
                                    />
                                    <PasswordRequirements
                                        rules={passwordRules}
                                        password={password}
                                        className="mt-1"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        icon={Lock}
                                        id="password_confirmation"
                                        required
                                        tabIndex={7}
                                        autoComplete="new-password"
                                        name="password_confirmation"
                                        placeholder="Confirm password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>

                                <div className="mt-1 flex gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setStep(1)}
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        type="submit"
                                        className="flex-1"
                                        tabIndex={8}
                                        data-test="register-user-button"
                                    >
                                        {processing && <Spinner />}
                                        Create account
                                    </Button>
                                </div>
                            </div>

                            <div className="text-center text-sm text-muted-foreground">
                                Already have an account?{' '}
                                <TextLink href={login()} tabIndex={9}>
                                    Log in
                                </TextLink>
                            </div>
                        </>
                    );
                }}
            </Form>
        </>
    );
}

/** A two-part sequence — company & identity, then the account itself — so the
 * order genuinely means something rather than decorating a single long form. */
function Stepper({ activeStep }: { activeStep: 1 | 2 }) {
    return (
        <div className="flex items-center gap-2">
            {STEPS.map((item, index) => {
                const stepNumber = (index + 1) as 1 | 2;
                const complete = activeStep > stepNumber;
                const active = activeStep === stepNumber;

                return (
                    <div
                        key={item.label}
                        className="flex flex-1 items-center gap-2"
                    >
                        <div
                            className={cn(
                                'flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold transition-colors',
                                complete
                                    ? 'bg-[#0ABFBF] text-[#0F2044]'
                                    : active
                                      ? 'border-2 border-[#0ABFBF] text-[#0a8b91] dark:text-[#0ABFBF]'
                                      : 'border-2 border-border text-muted-foreground',
                            )}
                        >
                            {complete ? (
                                <Check className="size-3" strokeWidth={3} />
                            ) : (
                                stepNumber
                            )}
                        </div>
                        <span
                            className={cn(
                                'text-xs font-medium',
                                active || complete
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {item.label}
                        </span>
                        {index < STEPS.length - 1 && (
                            <div
                                className={cn(
                                    'h-px flex-1',
                                    complete ? 'bg-[#0ABFBF]' : 'bg-border',
                                )}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your details below to create your account',
};
