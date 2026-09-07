/**
 * The donut on the file manager: which kinds of file are using the space.
 *
 * Two rules shape this and both come from the chart guidance rather than taste.
 *
 * **Six slices, never more.** A donut answers part-to-whole *at a glance*; past
 * about six segments the arcs stop being comparable and it becomes decoration
 * over a table. The panel has exactly five categorical tokens, so the top five
 * categories take those in fixed order and everything else folds into one
 * "Other" slice. A generated sixth hue would be indistinguishable from an
 * existing one under colour-blindness and would break the palette's checks.
 *
 * **"Other" is not a category, it is the tail.** It wears the muted token, the
 * same de-emphasis grey the rest of the panel uses, so it reads as "the rest"
 * rather than as a sixth kind of file.
 *
 * The exact numbers live in the table beside it. That is not a fallback: the
 * two smallest token hues sit under 3:1 against the light surface, which the
 * palette validator flags as needing visible values somewhere, and a canvas is
 * one opaque element to a screen reader regardless.
 */

/** In fixed order, never cycled. A ninth series is not a ninth colour. */
export const SERIES_TOKENS = ["chart-1", "chart-2", "chart-3", "chart-4", "chart-5"];

/** The token every chart on this page reads for de-emphasised ink. */
export const OTHER_TOKEN = "muted-foreground";

export const MAX_SLICES = SERIES_TOKENS.length + 1;

/**
 * Fold a full category list down to what a donut can honestly show.
 *
 * Returns the tail separately as well as folded, so the table can still list
 * every category — the chart summarises, the table does not.
 */
export function foldCategories(categories) {
  const list = Array.isArray(categories) ? categories.filter(Boolean) : [];

  if (list.length <= MAX_SLICES) {
    return { slices: list, folded: [] };
  }

  const head = list.slice(0, SERIES_TOKENS.length);
  const tail = list.slice(SERIES_TOKENS.length);

  return {
    slices: [
      ...head,
      {
        key: "other",
        bytes: tail.reduce((sum, entry) => sum + (entry.bytes ?? 0), 0),
        count: tail.reduce((sum, entry) => sum + (entry.count ?? 0), 0),
        isTail: true,
      },
    ],
    folded: tail,
  };
}

/**
 * The ECharts option.
 *
 * `tokens` is what useChartTokens resolved — colours already converted to sRGB,
 * because ECharts' own parser cannot read the `oklch()` the cascade returns.
 */
export function breakdownOption({ slices, tokens = {}, label }) {
  const muted = tokens[OTHER_TOKEN] ?? "#888";

  return {
    // The generated description for assistive tech; the table carries the
    // numbers.
    aria: { enabled: true },
    tooltip: {
      trigger: "item",
      backgroundColor: tokens.popover ?? "#fff",
      borderColor: tokens.border ?? "#eee",
      textStyle: { color: tokens["popover-foreground"] ?? "#000" },
      // Share only. Exact sizes are in the table beside this, already
      // formatted by the backend — a second byte formatter in JS would drift
      // from the one that produced every other size on the screen.
      formatter: (params) => `${label(params.name)} · ${params.percent}%`,
    },
    legend: {
      bottom: 0,
      icon: "circle",
      itemWidth: 8,
      itemHeight: 8,
      textStyle: { color: tokens["muted-foreground"] ?? muted },
      formatter: label,
    },
    series: [
      {
        type: "pie",
        // A donut, not a pie: the hole is where the total goes, and it stops
        // the eye trying to compare angles at the centre where they converge.
        radius: ["55%", "78%"],
        center: ["50%", "45%"],
        avoidLabelOverlap: true,
        // No label on every slice — a number beside each arc is chaos and goes
        // unread. The legend names them; the tooltip and table carry values.
        label: { show: false },
        labelLine: { show: false },
        itemStyle: {
          // A 2px gap in the surface colour between segments, so adjacent
          // fills never touch and read as one shape.
          borderColor: tokens.card ?? "#fff",
          borderWidth: 2,
        },
        data: slices.map((slice, index) => ({
          name: slice.key,
          value: slice.bytes,
          itemStyle: {
            color: slice.isTail
              ? muted
              : tokens[SERIES_TOKENS[index]] ?? muted,
          },
        })),
      },
    ],
  };
}
