export type PasswordRequirement = {
    key: string;
    label: string;
    test: (password: string) => boolean;
};

/**
 * Turns the backend's `passwordrules` string (Apple's Password AutoFill
 * format — `Password::defaults()->toPasswordRulesString()`, e.g.
 * "minlength: 12; required: lower; required: upper; required: digit;
 * required: special;") into a small set of checkable requirements, so the
 * one policy defined in App\Providers\AppServiceProvider can be shown live
 * instead of duplicated as copy.
 */
export function parsePasswordRules(
    rules: string | undefined,
): PasswordRequirement[] {
    if (!rules) {
        return [];
    }

    const requirements: PasswordRequirement[] = [];

    const minLength = rules.match(/minlength:\s*(\d+)/i);

    if (minLength) {
        const length = Number(minLength[1]);
        requirements.push({
            key: 'minlength',
            label: `At least ${length} characters`,
            test: (password) => password.length >= length,
        });
    }

    if (/required:\s*lower/i.test(rules)) {
        requirements.push({
            key: 'lower',
            label: 'A lowercase letter',
            test: (password) => /[a-z]/.test(password),
        });
    }

    if (/required:\s*upper/i.test(rules)) {
        requirements.push({
            key: 'upper',
            label: 'An uppercase letter',
            test: (password) => /[A-Z]/.test(password),
        });
    }

    if (/required:\s*digit/i.test(rules)) {
        requirements.push({
            key: 'digit',
            label: 'A number',
            test: (password) => /\d/.test(password),
        });
    }

    if (/required:\s*special/i.test(rules)) {
        requirements.push({
            key: 'special',
            label: 'A symbol',
            test: (password) => /[^A-Za-z0-9]/.test(password),
        });
    }

    return requirements;
}
