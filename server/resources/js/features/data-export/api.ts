import { router } from '@inertiajs/react';
import { dataExportRoutes } from './routes';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

/** Delete an archive from the history. */
export function deleteExport(hashid: string, h: Handlers = {}): void {
    router.delete(dataExportRoutes.destroy(hashid), {
        preserveScroll: true,
        onStart: h.onStart,
        onFinish: h.onFinish,
        onSuccess: h.onSuccess,
    });
}
