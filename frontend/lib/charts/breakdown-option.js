/**
 * The storage breakdown on the file manager: which kinds of file use the space.
 *
 * **A horizontal stacked bar, not a donut.** Part-to-whole is a stacked bar, and
 * it goes horizontal when the categories are many or long-named — both are true
 * here ("Node modules", "Documents", nine or ten of them). The donut this
 * replaces was chosen for a 340px side rail, and it cost the file listing that
 * width; a single full-width bar says the same thing in 40px of height, leaving
 * the listing the whole row. A donut also spends horizontal space on a circle,
 * which is the one resource this screen is short of.
 *
 * Two rules shape the rest and both come from the chart guidance rather than
 * taste.
 *
 * **Six segments, never more.** Past about six the segments stop being
 * comparable and the thing becomes decoration over a table. The panel has
 * exactly five categorical tokens, so the top five categories take those in
 * fixed order and everything else folds into one "Other". A generated sixth hue
 * would be indistinguishable from an existing one under colour-blindness and
 * would break the palette's checks.
 *
 * **"Other" is not a category, it is the tail.** It wears the muted token, the
 * same de-emphasis grey the rest of the panel uses, so it reads as "the rest"
 * rather than as a sixth kind of file.
 *
 * The exact numbers live in the legend beside it. That is not a fallback: the
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
 * Fold a full category list down to what one bar can honestly show.
 *
 * Returns the tail separately as well as folded, so the legend can still list
 * every category — the chart summarises, the legend does not.
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
 *
 * One stacked bar on one category band. The axes are switched off entirely: a
 * value axis under a part-to-whole bar invites reading absolute bytes off a
 * pixel position, which is what the legend's formatted sizes are for, and the
 * band has no second entry for a category axis to name.
 */
export function breakdownOption({ slices, tokens = {}, label }) {
  const muted = tokens[OTHER_TOKEN] ?? "#888";
  const surface = tokens.card ?? "#fff";
  const total = slices.reduce((sum, slice) => sum + (slice.bytes ?? 0), 0);

  return {
    // The generated description for assistive tech; EChart's own sr-only table
    // carries the numbers.
    aria: { enabled: true },
    // The bar is the whole chart, so it gets the whole box. No axis labels and
    // no legend inside the canvas — the legend is real DOM beside it, where its
    // values can be selected, translated and read by a screen reader.
    grid: { top: 0, right: 0, bottom: 0, left: 0, containLabel: false },
    xAxis: { type: "value", show: false, max: total || 1 },
    yAxis: { type: "category", show: false, data: [""] },
    tooltip: {
      trigger: "item",
      backgroundColor: tokens.popover ?? "#fff",
      borderColor: tokens.border ?? "#eee",
      textStyle: { color: tokens["popover-foreground"] ?? "#000" },
      // Share only. Exact sizes are in the legend beside this, already
      // formatted by the backend — a second byte formatter in JS would drift
      // from the one that produced every other size on the screen.
      formatter: (params) => {
        const share = total > 0 ? Math.round((params.value / total) * 100) : 0;

        return `${label(params.seriesName)} · ${share}%`;
      },
    },
    // One series per segment rather than one series of many values: `stack`
    // needs separate series to stack, and it is also what gives each segment
    // its own name for the tooltip and its own colour.
    series: slices.map((slice, index) => ({
      name: slice.key,
      type: "bar",
      stack: "size",
      barWidth: 24,
      // No label inside the segments. An interior stacked segment has no free
      // end to put a label outside of, and most segments here are far too
      // narrow to hold one — the guidance is to let the legend and tooltip
      // carry it rather than clip text inside a fill.
      label: { show: false },
      itemStyle: {
        color: slice.isTail ? muted : tokens[SERIES_TOKENS[index]] ?? muted,
        // A 2px gap in the surface colour between segments, so adjacent fills
        // never touch and read as one shape.
        borderColor: surface,
        borderWidth: 2,
        // Rounded only on the two outer ends of the whole bar, square where
        // segments meet: a rounded interior edge would read as a gap that is
        // not there. [topLeft, topRight, bottomRight, bottomLeft]
        borderRadius: radiusFor(index, slices.length),
      },
      emphasis: { itemStyle: { borderColor: surface, borderWidth: 2 } },
      data: [slice.bytes],
    })),
  };
}

/**
 * 4px on the bar's outer ends only.
 *
 * A single segment is both ends at once, which is the common case on a folder
 * holding one kind of file — and the case a left/right-only rule gets wrong.
 */
function radiusFor(index, count) {
  const first = index === 0;
  const last = index === count - 1;

  return [
    first ? 4 : 0,
    last ? 4 : 0,
    last ? 4 : 0,
    first ? 4 : 0,
  ];
}
