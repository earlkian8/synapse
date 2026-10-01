import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { StepControls } from '../types';

/** The step's forward action — what pressing the main button does, and says. */
export type StepPrimary = {
    label: string;
    onClick: () => void;
    /** The step has nothing to move on with yet. */
    disabled?: boolean;
    /** The step's own save is in flight. */
    processing?: boolean;
};

type Props = {
    onBack: () => void;
    onSkip: () => void;
    /** Something whole-wizard is in flight — a skip, or a move between steps. */
    busy: boolean;
    /** That something is this step's skip. */
    skipping: boolean;
    /**
     * Absent when there is nothing to do on this step — the person may not
     * configure the module behind it. Skipping becomes the forward action rather
     * than sitting next to a button that can never be pressed.
     */
    primary?: StepPrimary;
    /** A short line beside the buttons — what the main button will actually do. */
    note?: string;
};

/**
 * The action row every step ends on. Each step renders its own, so the main
 * button always runs that step's action and every step owns its own in-flight
 * state — but they are laid out once, here, so the wizard's floor never moves.
 *
 * The main button is a plain button rather than a form's submit: a step also
 * carries its Company Setup editors, whose own dialogs and forms must never set
 * off the step's action.
 *
 * Skipping sits next to moving on rather than hidden in the corner: passing over
 * a step is a legitimate answer, and a company with nothing to say about hiring
 * yet should not have to hunt for the way past it.
 */
export default function StepFooter({
    onBack,
    onSkip,
    busy: wizardBusy,
    skipping,
    primary,
    note,
}: Props) {
    const processing = primary?.processing ?? false;
    const busy = processing || wizardBusy;
    const skipOnly = primary === undefined;

    return (
        <div className="shrink-0 border-t border-sidebar-border/70 bg-background/85 px-5 py-3.5 backdrop-blur md:px-8 dark:border-sidebar-border">
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-2.5 sm:flex-row sm:items-center">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={onBack}
                    disabled={busy}
                    className="hidden text-muted-foreground sm:inline-flex"
                >
                    <ArrowLeft className="size-4" />
                    Back
                </Button>

                {note && (
                    <p className="order-last text-xs text-muted-foreground sm:order-none sm:ml-auto sm:text-right">
                        {note}
                    </p>
                )}

                <div
                    className={`flex items-center gap-2 ${note ? '' : 'sm:ml-auto'}`}
                >
                    <Button
                        type="button"
                        variant={skipOnly ? 'default' : 'ghost'}
                        onClick={onSkip}
                        disabled={busy}
                        className={
                            skipOnly
                                ? 'flex-1 sm:flex-none'
                                : 'flex-1 text-muted-foreground sm:flex-none'
                        }
                    >
                        {skipping && <Spinner />}
                        Skip this step
                        {skipOnly && !skipping && (
                            <ArrowRight className="size-4" />
                        )}
                    </Button>
                    {primary && (
                        <Button
                            type="button"
                            onClick={primary.onClick}
                            disabled={busy || primary.disabled}
                            className="flex-1 sm:flex-none"
                        >
                            {processing && <Spinner />}
                            {primary.label}
                            {!processing && <ArrowRight className="size-4" />}
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * The forward action of a step whose work is done with its own editors: move on,
 * recording the step as done — possible only once its module holds something.
 */
export function continueAction(controls: StepControls): StepPrimary {
    return {
        label: 'Continue',
        onClick: controls.onContinue,
        disabled: !controls.configured,
        processing: controls.advancing,
    };
}
