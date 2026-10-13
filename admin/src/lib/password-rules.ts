/**
 * The portal's password rules (server: App\Support\Admin\AdminPasswords), as a
 * live checklist. The server checks them again, and in production also against
 * known breaches, which no checklist can show.
 */
export const PASSWORD_RULES = [
    {
        key: 'length',
        label: 'At least 12 characters',
        test: (value: string) => value.length >= 12,
    },
    {
        key: 'upper',
        label: 'An uppercase letter',
        test: (value: string) => /\p{Lu}/u.test(value),
    },
    {
        key: 'lower',
        label: 'A lowercase letter',
        test: (value: string) => /\p{Ll}/u.test(value),
    },
    {
        key: 'number',
        label: 'A number',
        test: (value: string) => /\d/.test(value),
    },
    {
        key: 'symbol',
        label: 'A symbol',
        test: (value: string) => /[^\p{L}\p{N}\s]/u.test(value),
    },
] as const;

export function passwordMeetsRules(value: string): boolean {
    return PASSWORD_RULES.every((rule) => rule.test(value));
}
