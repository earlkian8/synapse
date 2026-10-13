import { Fragment } from 'react';
import { Link, useMatches } from 'react-router';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { Crumb } from './crumbs';

/**
 * The trail in the top bar, as the ERP draws it: on a phone only the page
 * itself, truncated on one line.
 */
export function Breadcrumbs() {
    const matches = useMatches();
    const trail =
        [...matches]
            .reverse()
            .map(
                (match) =>
                    (match.handle as { crumbs?: Crumb[] } | undefined)?.crumbs,
            )
            .find((value) => value !== undefined) ?? [];

    if (trail.length === 0) {
        return null;
    }

    return (
        <Breadcrumb className="min-w-0">
            <BreadcrumbList className="flex-nowrap sm:flex-wrap">
                {trail.map((item, index) => {
                    const isLast = index === trail.length - 1;

                    return (
                        <Fragment key={item.to}>
                            <BreadcrumbItem
                                className={
                                    isLast ? 'min-w-0' : 'hidden sm:inline-flex'
                                }
                            >
                                {isLast ? (
                                    <BreadcrumbPage className="block truncate">
                                        {item.title}
                                    </BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink asChild>
                                        <Link to={item.to}>{item.title}</Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && (
                                <BreadcrumbSeparator className="hidden sm:list-item" />
                            )}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
