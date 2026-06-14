import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/synapse-logo-transparent.png"
            alt="SYNAPSE"
            className={className}
            {...props}
        />
    );
}
