import { useSearchParams } from 'react-router';

type Defaults = {
    sort: string;
    direction: 'asc' | 'desc';
    /** The status shown when the address names none (`all` unless said). */
    status?: string;
    /** Extra filters this list has, with their "everything" value. */
    extra?: Record<string, string>;
};

/**
 * A list's filters, page and open record, kept in the address so a filtered
 * view can be reloaded or shared with another admin. Changing a filter goes
 * back to page 1; `id` is the record whose detail sheet is open.
 */
export function useListParams({
    sort,
    direction,
    status = 'all',
    extra = {},
}: Defaults) {
    const [params, setParams] = useSearchParams();

    const extras = Object.fromEntries(
        Object.entries(extra).map(([key, empty]) => [
            key,
            params.get(key) ?? empty,
        ]),
    );

    const values = {
        search: params.get('search') ?? '',
        status: params.get('status') ?? status,
        sort: params.get('sort') ?? sort,
        direction: (params.get('direction') === 'asc'
            ? 'asc'
            : params.get('direction') === 'desc'
              ? 'desc'
              : direction) as 'asc' | 'desc',
        per_page: Number(params.get('per_page') ?? 15),
        page: Math.max(1, Number(params.get('page') ?? 1)),
    };

    const openId = params.get('id') ? Number(params.get('id')) : null;

    const update = (
        changes: Record<string, string | number | null>,
        keepPage = false,
    ) =>
        setParams(
            (previous) => {
                const next = new URLSearchParams(previous);

                Object.entries(changes).forEach(([key, value]) => {
                    if (value === null || value === '') {
                        next.delete(key);
                    } else {
                        next.set(key, String(value));
                    }
                });

                if (!keepPage) {
                    next.delete('page');
                }

                return next;
            },
            { replace: true },
        );

    const filtered =
        values.search !== '' ||
        values.status !== status ||
        Object.entries(extra).some(([key, empty]) => extras[key] !== empty);

    return {
        values,
        extras,
        filtered,
        openId,
        setSearch: (value: string) => update({ search: value }),
        setStatus: (value: string) =>
            update({ status: value === status ? null : value }),
        setExtra: (key: string, value: string) =>
            update({ [key]: value === extra[key] ? null : value }),
        setPage: (page: number) =>
            update({ page: page > 1 ? page : null }, true),
        setPerPage: (perPage: number) =>
            update({ per_page: perPage === 15 ? null : perPage }),
        toggleSort: (column: string) =>
            values.sort === column
                ? update(
                      {
                          direction:
                              values.direction === 'asc' ? 'desc' : 'asc',
                      },
                      true,
                  )
                : update({ sort: column, direction: 'asc' }, true),
        open: (id: number | null) => update({ id }, true),
        reset: () =>
            setParams(
                (previous) => {
                    const next = new URLSearchParams();
                    const id = previous.get('id');

                    if (id) {
                        next.set('id', id);
                    }

                    return next;
                },
                { replace: true },
            ),
    };
}
