// At most six slices: five categorical tokens in fixed order, then a muted "Other". Exact numbers
// live in the legend (two hues are under 3:1 contrast, and a canvas is opaque to screen readers).

/** In fixed order, never cycled. A ninth series is not a ninth colour. */
export const SERIES_TOKENS = ["chart-1", "chart-2", "chart-3", "chart-4", "chart-5"];

/** The token every chart on this page reads for de-emphasised ink. */
export const OTHER_TOKEN = "muted-foreground";

export const MAX_SLICES = SERIES_TOKENS.length + 1;

// The tail is also returned so the legend can still list every category.
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

// `tokens` come from useChartTokens, already sRGB: ECharts cannot parse `oklch()`.
export function breakdownOption({ slices, tokens = {}, label }) {
  const muted = tokens[OTHER_TOKEN] ?? "#888";

  return {
    aria: { enabled: true },
    tooltip: {
      trigger: "item",
      backgroundColor: tokens.popover ?? "#fff",
      borderColor: tokens.border ?? "#eee",
      textStyle: { color: tokens["popover-foreground"] ?? "#000" },
      // Share only: exact sizes are in the legend, formatted by the backend.
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
        radius: ["55%", "78%"],
        center: ["50%", "45%"],
        avoidLabelOverlap: true,
        // Values live in the legend, tooltip and table, not on the arcs.
        label: { show: false },
        labelLine: { show: false },
        itemStyle: {
          // Surface-coloured gap so adjacent segments never merge.
          borderColor: tokens.card ?? "#fff",
          borderWidth: 2,
        },
        data: slices.map((slice, index) => ({
          name: slice.key,
          value: slice.bytes,
          itemStyle: {
            color: slice.isTail ? muted : tokens[SERIES_TOKENS[index]] ?? muted,
          },
        })),
      },
    ],
  };
}
