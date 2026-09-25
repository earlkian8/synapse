import type { ReactNode } from 'react';
import type { StepControls } from '../types';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';

type Props = StepControls & {
    /** What the footer says while there is nothing to continue with. */
    emptyNote: string;
    /** The step's Company Setup editor, under its heading. */
    children: ReactNode;
};

/**
 * A step that is its Company Setup editor and nothing else — sites, devices, the
 * roster — where there is no sensible suggestion to start from, because what is
 * there depends entirely on the company's own buildings, hardware and people.
 * Every action the screen offers is here; the step only adds the way on.
 */
export default function EditorStep({
    emptyNote,
    children,
    ...controls
}: Props) {
    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>{children}</StepBody>

            <StepFooter
                onBack={controls.onBack}
                onSkip={controls.onSkip}
                busy={controls.busy}
                skipping={controls.skipping}
                primary={continueAction(controls)}
                note={controls.configured ? undefined : emptyNote}
            />
        </div>
    );
}
