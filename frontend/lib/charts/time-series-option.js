/** Colour of a series, or a readable fallback if the token has not resolved. */
function colourOf(series, tokens) {
  return tokens[series.token] ?? "currentColor";
}

// Tokens arrive as `rgb(r, g, b)` (ECharts cannot handle `oklch()`); anything else is returned unchanged.
function withAlpha(colour, alpha) {
  const m = /^rgb\((\s*\d+\s*,\s*\d+\s*,\s*\d+\s*)\)$/.exec(colour ?? "");
  return m ? `rgba(${m[1]}, ${alpha})` : colour;
}

// `data` points carry `t` plus one field per series; `markLine` is a capacity reference.
export function timeSeriesOption({
  data = [],
  series = [],
  axes = [{}],
  tokens = {},
  xLabel = (value) => String(value),
  value = (v) => String(v),
  zoom = false,
  markLine = null,
}) {
  const muted = tokens["muted-foreground"] ?? "currentColor";
  const border = tokens.border ?? "currentColor";

  const built = series.map((s) => {
    const colour = colourOf(s, tokens);
    const line = {
      name: s.label,
      type: "line",
      yAxisIndex: s.axis ?? 0,
      showSymbol: false,
      smooth: true,
      // Never join across a missing sample; it would draw an outage as data.
      connectNulls: false,
      lineStyle: { width: s.width ?? 2, color: colour },
      itemStyle: { color: colour },
      data: data.map((point) => [point.t, point[s.key] ?? null]),
    };

    // A fade to nothing, as in the redesign; a flat tint (plus the aria decal
    // stripes) read as a hatched block.
    if (s.kind === "area") {
      line.areaStyle = {
        color: {
          type: "linear",
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: withAlpha(colour, 0.3) },
            { offset: 1, color: withAlpha(colour, 0) },
          ],
        },
      };
    }

    return line;
  });

  // Attached to the first series so the capacity line stays out of the legend.
  if (markLine && built[0]) {
    built[0].markLine = {
      silent: true,
      symbol: "none",
      lineStyle: {
        type: "dashed",
        color: tokens[markLine.token] ?? muted,
      },
      label: { formatter: markLine.label, color: muted, position: "insideEndTop" },
      data: [{ yAxis: markLine.value }],
    };
  }

  const option = {
    // Pairs with the hidden data table the wrapper renders for screen readers.
    // No decal patterns: the legend, tooltip and data table already name each series.
    aria: { enabled: true, decal: { show: false } },
    // Charts redraw on a poll; re-animating each time is unreadable.
    animation: false,
    /* ECharts does not lay out these bands relative to each other; keep in step: slider 4–34,
     * legend 42–56, labels 68–80 (ECharts: gridBottom-20..-8), grid bottom 88. */
    grid: {
      left: 56,
      right: 20,
      top: 16,
      bottom: zoom ? 88 : 40,
      containLabel: false,
    },
    legend: {
      // Declaration order.
      data: series.map((s) => s.label),
      bottom: zoom ? 42 : 4,
      icon: "roundRect",
      // Wider gap between entries than swatch-to-label, so each swatch pairs with its label.
      itemWidth: 8,
      itemHeight: 8,
      itemGap: 22,
      textStyle: { color: muted, fontSize: 11, padding: [0, 0, 0, 2] },
    },
    tooltip: {
      trigger: "axis",
      backgroundColor: tokens.popover ?? "#fff",
      borderColor: border,
      textStyle: { color: tokens["popover-foreground"] ?? "#000" },
      formatter: (params) => {
        const rows = params
          .filter((p) => p.value?.[1] !== null && p.value?.[1] !== undefined)
          .map(
            (p) =>
              `<div style="display:flex;gap:.75rem;justify-content:space-between">` +
              `<span>${p.marker} ${p.seriesName}</span>` +
              `<strong>${value(p.value[1])}</strong></div>`,
          )
          .join("");

        return (
          `<div style="font-weight:600;margin-bottom:.25rem">` +
          `${xLabel(params[0]?.value?.[0])}</div>${rows}`
        );
      },
    },
    xAxis: {
      type: "time",
      axisLine: { show: false },
      axisTick: { show: false },
      splitLine: { show: false },
      // 11px to match the legend and stay below body text.
      axisLabel: { color: muted, fontSize: 11, hideOverlap: true, formatter: xLabel },
    },
    yAxis: axes.map((axis, index) => ({
      type: "value",
      min: axis.min ?? 0,
      ...(axis.max === undefined ? {} : { max: axis.max }),
      ...(axis.interval === undefined ? {} : { interval: axis.interval }),
      ...(axis.minInterval === undefined ? {} : { minInterval: axis.minInterval }),
      position: axis.position ?? (index === 0 ? "left" : "right"),
      axisLine: { show: false },
      axisTick: { show: false },
      // Opacity because `--border` is already the faintest token.
      splitLine:
        index === 0
          ? { lineStyle: { color: border, type: "solid", width: 1, opacity: 0.7 } }
          : { show: false },
      axisLabel: {
        color: muted,
        fontSize: 11,
        // A forced `max` gets its own label, which can collide with a tick.
        hideOverlap: true,
        ...(axis.formatter ? { formatter: axis.formatter } : {}),
      },
    })),
    series: built,
  };

  if (zoom) {
    const tint = colourOf(series[0] ?? {}, tokens);

    option.dataZoom = [
      { type: "inside", throttle: 50 },
      {
        type: "slider",
        // Tall and tinted so it reads as a zoom control, not a divider.
        height: 30,
        bottom: 4,
        borderColor: border,
        backgroundColor: "transparent",
        // Muted unselected span so the selection reads as a window.
        dataBackground: {
          lineStyle: { color: muted, opacity: 0.35 },
          areaStyle: { color: muted, opacity: 0.12 },
        },
        selectedDataBackground: {
          lineStyle: { color: tint },
          areaStyle: { color: withAlpha(tint, 0.22) },
        },
        fillerColor: withAlpha(tint, 0.12),
        // Grabbable: a filled handle with a border, not the hairline default.
        handleSize: "120%",
        handleStyle: { color: tint, borderColor: border, borderWidth: 1 },
        moveHandleSize: 5,
        moveHandleStyle: { color: withAlpha(tint, 0.5) },
        emphasis: { handleStyle: { color: tint, borderColor: tint } },
        textStyle: { color: muted },
      },
    ];
  }

  return option;
}

