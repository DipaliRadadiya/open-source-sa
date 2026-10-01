/**
 * One ECharts option shape for every time-series card in the panel, so the
 * charts stay visually consistent. Kept a pure function so it can be unit-tested.
 */

/** Colour of a series, or a readable fallback if the token has not resolved. */
function colourOf(series, tokens) {
  return tokens[series.token] ?? "currentColor";
}

/**
 * The same colour, see-through. Tokens arrive as `rgb(r, g, b)` (the chart
 * host converts them; ECharts cannot handle `oklch()`). Anything else is
 * returned unchanged.
 */
function withAlpha(colour, alpha) {
  const m = /^rgb\((\s*\d+\s*,\s*\d+\s*,\s*\d+\s*)\)$/.exec(colour ?? "");
  return m ? `rgba(${m[1]}, ${alpha})` : colour;
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
      // Never join across a missing sample; it would draw an outage as data.
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
    aria: { enabled: true, decal: { show: true } },
    // Charts redraw on a poll; re-animating each time is unreadable.
    animation: false,
    /*
     * Bands below the plot are stacked by hand; ECharts does not lay them out
     * relative to each other. Keep these numbers in step:
     *
     *   slider   4 .. 34   (bottom 4, height 30)
     *   legend  42 .. 56   (clears the slider by 8)
     *   labels  68 .. 80   (ECharts puts them at gridBottom-20 .. gridBottom-8)
     *   grid bottom 88     (so the labels clear the legend by 12)
     */
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
      // Gap between entries larger than swatch-to-label, so each swatch reads
      // as paired with its own label.
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
      // Only the first axis draws grid lines. Solid and faint rather than
      // dashed; opacity because `--border` is already the faintest token.
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

/**
 * The same numbers as a text table for screen readers. Built from the same
 * data, series order and formatters as the chart so the two cannot diverge.
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
 * An axis ceiling with headroom. `floor` is always included (e.g. 64 KB/s for
 * I/O, core count for load) so an idle series is not auto-scaled to look busy.
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

/**
 * Round a ceiling up to a number the tick sequence would have chosen anyway
 * (1, 2, 2.5 or 5 x 10^n), so a forced `max` label does not sit beside a tick.
 *
 * Applied by the caller, not inside `axisMax`: the I/O charts' 65536 floor is
 * already round in KB/s and would become "97.7 KB/s".
 */
export function niceCeiling(value) {
  if (!Number.isFinite(value) || value <= 0) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const scaled = value / magnitude;
  const step = [1, 2, 2.5, 5, 10].find((candidate) => scaled <= candidate + 1e-9) ?? 10;

  return step * magnitude;
}
