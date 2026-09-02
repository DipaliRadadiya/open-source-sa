/**
 * One ECharts option shape for every time-series card in the panel.
 *
 * The five charts differ in about six ways — area or line, one axis or two,
 * percent or bytes or a bare number, a capacity line or not — and were
 * otherwise the same eighty lines of configuration copied five times. Under
 * Recharts that duplication is what let the dashboard's 2x2 grid drift: four
 * cards that are supposed to read as one instrument, each free to disagree
 * about grid colour, tick density or tooltip wording.
 *
 * This is a pure function on purpose. Everything above it is React and canvas
 * and therefore awkward to test; the decisions worth pinning — that a gap in
 * the samples is never joined, that the legend follows declaration order, that
 * an axis floor is respected — are all decisions about this object, and can be
 * asserted directly.
 */

/** Colour of a series, or a readable fallback if the token has not resolved. */
function colourOf(series, tokens) {
  return tokens[series.token] ?? "currentColor";
}

/**
 * @param {object}   spec
 * @param {object[]} spec.data     points, each carrying `t` plus one field per series
 * @param {object[]} spec.series   { key, label, token, kind, axis, width }
 * @param {object[]} spec.axes     { min, max, formatter, ticks, minInterval, position }
 * @param {object}   spec.tokens   resolved CSS custom properties
 * @param {Function} spec.xLabel   formats an x value for the axis and tooltip header
 * @param {Function} spec.value    formats a y value for the tooltip
 * @param {boolean}  spec.zoom     add the dataZoom pair
 * @param {object}   [spec.markLine] { value, label, token } — a capacity reference
 */
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
      // A missing sample is a gap in what we know. Joining across it draws a
      // straight line through a collector outage as though it were data.
      connectNulls: false,
      lineStyle: { width: s.width ?? 2, color: colour },
      itemStyle: { color: colour },
      data: data.map((point) => [point.t, point[s.key] ?? null]),
    };

    if (s.kind === "area") {
      line.areaStyle = { color: colour, opacity: 0.18 };
    }

    return line;
  });

  // The capacity reference hangs off the first series rather than being a
  // series of its own, so it never appears in the legend as though it were
  // something measured.
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
    // ECharts' generated description, alongside the hidden table the wrapper
    // renders. A canvas is one opaque element without both.
    aria: { enabled: true, decal: { show: true } },
    // These redraw on a poll. A chart that re-animates every few seconds is
    // unreadable, and on the 2x2 grid it is four of them out of step.
    animation: false,
    grid: {
      left: 56,
      right: 20,
      top: 16,
      bottom: zoom ? 64 : 40,
      containLabel: false,
    },
    legend: {
      // Declaration order. Recharts built the legend from its own internal
      // ordering, so load average listed itself "1m · 15m · 5m" against the
      // order the stat card above used, and a custom content component had to
      // put it back. Nothing to work around here.
      data: series.map((s) => s.label),
      bottom: zoom ? 28 : 4,
      icon: "roundRect",
      itemWidth: 10,
      itemHeight: 10,
      textStyle: { color: muted },
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
      axisLabel: { color: muted, hideOverlap: true, formatter: xLabel },
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
      // Only the first axis draws grid lines; two sets of them on one plot is
      // a grid that looks broken rather than two scales.
      splitLine:
        index === 0
          ? { lineStyle: { color: border, type: "dashed" } }
          : { show: false },
      axisLabel: {
        color: muted,
        ...(axis.formatter ? { formatter: axis.formatter } : {}),
      },
    })),
    series: built,
  };

  if (zoom) {
    option.dataZoom = [
      { type: "inside", throttle: 50 },
      {
        type: "slider",
        height: 18,
        bottom: 4,
        borderColor: border,
        textStyle: { color: muted },
      },
    ];
  }

  return option;
}

/**
 * The same numbers as text, for anyone who cannot see the canvas.
 *
 * Built here rather than per chart so it cannot quietly diverge from what is
 * drawn: same data, same series order, same formatters. A chart that renders
 * without one is a chart a screen reader cannot read at all — the SVG this
 * replaced at least exposed its points.
 */
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

/**
 * An axis ceiling that leaves headroom without letting a quiet series look
 * dramatic. `floor` is what the axis must always include — 64 KB/s for the
 * I/O charts, the core count for load — so a flat idle line reads as idle
 * rather than being auto-scaled into a mountain range.
 */
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
