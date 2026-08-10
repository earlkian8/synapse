import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type SelectOption = { value: string; label: string };

type Props = {
    value: string;
    onChange: (value: string) => void;
    options: readonly SelectOption[];
    /** Rendered as the first, clearing option — omit for a required choice. */
    placeholder?: string;
    /** The value that stands for "nothing selected". */
    noneValue?: string;
    disabled?: boolean;
    className?: string;
    id?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
};

/**
 * The select used across the app's forms. It forwards the accessibility props a
 * FormField hands down onto the trigger — the element the label points at — so
 * the label, hint and error reach the control the user actually opens.
 */
export function FormSelect({
    value,
    onChange,
    options,
    placeholder,
    noneValue,
    disabled,
    className,
    id,
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
}: Props) {
    return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger
                id={id}
                aria-describedby={describedBy}
                aria-invalid={invalid}
                className={className ?? 'w-full'}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {placeholder && noneValue && (
                    <SelectItem value={noneValue}>{placeholder}</SelectItem>
                )}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
