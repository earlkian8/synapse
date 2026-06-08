import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/staffa-logo-transparent.png"
            alt="STAFFA"
            className={className}
            {...props}
        />
    );
}
