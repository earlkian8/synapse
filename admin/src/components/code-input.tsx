import { REGEXP_ONLY_DIGITS } from 'input-otp';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSeparator,
    InputOTPSlot,
} from '@/components/ui/input-otp';

/** Six digits from an authenticator app, in two groups of three. */
export function CodeInput({
    value,
    onChange,
    onComplete,
    disabled,
    invalid,
}: {
    value: string;
    onChange: (value: string) => void;
    onComplete: (value: string) => void;
    disabled?: boolean;
    invalid?: boolean;
}) {
    return (
        <InputOTP
            maxLength={6}
            pattern={REGEXP_ONLY_DIGITS}
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus
            value={value}
            onChange={onChange}
            onComplete={onComplete}
            disabled={disabled}
            aria-label="Six-digit authenticator code"
            aria-invalid={invalid}
            containerClassName="justify-center"
        >
            <InputOTPGroup>
                {[0, 1, 2].map((index) => (
                    <InputOTPSlot
                        key={index}
                        index={index}
                        className="size-11 bg-background text-base font-medium"
                    />
                ))}
            </InputOTPGroup>
            <InputOTPSeparator className="text-muted-foreground" />
            <InputOTPGroup>
                {[3, 4, 5].map((index) => (
                    <InputOTPSlot
                        key={index}
                        index={index}
                        className="size-11 bg-background text-base font-medium"
                    />
                ))}
            </InputOTPGroup>
        </InputOTP>
    );
}
