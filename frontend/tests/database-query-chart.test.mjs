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

  assert.match(chart, /import \{ historySeries \} from "@\/lib\/server\/history-series"/);
  assert.match(chart, /historySeries\(metrics\)/);
  assert.match(chart, /value === null \|\| value === undefined \|\| value === ""/);
  assert.equal((chart.match(/connectNulls=\{false\}/g) ?? []).length, 3);
  assert.doesNotMatch(chart, /new Date\(String\(value/);
  assert.match(chart, /<ComposedChart/);
  assert.match(chart, /<Area[\s\S]*dataKey="qps"/);
  assert.match(chart, /type="number"[\s\S]*scale="time"/);
  assert.match(chart, /tickCount=\{5\}/);
  assert.match(chart, /labelFormatter=\{timeLabel\(clock\)\}/);
  assert.match(chart, /<ChartLegend content=\{orderedLegend\(config\)\}/);
  assert.equal((chart.match(/isAnimationActive=\{false\}/g) ?? []).length, 3);
  assert.match(chart, /data\.length < 2/);
  assert.match(chart, /t\("noActivity"\)/);
  assert.doesNotMatch(chart, /<Legend/);
});
