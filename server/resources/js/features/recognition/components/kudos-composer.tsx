import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { recognitionRoutes } from '../routes';
import type { Person, RecognitionMe } from '../types';
import { ColleaguePicker } from './colleague-picker';

const MAX = 500;

/**
 * Thank a colleague: pick them, say what they did, send. Says up front whether
 * these kudos still carry points this month.
 */
export function KudosComposer({
    colleagues,
    me,
}: {
    colleagues: Person[];
    me: RecognitionMe;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        to_employee_id: null as number | null,
        message: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(recognitionRoutes.kudos, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const carries = me.kudos_points > 0 && me.kudos_left > 0;

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-base font-semibold">Send kudos</h2>
                <span className="text-xs text-muted-foreground">
                    {carries
                        ? `Gives them ${me.kudos_points} points`
                        : 'Sent without points'}
                </span>
            </div>

            <div>
                <ColleaguePicker
                    colleagues={colleagues}
                    value={data.to_employee_id}
                    onChange={(id) => setData('to_employee_id', id)}
                    placeholder="Who are you thanking?"
                    invalid={Boolean(errors.to_employee_id)}
                />
                <InputError
                    message={errors.to_employee_id}
                    className="mt-1.5"
                />
            </div>

            <div>
                <textarea
                    value={data.message}
                    onChange={(e) => setData('message', e.target.value)}
                    rows={3}
                    maxLength={MAX}
                    aria-label="What they did"
                    placeholder="What did they do? Be specific — it’s what they’ll remember."
                    className="flex w-full resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                />
                <div className="mt-1 flex items-center justify-between">
                    <InputError message={errors.message} />
                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                        {data.message.length}/{MAX}
                    </span>
                </div>
            </div>

            <Button
                type="submit"
                className="self-end"
                disabled={
                    processing ||
                    data.to_employee_id === null ||
                    data.message.trim() === ''
                }
            >
                {processing ? <Spinner /> : <Send className="size-4" />}
                Send kudos
            </Button>
        </form>
    );
}
