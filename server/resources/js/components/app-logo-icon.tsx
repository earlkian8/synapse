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
 * The SYNAPSE mark: an S drawn as two swooshes and three dots, in a blue gradient.
 *
 * It is vector (`public/synapse-mark*.svg`), so it stays sharp at any size and on any
 * screen density. It ships in two colourways because its deeper blues sink on a dark
 * ground: `synapse-mark.svg` for light surfaces, and `synapse-mark-reversed.svg` —
 * the same gradient, lifted — for the navy field and dark mode.
 *
 * The mark is a little taller than wide (about 6:7), so size it by height
 * (`h-7 w-auto`) and let the width follow.
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
            src={onDark ? '/synapse-mark-reversed.svg' : '/synapse-mark.svg'}
            alt="SYNAPSE"
            className={className}
            {...props}
        />
    );
}
