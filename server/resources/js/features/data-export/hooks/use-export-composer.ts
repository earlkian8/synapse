import { useForm } from '@inertiajs/react';
import { dataExportRoutes } from '../routes';
import type { Dataset, ExportFormat } from '../types';

type ComposerForm = {
    datasets: string[];
    format: ExportFormat;
    include_files: boolean;
};

/**
 * What goes into the next archive. Everything the viewer may export starts
 * ticked: the usual reason to be here is a full copy of the workspace.
 */
export function useExportComposer(datasets: Dataset[]) {
    const form = useForm<ComposerForm>({
        datasets: datasets.map((dataset) => dataset.key),
        format: 'csv',
        include_files: false,
    });

    const selected = new Set(form.data.datasets);
    const chosen = datasets.filter((dataset) => selected.has(dataset.key));

    // Kept in catalogue order, so the archive preview reads like the sidebar.
    const setSelection = (keys: Set<string>) =>
        form.setData(
            'datasets',
            datasets
                .map((dataset) => dataset.key)
                .filter((key) => keys.has(key)),
        );

    const toggle = (key: string, checked: boolean) => {
        const next = new Set(selected);

        if (checked) {
            next.add(key);
        } else {
            next.delete(key);
        }

        setSelection(next);
    };

    const toggleMany = (keys: string[], checked: boolean) => {
        const next = new Set(selected);
        keys.forEach((key) => (checked ? next.add(key) : next.delete(key)));
        setSelection(next);
    };

    const filesAvailable = chosen.some((dataset) => dataset.has_files);

    // A switch left on from an earlier choice means nothing once no chosen
    // record has uploads, so it is not sent as asked for.
    const submit = () => {
        form.transform((data) => ({
            ...data,
            include_files: data.include_files && filesAvailable,
        }));
        form.post(dataExportRoutes.store, { preserveScroll: true });
    };

    return {
        form,
        selected,
        chosen,
        records: chosen.reduce((sum, dataset) => sum + dataset.records, 0),
        filesAvailable,
        toggle,
        toggleMany,
        selectAll: () => setSelection(new Set(datasets.map((d) => d.key))),
        clear: () => setSelection(new Set()),
        submit,
    };
}

export type ExportComposer = ReturnType<typeof useExportComposer>;
