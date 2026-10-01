import type { ImgHTMLAttributes } from 'react';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Which ground the mark is being placed on.
 *
 * `auto` follows the theme. `dark` is for the brand's navy field — the sign-in panel,
 * the workspace picker, the invitation page — which stays dark whatever the user's
 * appearance setting says.
 */
type Surface = 'auto' | 'light' | 'dark';

type Props = Omit<ImgHTMLAttributes<HTMLImageElement>, 'src' | 'alt'> & {
    surface?: Surface;
};

/**
 * The SYNAPSE mark.
 *
 * It ships in two colourways because roughly 60% of the artwork is deep navy, which
 * disappears on a dark ground: `synapse-mark.png` for light surfaces, and a reversed
 * `synapse-mark-reversed.png` — white figure, teal network — for dark ones.
 *
 * The mark is landscape (about 4:3), so size it by height (`h-7 w-auto`) and let the
 * width follow. Forcing it into a square box leaves it floating in empty space.
 */
export default function AppLogoIcon({
    surface = 'auto',
    className,
    ...props
}: Props) {
    const { resolvedAppearance } = useAppearance();

    const onDark =
        surface === 'dark' ||
        (surface === 'auto' && resolvedAppearance === 'dark');

    return (
        <img
            src={onDark ? '/synapse-mark-reversed.png' : '/synapse-mark.png'}
            alt="SYNAPSE"
            className={className}
            {...props}
        />
    );
}
