/* Copied from the ServerAvatar Central site (sv-central/site components/steel/part.jsx).
   Changed here: each gradient stop carries a class, so the light theme can re-cut the metal
   as brushed aluminium in globals.css (the site is dark-only). */

import { rounded } from "@/lib/geometry/cuboid";

/**
 * A machined block with rounded vertical edges, lit like a studio product shot: a soft key
 * light from above, a highlight down the front corner, darker falloff toward the floor, a rim
 * light along the top edge and a contact shadow beneath it.
 */
export function Part({ box, r = 10, shadow = true, children }) {
  const g = rounded(box, r);
  const { x0, x1, y1 } = g.bounds;
  const corner = (g.cornerX - x0) / (x1 - x0);
  const at = (t) => Math.min(1, Math.max(0, t));
  const id = (name) => `${g.id}-${name}`;

  return (
    <g className="sv-part">
      <defs>
        <linearGradient id={id("side")} gradientUnits="userSpaceOnUse" x1={x0} y1="0" x2={x1} y2="0">
          <stop className="sv-m-side0" offset="0" stopColor="#2a2e34" />
          <stop className="sv-m-side1" offset={at(corner - 0.2)} stopColor="#373c43" />
          <stop className="sv-m-side2" offset={at(corner - 0.035)} stopColor="#555c65" />
          <stop className="sv-m-side3" offset={at(corner)} stopColor="#8f96a0" />
          <stop className="sv-m-side4" offset={at(corner + 0.03)} stopColor="#3b4047" />
          <stop className="sv-m-side5" offset={at(corner + 0.14)} stopColor="#24272c" />
          <stop className="sv-m-side6" offset="1" stopColor="#1a1c20" />
        </linearGradient>
        <linearGradient id={id("fall")} gradientUnits="userSpaceOnUse" x1="0" y1={g.topBounds.y1} x2="0" y2={y1}>
          <stop className="sv-m-fall0" offset="0" stopColor="#000" stopOpacity="0" />
          <stop className="sv-m-fall1" offset="1" stopColor="#000" stopOpacity="0.38" />
        </linearGradient>
        <linearGradient id={id("top")} gradientUnits="userSpaceOnUse" x1="0" y1={g.topBounds.y0} x2="0" y2={g.topBounds.y1}>
          <stop className="sv-m-top0" offset="0" stopColor="#5d646d" />
          <stop className="sv-m-top1" offset="1" stopColor="#3f444b" />
        </linearGradient>
        <linearGradient id={id("rim")} gradientUnits="userSpaceOnUse" x1="0" y1={g.topBounds.y0} x2="0" y2={g.topBounds.y1}>
          <stop offset="0" stopColor="#fff" stopOpacity="0.06" />
          <stop offset="0.55" stopColor="#fff" stopOpacity="0.14" />
          <stop offset="1" stopColor="#fff" stopOpacity="0.6" />
        </linearGradient>
      </defs>
      {shadow && <path d={g.shadow} className="sv-contact" filter="url(#sv-soft)" />}
      <path d={g.body} fill={`url(#${id("side")})`} />
      <path d={g.body} fill={`url(#${id("fall")})`} />
      <path d={g.top} fill={`url(#${id("top")})`} />
      <path d={g.top} fill="url(#sv-sheen)" />
      {children}
      <path d={g.top} fill="none" stroke={`url(#${id("rim")})`} strokeWidth="1.2" />
    </g>
  );
}
