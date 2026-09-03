import assert from "node:assert/strict";
import test from "node:test";
import {
  axisMax,
  seriesDataTable,
  timeSeriesOption,
} from "../lib/charts/time-series-option.js";

const TOKENS = {
  "chart-1": "oklch(0.62 0.19 260)",
  "chart-2": "oklch(0.68 0.15 165)",
  border: "oklch(0.9 0 0)",
  "muted-foreground": "oklch(0.5 0 0)",
  popover: "oklch(1 0 0)",
  "popover-foreground": "oklch(0.2 0 0)",
};

const DATA = [
  { t: 1000, cpu: 10, memory: 40 },
  { t: 2000, cpu: null, memory: 42 },
  { t: 3000, cpu: 30, memory: 44 },
];

const SERIES = [
  { key: "cpu", label: "CPU", token: "chart-1", kind: "area" },
  { key: "memory", label: "Memory", token: "chart-2", kind: "area" },
];

function build(overrides = {}) {
  return timeSeriesOption({
    data: DATA,
    series: SERIES,
    tokens: TOKENS,
    xLabel: (v) => `t${v}`,
    value: (v) => `${v}%`,
    ...overrides,
  });
}

test("a missing sample stays a gap rather than being joined across", () => {
  const option = build();
  const cpu = option.series[0];

  assert.equal(cpu.connectNulls, false);
  // The null survives into the series data as a null, not a zero — a zero
  // would draw the CPU dropping to idle during a collector outage.
  assert.deepEqual(
    cpu.data.map(([, v]) => v),
    [10, null, 30],
  );
});

test("legend order follows declaration, not the library's own ordering", () => {
  const option = build();

  assert.deepEqual(option.legend.data, ["CPU", "Memory"]);
  assert.deepEqual(
    option.series.map((s) => s.name),
    ["CPU", "Memory"],
  );
});

test("colours come from the resolved tokens, never from literals", () => {
  const option = build();

  assert.equal(option.series[0].lineStyle.color, TOKENS["chart-1"]);
  assert.equal(option.series[1].lineStyle.color, TOKENS["chart-2"]);
  assert.equal(option.series[0].areaStyle.color, TOKENS["chart-1"]);

  // An unresolved token must not fall back to an invented colour.
  const unresolved = timeSeriesOption({ data: DATA, series: SERIES, tokens: {} });
  assert.equal(unresolved.series[0].lineStyle.color, "currentColor");
});

test("a line series gets no area fill", () => {
  const option = build({
    series: [{ key: "cpu", label: "CPU", token: "chart-1", kind: "line" }],
  });

  assert.equal(option.series[0].areaStyle, undefined);
});

test("animation is off — these redraw on a poll", () => {
  assert.equal(build().animation, false);
});

test("zoom is opt-in, and moves the legend out of the slider's way", () => {
  const without = build();
  assert.equal(without.dataZoom, undefined);
  assert.equal(without.legend.bottom, 4);

  const withZoom = build({ zoom: true });
  assert.deepEqual(
    withZoom.dataZoom.map((z) => z.type),
    ["inside", "slider"],
  );

  /*
   * The relationship, not the number. This asserted `legend.bottom === 28`,
   * which was the value that put the legend INSIDE the slider's band -- the
   * two overlapped by six pixels and rendered as one smudged strip. A test
   * pinning a magic number cannot tell a layout from a collision, so it
   * happily guarded the bug it was named after.
   */
  const slider = withZoom.dataZoom.find((z) => z.type === "slider");
  const sliderTop = slider.bottom + slider.height;
  assert.ok(
    withZoom.legend.bottom > sliderTop,
    `legend (${withZoom.legend.bottom}) must clear the slider (top ${sliderTop})`,
  );

  // Axis labels hang BELOW the grid line, roughly gridBottom-20..gridBottom-8,
  // so the grid has to clear the legend by more than the label band is tall.
  const LABEL_BAND = 20;
  assert.ok(
    withZoom.grid.bottom - LABEL_BAND > withZoom.legend.bottom,
    `grid (${withZoom.grid.bottom}) must leave room for labels above the legend`,
  );
  assert.ok(withZoom.grid.bottom > without.grid.bottom);
});

test("a capacity line hangs off a series so it never enters the legend", () => {
  const option = build({
    markLine: { value: 8, label: "8 cores", token: "muted-foreground" },
  });

  assert.equal(option.series[0].markLine.data[0].yAxis, 8);
  assert.equal(option.series[0].markLine.silent, true);
  // Two series declared, two legend entries — the reference is not one of them.
  assert.equal(option.legend.data.length, 2);
});

test("a second axis sits on the right and draws no grid of its own", () => {
  const option = build({
    axes: [{}, { minInterval: 1 }],
    series: [
      { key: "cpu", label: "CPU", token: "chart-1", axis: 0 },
      { key: "memory", label: "Memory", token: "chart-2", axis: 1 },
    ],
  });

  assert.equal(option.yAxis.length, 2);
  assert.equal(option.yAxis[0].position, "left");
  assert.equal(option.yAxis[1].position, "right");
  assert.deepEqual(option.yAxis[1].splitLine, { show: false });
  assert.equal(option.yAxis[1].minInterval, 1);
  assert.equal(option.series[1].yAxisIndex, 1);
});

test("an axis maximum is only set when one was asked for", () => {
  assert.equal("max" in build().yAxis[0], false);
  assert.equal(build({ axes: [{ max: 100 }] }).yAxis[0].max, 100);
  assert.equal(build().yAxis[0].min, 0);
});

test("the tooltip drops series with no reading at that moment", () => {
  const option = build();
  const html = option.tooltip.formatter([
    { value: [2000, null], seriesName: "CPU", marker: "M" },
    { value: [2000, 42], seriesName: "Memory", marker: "M" },
  ]);

  assert.match(html, /t2000/);
  assert.match(html, /Memory/);
  assert.doesNotMatch(html, /CPU/);
});

test("axisMax respects a floor so an idle series does not look dramatic", () => {
  const quiet = [{ x: 10 }, { x: 20 }];

  // Without a floor the peak drives the scale; with one, the floor wins.
  assert.equal(axisMax(quiet, ["x"], { floor: 65536 }), 65536);
  assert.equal(axisMax([{ x: 100000 }], ["x"], { floor: 65536 }), 120000);
  // Never zero: an all-zero series on a 0-0 axis has nothing to draw against.
  assert.equal(axisMax([{ x: 0 }], ["x"]), 1);
  // Non-numeric readings are ignored rather than poisoning the scale.
  assert.equal(axisMax([{ x: null }, { x: "n/a" }, { x: 5 }], ["x"], { headroom: 1 }), 5);
});

test("the hidden table carries the same series, in the same order, as the plot", () => {
  const table = seriesDataTable({
    caption: "Resource usage",
    timeLabel: "Time",
    data: DATA,
    series: SERIES,
    xLabel: (v) => `t${v}`,
    value: (v) => `${v}%`,
  });

  assert.deepEqual(table.columns, ["Time", "CPU", "Memory"]);
  assert.equal(table.rows.length, DATA.length);
  assert.deepEqual(table.rows[0], ["t1000", "10%", "40%"]);
  // A gap reads as a gap here too — not as a zero, and not as blank.
  assert.deepEqual(table.rows[1], ["t2000", "—", "42%"]);
});
