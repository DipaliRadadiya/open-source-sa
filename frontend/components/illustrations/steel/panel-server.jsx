import { cuboid, projector } from "@/lib/geometry/cuboid";
import { Part } from "@/components/illustrations/steel/part";

/**
 * The dashboard banner's drawing (Krishna, 15 Oct): this panel's own subject — one server and
 * the applications on it — in the machined-steel material of the landing sites, not a copy of
 * their scenes. A slot on the server's top per application (up to six, then "+N"):
 *   green  = running, amber = paused or failing, outlined = no applications yet.
 * The server's own lights turn amber when services are down. Unknown counts (a failed read)
 * light nothing rather than guess. Decorative; the words beside it carry the meaning.
 * On first paint the modules settle into their bays and their lights come on, once (Krishna
 * asked to see it, 15 Oct). Pure CSS, so a live refresh that keeps the nodes does not replay it.
 */

const P = projector(0, 0);
P.origin = "panel-server";

const CHASSIS = { x: 0, y: 0, z: 0, w: 200, d: 132, h: 50 };
const SLOT = { w: 52, d: 46, h: 12 };
const COLS = 3;
const ROWS = 2;
const MAX_SLOTS = COLS * ROWS;

const slotAt = (i) => {
  const col = i % COLS;
  const row = Math.floor(i / COLS);
  return {
    x: 12 + col * (SLOT.w + 10),
    y: 15 + row * (SLOT.d + 10),
    z: CHASSIS.h,
    ...SLOT,
  };
};

function Chassis({ alert }) {
  const box = cuboid(P, CHASSIS);
  const { w, d, h } = CHASSIS;
  return (
    <Part box={box} r={12}>
      <g transform={box.leftT}>
        <rect
          x="12"
          y="14"
          width={w - 24}
          height="20"
          rx="3"
          fill="url(#ps-glass)"
          className="sv-glass"
        />
        <rect
          x="20"
          y="23.25"
          width={w * 0.3}
          height="1.5"
          rx="0.75"
          className={alert ? "sv-glow is-warn" : "sv-glow"}
        />
      </g>
      <g transform={box.rightT}>
        <rect
          x="34"
          y="10"
          width={d - 46}
          height={h - 20}
          rx="4"
          fill="url(#ps-perf)"
        />
        {[0, 1, 2].map((i) => (
          <rect
            key={i}
            x="12"
            y={14 + i * 9}
            width="12"
            height="2"
            rx="1"
            className={alert ? "sv-led is-warn" : "sv-led"}
            style={{ "--d": `${(i * 0.4).toFixed(1)}s` }}
          />
        ))}
      </g>
    </Part>
  );
}

/** An empty bay on the chassis top: nothing installed yet. */
function EmptyBay({ spec }) {
  const top = cuboid(P, { ...spec, h: 0 }).top;
  return <path d={top} className="sv-bay" />;
}

function AppModule({ spec, state, more, step }) {
  const box = cuboid(P, spec);
  const { w } = spec;
  return (
    <g className="sv-drop" style={{ "--i": step }}>
      <Part box={box} r={6} shadow={false}>
        <g transform={box.leftT} className="sv-power">
          <rect
            x="8"
            y={spec.h / 2 - 1.5}
            width={w * 0.45}
            height="3"
            rx="1.5"
            className={`sv-led ${state === "warn" ? "is-warn" : state === "off" ? "is-off" : ""}`}
          />
        </g>
        <g transform={box.topT}>
          {/* No vents on the "+N" module, so the number reads. */}
          {more ? null : (
            <rect
              x="8"
              y="8"
              width={w - 16}
              height={spec.d - 16}
              rx="3"
              fill="url(#ps-perf)"
              opacity="0.6"
            />
          )}
          {more ? (
            <text
              x={w / 2}
              y={spec.d / 2 + 4}
              textAnchor="middle"
              className="sv-etched"
            >
              +{more}
            </text>
          ) : null}
        </g>
      </Part>
    </g>
  );
}

function viewBox() {
  const { w, d, h } = CHASSIS;
  const top = h + SLOT.h;
  const pts = [
    P(0, 0, top),
    P(w, 0, 0),
    P(0, d, 0),
    P(w, d, 0),
    P(w, 0, top),
    P(0, d, top),
  ];
  const xs = pts.map((p) => p[0]);
  const ys = pts.map((p) => p[1]);
  const pad = 14;
  const x = Math.min(...xs) - pad;
  const y = Math.min(...ys) - pad;
  return `${x.toFixed(1)} ${y.toFixed(1)} ${(Math.max(...xs) - x + pad).toFixed(1)} ${(Math.max(...ys) - y + pad).toFixed(1)}`;
}

/**
 * `total`/`online`: application counts (null when unknown). `alert`: something on the server
 * needs attention (services down).
 */
export function PanelServer({
  total = null,
  online = 0,
  alert = false,
  className,
}) {
  const known = typeof total === "number";
  const shown = known ? Math.min(total, MAX_SLOTS) : 0;
  const more = known && total > MAX_SLOTS ? total - (MAX_SLOTS - 1) : 0;
  const slots = Array.from({ length: MAX_SLOTS }, (_, i) => slotAt(i));
  // Drawn back to front so a nearer module covers the one behind it.
  const order = slots
    .map((s, i) => ({ s, i }))
    .sort((a, b) => a.s.x + a.s.y - (b.s.x + b.s.y));

  return (
    <svg
      viewBox={viewBox()}
      className={className}
      aria-hidden="true"
      focusable="false"
    >
      <defs>
        <linearGradient id="sv-sheen" x1="0" y1="1" x2="1" y2="0">
          <stop offset="0.35" stopColor="#fff" stopOpacity="0" />
          <stop offset="0.5" stopColor="#fff" stopOpacity="0.07" />
          <stop offset="0.65" stopColor="#fff" stopOpacity="0" />
        </linearGradient>
        <linearGradient id="ps-glass" x1="0" y1="0" x2="0" y2="1">
          <stop className="sv-m-glass0" offset="0" stopColor="#0d1512" />
          <stop className="sv-m-glass1" offset="1" stopColor="#0a2a1b" />
        </linearGradient>
        <pattern
          id="ps-perf"
          width="7"
          height="7"
          patternUnits="userSpaceOnUse"
        >
          <circle cx="3.5" cy="3.5" r="1.4" fill="#101215" className="sv-m-perf" />
        </pattern>
        <filter id="sv-soft" x="-40%" y="-40%" width="180%" height="180%">
          <feGaussianBlur stdDeviation="9" />
        </filter>
      </defs>
      <Chassis alert={alert} />
      {order.map(({ s, i }, step) => {
        if (i >= shown)
          return known && total === 0 ? <EmptyBay key={i} spec={s} /> : null;
        const isMore = more && i === MAX_SLOTS - 1;
        // Running apps first, so the green ones read as a count.
        const state = isMore ? "off" : i < online ? "ok" : "warn";
        return (
          <AppModule
            key={i}
            spec={s}
            state={state}
            more={isMore ? more : 0}
            step={step}
          />
        );
      })}
    </svg>
  );
}
