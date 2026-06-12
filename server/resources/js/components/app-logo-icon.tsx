import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/nexo-logo-transparent.png"
            alt="NEXO"
            className={className}
            {...props}
        />
    );
}
