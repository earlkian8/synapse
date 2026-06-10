import { useMemo } from 'react';
import { PIPELINE_STAGES } from '../constants';
import type { Application, RecruitmentPermissions, Stage } from '../types';
import { PipelineColumn } from './pipeline-column';

type Props = {
    applications: Application[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stage: Stage) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

export function PipelineBoard({ applications, can, ...handlers }: Props) {
    const byStage = useMemo(() => {
        const groups: Record<Stage, Application[]> = {
            applied: [],
            screening: [],
            interview: [],
            offer: [],
            hired: [],
            rejected: [],
        };

        for (const application of applications) {
            groups[application.stage]?.push(application);
        }

        return groups;
    }, [applications]);

    return (
        <div className="flex gap-3 overflow-x-auto pb-2">
            {PIPELINE_STAGES.map((stage) => (
                <PipelineColumn
                    key={stage.value}
                    label={stage.label}
                    dot={stage.dot}
                    applications={byStage[stage.value]}
                    can={can}
                    {...handlers}
                />
            ))}
        </div>
    );
}
