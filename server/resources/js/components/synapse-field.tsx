/**
 * The signature backdrop for SYNAPSE's deep-navy, pre-app screens: a faint
 * network of nodes and links — a "synapse" the screen's content plugs into.
 * Static and very low contrast so it never competes with foreground content;
 * the slow node pulse (`.synapse-node`, defined in app.css) is dropped under
 * reduced motion. Shared by the workspace picker and the auth screens so the
 * whole pre-app journey reads as one place.
 */
export default function SynapseField() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 overflow-hidden"
        >
            <div className="absolute -top-40 left-1/2 size-[640px] -translate-x-1/2 rounded-full bg-[#0ABFBF]/10 blur-[120px]" />
            <div className="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-[#091632]" />

            <svg
                className="absolute inset-0 size-full opacity-[0.5]"
                viewBox="0 0 800 600"
                preserveAspectRatio="xMidYMid slice"
                fill="none"
            >
                <g stroke="#0ABFBF" strokeWidth="0.6" strokeOpacity="0.25">
                    <line x1="90" y1="110" x2="250" y2="180" />
                    <line x1="250" y1="180" x2="180" y2="360" />
                    <line x1="250" y1="180" x2="430" y2="120" />
                    <line x1="430" y1="120" x2="610" y2="200" />
                    <line x1="610" y1="200" x2="540" y2="400" />
                    <line x1="180" y1="360" x2="380" y2="470" />
                    <line x1="380" y1="470" x2="540" y2="400" />
                    <line x1="430" y1="120" x2="380" y2="470" />
                    <line x1="700" y1="90" x2="610" y2="200" />
                    <line x1="120" y1="520" x2="180" y2="360" />
                </g>
                <g fill="#0ABFBF">
                    {[
                        [90, 110],
                        [250, 180],
                        [430, 120],
                        [610, 200],
                        [180, 360],
                        [540, 400],
                        [380, 470],
                        [700, 90],
                        [120, 520],
                    ].map(([cx, cy], index) => (
                        <circle
                            key={`${cx}-${cy}`}
                            cx={cx}
                            cy={cy}
                            r={index % 3 === 0 ? 3 : 2}
                            className="synapse-node"
                            style={{ animationDelay: `${index * 320}ms` }}
                        />
                    ))}
                </g>
            </svg>
        </div>
    );
}
