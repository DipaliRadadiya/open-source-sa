// Steel UI banner drawing (Krishna's prototype, 14 Oct): three dark server plates in
// thin line work; only the top plate's top face is edged in the brand green, and each
// plate has one green light near its front-right corner. Colours come from `.bg-hero`.
// Decorative only.

const COS = 0.866;
const project = (cx, cy) => (x, y, z) => [cx + (x - y) * COS, cy + (x + y) * 0.5 - z];
const points = (list) => list.map((p) => p.map((n) => n.toFixed(1)).join(",")).join(" ");

function Plate({ P, z, accent = false }) {
  const [w, d, h] = [56, 56, 9];
  const top = [P(0, 0, z + h), P(w, 0, z + h), P(w, d, z + h), P(0, d, z + h)];
  const right = [P(w, 0, z), P(w, d, z), P(w, d, z + h), P(w, 0, z + h)];
  const front = [P(0, d, z), P(w, d, z), P(w, d, z + h), P(0, d, z + h)];
  const [lx, ly] = P(w, d * 0.82, z + h / 2);
  return (
    <g strokeWidth="1" strokeLinejoin="round">
      <polygon points={points(front)} fill="var(--iso-side)" stroke="var(--iso-line)" />
      <polygon points={points(right)} fill="var(--iso-side)" stroke="var(--iso-line)" />
      <polygon
        points={points(top)}
        fill="var(--iso-face)"
        stroke={accent ? "var(--primary)" : "var(--iso-line)"}
        strokeWidth={accent ? 1.4 : 1}
      />
      <rect x={lx - 1.6} y={ly - 1.6} width="3.2" height="3.2" fill="var(--primary)" />
    </g>
  );
}

export function IsoStack({ className }) {
  const P = project(60, 66);
  return (
    <svg viewBox="0 0 120 124" className={className} aria-hidden="true" focusable="false">
      {/* Drawn bottom-up so each plate covers the one below. */}
      <Plate P={P} z={0} />
      <Plate P={P} z={25} />
      <Plate P={P} z={52} accent />
    </svg>
  );
}
