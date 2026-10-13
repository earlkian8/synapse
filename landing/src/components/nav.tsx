import { motion, useMotionValueEvent, useScroll } from 'motion/react';
import { useState } from 'react';
import { links } from '../lib/links';
import { Lockup } from './mark';

const sections = [
    { href: '#lifecycle', label: 'How it works' },
    { href: '#insights', label: 'Insights' },
    { href: '#app', label: 'Mobile app' },
];

export function Nav() {
    const { scrollY } = useScroll();
    const [raised, setRaised] = useState(false);
    const [overDark, setOverDark] = useState(false);

    useMotionValueEvent(scrollY, 'change', (y) => {
        setRaised(y > 24);

        // Over the navy band (or the wave that tops it), the bar turns dark.
        const band = document
            .getElementById('insights')
            ?.getBoundingClientRect();
        setOverDark(!!band && band.top < 72 && band.bottom > 40);
    });

    const ink = overDark
        ? 'text-white/70 hover:text-white'
        : 'text-ink-soft hover:text-ink';

    return (
        <motion.header
            initial={{ y: -16, opacity: 0 }}
            animate={{ y: 0, opacity: 1 }}
            transition={{ duration: 0.6, delay: 0.1, ease: [0.22, 1, 0.36, 1] }}
            className="fixed inset-x-0 top-0 z-50 px-4 pt-3 sm:px-6"
        >
            <nav
                aria-label="Main"
                className={`mx-auto flex h-14 max-w-[84rem] items-center justify-between rounded-full pr-2 pl-4 transition-[background-color,box-shadow,backdrop-filter] duration-300 sm:pl-5 ${
                    !raised
                        ? 'bg-transparent'
                        : overDark
                          ? 'bg-[#16295a]/80 shadow-[0_12px_32px_-16px_rgb(0_0_0/0.5)] ring-1 ring-white/10 backdrop-blur-xl'
                          : 'bg-white/75 shadow-[0_1px_0_rgb(15_32_68/0.06),0_12px_32px_-16px_rgb(15_32_68/0.25)] ring-1 ring-ink/5 backdrop-blur-xl'
                }`}
            >
                <a href="#top" aria-label="SYNAPSE, back to top">
                    <Lockup
                        reversed={overDark}
                        className={`transition-colors ${overDark ? 'text-white' : 'text-ink'}`}
                    />
                </a>

                <ul className="hidden items-center gap-1 md:flex">
                    {sections.map((s) => (
                        <li key={s.href}>
                            <a
                                href={s.href}
                                className={`rounded-full px-4 py-2 text-[0.9rem] font-medium transition-colors ${ink}`}
                            >
                                {s.label}
                            </a>
                        </li>
                    ))}
                </ul>

                <div className="flex items-center gap-1.5">
                    <a
                        href={links.signIn}
                        className={`hidden rounded-full px-4 py-2 text-[0.9rem] font-medium transition-colors sm:block ${ink}`}
                    >
                        Sign in
                    </a>
                    <a
                        href="#app"
                        className={`rounded-full px-4 py-2.5 text-[0.88rem] font-semibold transition-colors ${
                            overDark
                                ? 'bg-white text-ink hover:bg-sky-lift'
                                : 'bg-ink text-white hover:bg-signal-deep'
                        }`}
                    >
                        Get the app
                    </a>
                </div>
            </nav>
        </motion.header>
    );
}
