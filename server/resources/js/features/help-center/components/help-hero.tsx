import { usePage } from '@inertiajs/react';
import SynapseField from '@/components/synapse-field';
import { HelpSearchBox } from './help-search-box';

/**
 * The Help Center's front door: the brand's deep-navy band — the same surface
 * the dashboard greets people with — carrying the question and the search.
 */
export function HelpHero() {
    const { auth } = usePage().props;
    const firstName = auth.user.first_name || 'there';
    const organization = auth.organization?.name;

    return (
        <section className="relative overflow-hidden rounded-2xl bg-[#0F2044] px-6 py-10 text-white sm:px-10 sm:py-12">
            <SynapseField />

            <div className="relative z-10 mx-auto flex max-w-2xl flex-col items-center text-center">
                <p className="text-[11px] font-semibold tracking-[0.2em] text-[#0ABFBF] uppercase">
                    Help Center
                </p>
                <h1 className="mt-2 text-2xl font-semibold tracking-tight text-balance sm:text-[32px]">
                    How can we help, {firstName}?
                </h1>
                <p className="mt-2 max-w-lg text-sm text-pretty text-white/60">
                    Step-by-step guides for every part of SYNAPSE you can use
                    {organization ? ` at ${organization}` : ''}.
                </p>

                <HelpSearchBox variant="hero" className="mt-7 w-full" />
            </div>
        </section>
    );
}