// Screen-reader table built from the same data and formatters, so the two cannot diverge.
export function seriesDataTable({ caption, timeLabel, data = [], series = [], xLabel, value }) {
  return {
    caption,
    columns: [timeLabel, ...series.map((s) => s.label)],
    rows: data.map((point) => [
      xLabel(point.t),
      ...series.map((s) => {
        const reading = point[s.key];

        return reading === null || reading === undefined ? "—" : value(reading);
      }),
    ]),
  };
}

// `floor` (e.g. 64 KB/s, core count) keeps an idle series from being scaled to look busy.
export function axisMax(data, keys, { floor = 0, headroom = 1.2 } = {}) {
  let peak = 0;
  for (const point of data) {
    for (const key of keys) {
      const candidate = Number(point[key]);
      if (Number.isFinite(candidate) && candidate > peak) peak = candidate;
    }
  }

  return Math.max(floor, peak * headroom, 1);
}

// Rounds to 1, 2, 2.5 or 5 x 10^n so a forced `max` label does not sit beside a tick.
// Not inside `axisMax`: the I/O 65536 floor would become "97.7 KB/s".
export function niceCeiling(value) {
  if (!Number.isFinite(value) || value <= 0) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const scaled = value / magnitude;
  const step = [1, 2, 2.5, 5, 10].find((candidate) => scaled <= candidate + 1e-9) ?? 10;

  return step * magnitude;
}
