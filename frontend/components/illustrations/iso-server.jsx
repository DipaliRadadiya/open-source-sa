// The banner's server drawing, in the same isometric projection as the brand's
// cube mark. Decorative only: aria-hidden, and it drops out below `md`.

const COS = 0.866;

const project = (cx, cy) => (x, y, z) => [cx + (x - y) * COS, cy + (x + y) * 0.5 - z];
const points = (list) => list.map((p) => p.join(",")).join(" ");

const GLASS = {
  top: "rgba(255,255,255,0.55)",
  right: "rgba(10,30,90,0.30)",
  front: "rgba(255,255,255,0.28)",
  vent: "rgba(255,255,255,0.75)",
  stroke: "rgba(255,255,255,0.6)",
};

function Slab({ P, z, leds }) {
  const [w, d, h] = [120, 76, 22];
  const top = [P(0, 0, z + h), P(w, 0, z + h), P(w, d, z + h), P(0, d, z + h)];
  const right = [P(w, 0, z), P(w, d, z), P(w, d, z + h), P(w, 0, z + h)];
  const front = [P(0, d, z), P(w, d, z), P(w, d, z + h), P(0, d, z + h)];
  return (
    <g>
      <g stroke={GLASS.stroke} strokeWidth="1" strokeLinejoin="round">
        <polygon points={points(front)} fill={GLASS.front} />
        <polygon points={points(right)} fill={GLASS.right} />
        <polygon points={points(top)} fill={GLASS.top} />
      </g>
      {[0, 1, 2, 3].map((i) => (
        <polygon
          key={i}
          points={points([P(14 + i * 13, d, z + 8), P(22 + i * 13, d, z + 8), P(22 + i * 13, d, z + 14), P(14 + i * 13, d, z + 14)])}
          fill={GLASS.vent}
          opacity="0.7"
        />
      ))}
      {leds.map((colour, i) => {
        const [cx, cy] = P(84 + i * 10, d, z + 11);
        return <circle key={i} cx={cx} cy={cy} r="2.4" fill={colour} />;
      })}
    </g>
  );
}

export function IsoServer({ className }) {
  const P = project(150, 166);
  const float = "motion-safe:animate-[hero-float_6s_ease-in-out_infinite]";
  return (
    // y 22–272: the floating cards start at 36 and the bottom slab's front corner reaches
    // 264 (166 + (120 + 76) / 2). A 250-high box cut the server's base off (Krishna, 7 Oct).
    <svg viewBox="0 22 300 250" className={className} aria-hidden="true" focusable="false">
      <Slab P={P} z={0} leds={["#34d399", "#34d399", "#93c5fd"]} />
      <Slab P={P} z={28} leds={["#34d399", "#fbbf24", "#93c5fd"]} />
      <Slab P={P} z={56} leds={["#34d399", "#34d399", "#93c5fd"]} />
      <g className={float}>
        <rect x="18" y="40" width="58" height="40" rx="10" fill="#fff" />
        <rect x="28" y="50" width="14" height="14" rx="4" fill="var(--primary)" />
        <rect x="48" y="52" width="20" height="4" rx="2" fill="var(--primary)" opacity="0.45" />
        <rect x="48" y="60" width="14" height="4" rx="2" fill="var(--primary)" opacity="0.25" />
        <circle cx="35" cy="72" r="2" fill="#10b981" />
      </g>
      <g className={`${float} [animation-delay:1s]`}>
        <circle cx="258" cy="56" r="20" fill="#fff" />
        <rect x="250" y="56" width="16" height="12" rx="3" fill="#10b981" />
        <path d="M253 56v-4a5 5 0 0 1 10 0v4" fill="none" stroke="#10b981" strokeWidth="2.2" />
      </g>
      <g className={`${float} [animation-delay:2s]`} stroke="rgba(255,255,255,0.55)">
        <ellipse cx="262" cy="150" rx="16" ry="5" fill="rgba(255,255,255,0.45)" />
        <path d="M246 150v14c0 2.8 7.2 5 16 5s16-2.2 16-5v-14" fill="rgba(255,255,255,0.22)" />
        <path d="M246 157c0 2.8 7.2 5 16 5s16-2.2 16-5" fill="none" />
      </g>
      <g fill="none" stroke="rgba(255,255,255,0.6)" strokeWidth="1.5" strokeDasharray="3 4">
        <path d="M76 70 C 110 70, 120 96, 138 108" />
        <path d="M238 66 C 214 74, 206 92, 196 104" />
      </g>
    </svg>
  );
}
