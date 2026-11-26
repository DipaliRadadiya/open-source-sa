const COS = Math.cos(Math.PI / 6);
const SIN = 0.5;

export const pt = (p) => p.map((n) => Math.round(n * 100) / 100).join(" ");
export const poly = (...points) => `M${points.map(pt).join("L")}Z`;
export const line = (...points) => `M${points.map(pt).join("L")}`;

export function projector(ox, oy) {
  const P = (x, y, z) => [ox + (x - y) * COS, oy + (x + y) * SIN - z];
  P.origin = `${ox}-${oy}`;
  return P;
}

export function cuboid(P, { x = 0, y = 0, z = 0, w, d, h }) {
  const X = x + w;
  const Y = y + d;
  const Z = z + h;
  const matrix = (a, b, c, e, [tx, ty]) => `matrix(${a} ${b} ${c} ${e} ${tx} ${ty})`;

  return {
    P,
    spec: { x, y, z, w, d, h },
    top: poly(P(x, y, Z), P(X, y, Z), P(X, Y, Z), P(x, Y, Z)),
    left: poly(P(x, Y, Z), P(X, Y, Z), P(X, Y, z), P(x, Y, z)),
    right: poly(P(X, y, Z), P(X, Y, Z), P(X, Y, z), P(X, y, z)),
    inner: (c) => poly(P(x + c, y + c, Z), P(X - c, y + c, Z), P(X - c, Y - c, Z), P(x + c, Y - c, Z)),
    frontEdges: line(P(X, y, Z), P(X, Y, Z), P(x, Y, Z)),
    cornerEdge: line(P(X, Y, Z), P(X, Y, z)),
    baseEdges: line(P(x, Y, z), P(X, Y, z), P(X, y, z)),
    edges: [
      line(P(x, y, Z), P(X, y, Z), P(X, Y, Z), P(x, Y, Z), P(x, y, Z)),
      line(P(x, Y, Z), P(x, Y, z), P(X, Y, z), P(X, y, z), P(X, y, Z)),
      line(P(X, Y, Z), P(X, Y, z)),
    ],
    leftT: matrix(COS, SIN, 0, 1, P(x, Y, Z)),
    rightT: matrix(COS, -SIN, 0, 1, P(X, Y, Z)),
    topT: matrix(COS, SIN, -COS, SIN, P(x, y, Z)),
    at: {
      top: P(x + w / 2, y + d / 2, Z),
      right: P(X, y + d / 2, z + h / 2),
      left: P(x + w / 2, Y, z + h / 2),
      corner: P(X, Y, z + h / 2),
    },
  };
}

function roundedOutline({ x, y, w, d }, r, z, P, steps = 10) {
  const X = x + w;
  const Y = y + d;
  const corners = [
    [X - r, y + r, -90],
    [X - r, Y - r, 0],
    [x + r, Y - r, 90],
    [x + r, y + r, 180],
  ];
  const points = [];
  for (const [cx, cy, start] of corners) {
    for (let i = 0; i <= steps; i++) {
      const a = ((start + (90 * i) / steps) * Math.PI) / 180;
      points.push(P(cx + r * Math.cos(a), cy + r * Math.sin(a), z));
    }
  }
  return points;
}

function hull(points) {
  const pts = [...points].sort((a, b) => a[0] - b[0] || a[1] - b[1]);
  const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
  const half = (list) => {
    const out = [];
    for (const p of list) {
      while (out.length >= 2 && cross(out.at(-2), out.at(-1), p) <= 0) out.pop();
      out.push(p);
    }
    out.pop();
    return out;
  };
  return [...half(pts), ...half([...pts].reverse())];
}

export function rounded(box, r) {
  const { P, spec } = box;
  const { z, h } = spec;
  const top = roundedOutline(spec, r, z + h, P);
  const bottom = roundedOutline(spec, r, z, P);
  const body = hull([...top, ...bottom]);
  const xs = body.map((p) => p[0]);
  const ys = body.map((p) => p[1]);
  const topYs = top.map((p) => p[1]);
  const id = `${P.origin}-${Object.values(spec).join("-")}-${r}`.replace(/\./g, "_");
  return {
    id,
    top: poly(...top),
    body: poly(...body),
    shadow: poly(...roundedOutline({ ...spec, x: spec.x - 6, y: spec.y - 6, w: spec.w + 16, d: spec.d + 16 }, r + 8, z, P)),
    bounds: { x0: Math.min(...xs), x1: Math.max(...xs), y0: Math.min(...ys), y1: Math.max(...ys) },
    topBounds: { y0: Math.min(...topYs), y1: Math.max(...topYs) },
    cornerX: P(spec.x + spec.w, spec.y + spec.d, z)[0],
  };
}
