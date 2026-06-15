/**
 * Centralised endpoint map for the Trash Bin module.
 * Mirrors the named routes registered in routes/system.php.
 */
export const trashRoutes = {
    index: '/system/trash',
    restore: '/system/trash/restore',
    forceDelete: '/system/trash/force-delete',
    bulk: '/system/trash/bulk',
    empty: '/system/trash/empty',
} as const;
