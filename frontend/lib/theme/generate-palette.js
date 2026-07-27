import { converter, formatCss } from "culori";

const toOklch = converter("oklch");

// Lightness (l) and a chroma multiplier per shade step. Chroma tapers down
// toward the extremes since very light/dark colors can't sustain full
// chroma while staying inside the sRGB gamut.
const SHADE_STEPS = {
  50: { l: 0.97, chroma: 0.3 },
  100: { l: 0.94, chroma: 0.45 },
  200: { l: 0.89, chroma: 0.65 },
  300: { l: 0.82, chroma: 0.85 },
  400: { l: 0.74, chroma: 0.95 },
  500: { l: 0.65, chroma: 1 },
  600: { l: 0.55, chroma: 1 },
  700: { l: 0.46, chroma: 0.92 },
  800: { l: 0.38, chroma: 0.8 },
  900: { l: 0.3, chroma: 0.65 },
  950: { l: 0.22, chroma: 0.45 },
};

const NEUTRAL_WHITE = formatCss({ mode: "oklch", l: 0.985, c: 0, h: 0 });
const NEUTRAL_BLACK = formatCss({ mode: "oklch", l: 0.145, c: 0, h: 0 });

// Contrast is decided by the background's actual lightness, not by which
// theme is active, so it stays correct even if the shades used for
// light/dark primary ever change.
function pickForeground(l) {
  return l < 0.62 ? NEUTRAL_WHITE : NEUTRAL_BLACK;
}

export function generatePalette(hex) {
  const base = toOklch(hex);
  if (!base) return null;

  const { c, h } = base;
  const shades = {};

  for (const [step, { l, chroma }] of Object.entries(SHADE_STEPS)) {
    shades[step] = formatCss({ mode: "oklch", l, c: c * chroma, h: h ?? 0 });
  }

  return {
    shades,
    light: {
      primary: shades[600],
      primaryForeground: pickForeground(SHADE_STEPS[600].l),
    },
    dark: {
      primary: shades[400],
      primaryForeground: pickForeground(SHADE_STEPS[400].l),
    },
  };
}
