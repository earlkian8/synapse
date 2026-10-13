import { ArrowUpRight, Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { appVersion, links } from '../lib/links';

type Tone = 'ink' | 'outline' | 'light' | 'ghost-light';

const tones: Record<Tone, string> = {
    ink: 'bg-ink text-white hover:bg-signal-deep',
    outline:
        'bg-white/60 text-ink ring-1 ring-ink/15 ring-inset hover:bg-white hover:ring-ink/30',
    light: 'bg-white text-ink hover:bg-sky-lift',
    'ghost-light':
        'text-white ring-1 ring-white/25 ring-inset hover:bg-white/10 hover:ring-white/45',
};

export function ActionLink({
    href,
    tone = 'ink',
    children,
    icon,
    download,
    external,
    className = '',
}: {
    href: string;
    tone?: Tone;
    children: ReactNode;
    icon?: ReactNode;
    download?: boolean;
    external?: boolean;
    className?: string;
}) {
    return (
        <a
            href={href}
            download={download || undefined}
            target={external ? '_blank' : undefined}
            rel={external ? 'noopener' : undefined}
            className={`group inline-flex h-12 items-center gap-2.5 rounded-full pr-5 pl-6 text-[0.95rem] font-semibold transition-[background-color,box-shadow,color] duration-200 ${tones[tone]} ${className}`}
        >
            {children}
            {icon && (
                <span className="transition-transform duration-200 ease-out group-hover:translate-x-0.5 group-hover:-translate-y-0.5">
                    {icon}
                </span>
            )}
        </a>
    );
}

export function OpenErpLink({
    tone = 'ink',
    className,
    label = 'Open SYNAPSE',
}: {
    tone?: Tone;
    className?: string;
    label?: string;
}) {
    return (
        <ActionLink
            href={links.erp}
            tone={tone}
            className={className}
            icon={<ArrowUpRight className="size-4.5" strokeWidth={2.25} />}
        >
            {label}
        </ActionLink>
    );
}

export function DownloadApkLink({
    tone = 'outline',
    className,
    label = 'Download for Android',
}: {
    tone?: Tone;
    className?: string;
    label?: string;
}) {
    return (
        <a
            href={links.apk}
            download
            className={`group inline-flex h-12 items-center gap-3 rounded-full pr-6 pl-5 text-[0.95rem] font-semibold transition-[background-color,box-shadow] duration-200 ${tones[tone]} ${className ?? ''}`}
        >
            <span className="grid size-7 place-items-center rounded-full bg-current/10">
                <Download
                    className="size-4 transition-transform duration-200 group-hover:translate-y-0.5"
                    strokeWidth={2.25}
                />
            </span>
            <span>
                {label}
                <span className="sr-only"> (APK, version {appVersion})</span>
            </span>
        </a>
    );
}

/** A store we are not on yet: shaped like a badge, plainly not a link. */
export function SoonBadge({
    store,
    glyph,
    dark = false,
}: {
    store: string;
    glyph: ReactNode;
    dark?: boolean;
}) {
    return (
        <div
            className={`flex h-14 items-center gap-3 rounded-2xl px-4 ring-1 ring-inset ${
                dark
                    ? 'text-white/70 ring-white/15'
                    : 'text-ink-soft ring-ink/12'
            }`}
        >
            <span className="size-6 shrink-0 opacity-80">{glyph}</span>
            <span className="flex flex-col leading-tight">
                <span className="text-[0.7rem] opacity-80">Coming soon to</span>
                <span className="text-[0.95rem] font-semibold">{store}</span>
            </span>
        </div>
    );
}

/** Generic, drawn glyphs for the two stores (not the stores' own marks). */
export function PlayGlyph() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="size-full">
            <path
                d="M5 3.8v16.4c0 .6.7 1 1.2.7l14-8.2c.5-.3.5-1.1 0-1.4l-14-8.2C5.7 2.8 5 3.2 5 3.8Z"
                stroke="currentColor"
                strokeWidth="1.7"
                strokeLinejoin="round"
            />
            <path
                d="M5.4 3.4 15 13M5.4 20.6 15 11"
                stroke="currentColor"
                strokeWidth="1.4"
                opacity="0.6"
            />
        </svg>
    );
}

export function PhoneGlyph() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="size-full">
            <rect
                x="6"
                y="2.5"
                width="12"
                height="19"
                rx="3"
                stroke="currentColor"
                strokeWidth="1.7"
            />
            <path
                d="M10.5 5h3"
                stroke="currentColor"
                strokeWidth="1.7"
                strokeLinecap="round"
            />
        </svg>
    );
}
