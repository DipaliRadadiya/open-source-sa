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

  // The option itself is built by lib/charts/time-series-option.js and tested
  // there against the object, not the source text. What matters here is that
  // this chart is wired to it, and with the shape this screen needs: QPS as an
  // area on its own axis, whole connections on a second, and the zoom the
  // migration was for.
  assert.match(chart, /timeSeriesOption\(\{/);
  assert.match(chart, /seriesDataTable\(\{/);
  assert.match(chart, /zoom: true/);
  assert.match(chart, /axes: \[\{ formatter: axisNumber \}, \{ minInterval: 1 \}\]/);
  assert.match(chart, /kind: "area", axis: 0/);
  assert.match(chart, /token: "chart-2", axis: 1/);

  // Colours come from the cascade. The Recharts version hard-coded three hsl()
  // literals because its SVG strokes would not resolve the variables — that
  // took the chart out of the design system and stopped it following the dark
  // theme. No colour literal may reappear here.
  assert.match(chart, /useChartTokens\(TOKENS\)/);
  assert.doesNotMatch(chart, /orderedLegend/);
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
