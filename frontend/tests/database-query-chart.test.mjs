import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import {
  historySeries,
  sampleTime,
} from "../lib/server/history-series.js";

const root = path.join(import.meta.dirname, "..");

test("database history accepts the API's day-first sample timestamps", () => {
  const timestamp = sampleTime("28-07-2026 00:00:00");
  const date = new Date(timestamp);

  assert.equal(Number.isFinite(timestamp), true);
  assert.equal(date.getFullYear(), 2026);
  assert.equal(date.getMonth(), 6);
  assert.equal(date.getDate(), 28);
  assert.equal(date.getHours(), 0);
});

test("database history rejects bad timestamps and sorts valid samples", () => {
  const series = historySeries([
    { sampled_at: "29-07-2026 00:00:00", qps: 3 },
    { sampled_at: "not-a-date", qps: 99 },
    { sampled_at: "28-07-2026 00:00:00", qps: 1 },
  ]);

  assert.deepEqual(
    series.map((point) => point.sampled_at),
    ["28-07-2026 00:00:00", "29-07-2026 00:00:00"],
  );
  assert.equal(series.every((point) => Number.isFinite(point.t)), true);
});

test("database chart uses the shared history and chart presentation contracts", () => {
  const chart = fs.readFileSync(
    path.join(root, "components/databases/query-chart.jsx"),
    "utf8",
  );

  // Unchanged by the move to ECharts: where the samples come from, how a
  // missing reading is told apart from a zero, and the two states that are
  // not a chart at all.
  assert.match(chart, /import \{ historySeries \} from "@\/lib\/server\/history-series"/);
  assert.match(chart, /historySeries\(metrics\)/);
  assert.match(chart, /value === null \|\| value === undefined \|\| value === ""/);
  assert.doesNotMatch(chart, /new Date\(String\(value/);
  assert.match(chart, /data\.length < 2/);
  assert.match(chart, /t\("noActivity"\)/);
  assert.match(chart, /notation: compact \? "compact" : "standard"/);

  // A gap in the samples stays a gap. Joining across a collector outage would
  // draw a straight line through it as though it were data.
  assert.match(chart, /connectNulls: false/);

  // Time axis, one area series for QPS against a second axis for the counts.
  assert.match(chart, /type: "time"/);
  assert.match(chart, /qps\.areaStyle = \{/);
  assert.match(chart, /yAxisIndex/);
  assert.match(chart, /minInterval: 1/);
  assert.match(chart, /formatter: \(value\) => axisNumber\(value\)/);

  // The reason for the migration.
  assert.match(chart, /dataZoom: \[/);
  assert.match(chart, /type: "inside"/);
  assert.match(chart, /type: "slider"/);

  // Animation off — these redraw on a poll, and a chart that re-animates every
  // few seconds is unreadable.
  assert.match(chart, /animation: false/);

  // Legend order is the declaration order, so it matches the summary above the
  // chart. Recharts needed a custom content component for this; ECharts does
  // not, and the workaround must not come back.
  assert.match(chart, /data: \[labels\.qps, labels\.connections, labels\.threads_running\]/);
  assert.doesNotMatch(chart, /orderedLegend/);
  assert.doesNotMatch(chart, /timeLabel/);

  // Colours come from the cascade. The Recharts version hard-coded three hsl()
  // literals because its SVG strokes would not resolve the variables — that
  // took the chart out of the design system and stopped it following the dark
  // theme. No colour literal may reappear here.
  assert.match(chart, /useChartTokens\(TOKENS\)/);
  assert.doesNotMatch(chart, /hsl\(/);
  assert.doesNotMatch(chart, /#[0-9a-fA-F]{3,8}\b/);

  // Nothing from the previous library may remain. Matched on the import and
  // the elements rather than the word: the comments here explain what the
  // Recharts version did and why, which is worth keeping.
  assert.doesNotMatch(chart, /from "recharts"/);
  assert.doesNotMatch(chart, /<ComposedChart|<Area|<Legend|<XAxis|<YAxis/);
});

test("the chart canvas wrapper is registered narrowly and stays accessible", () => {
  const wrapper = fs.readFileSync(
    path.join(root, "components/ui/echart.jsx"),
    "utf8",
  );

  // The bare `echarts` entry point registers every chart type as a side
  // effect and cannot be tree-shaken. Explicit registration is the only
  // reason this can be smaller than what it replaced.
  assert.match(wrapper, /from "echarts\/core"/);
  // Anchored to a real import line — the comment above it quotes the bare
  // entry point precisely in order to warn against it.
  assert.doesNotMatch(wrapper, /^import [^\n]*from "echarts"/m);
  assert.match(wrapper, /echarts\.use\(\[/);

  // A canvas is one opaque element to a screen reader, so the same numbers
  // are always available as text.
  assert.match(wrapper, /aria-busy=\{!ready\}/);
  assert.match(wrapper, /className="sr-only"/);
  assert.match(wrapper, /<caption>/);

  // An instance per host, disposed with it; charts are remounted by route
  // changes and a leaked instance keeps its canvas and its listeners.
  assert.match(wrapper, /chart\.dispose\(\)/);
  assert.match(wrapper, /new ResizeObserver/);

  // Theme changes arrive as a class swap on <html>, which re-renders nothing
  // on its own — subscribed to rather than polled or read once at mount.
  assert.match(wrapper, /useSyncExternalStore/);
  assert.match(wrapper, /attributeFilter: \["class", "style"\]/);
});
