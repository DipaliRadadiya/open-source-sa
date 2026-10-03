import { test } from "node:test";
import assert from "node:assert/strict";
import { historySeries, sampleTime } from "../lib/server/history-series.js";

// Central bug #6: the backend writes sampled_at in UTC; read as the browser's clock, a
// Kolkata browser drew the 24h charts 5h30 early.
test("sampled_at is read as UTC whatever the browser's zone", () => {
  // Node applies a TZ change at runtime; CI runs in UTC, where the bug cannot show.
  const zone = process.env.TZ;
  process.env.TZ = "Asia/Kolkata";
  try {
    assert.equal(new Date(sampleTime("03-10-2026 09:35:00")).toISOString(), "2026-10-03T09:35:00.000Z");
  } finally {
    process.env.TZ = zone;
  }
  assert.equal(new Date(sampleTime("03-10-2026 09:35:00")).toISOString(), "2026-10-03T09:35:00.000Z");
  assert.equal(sampleTime("2026-10-03 09:35:00"), null);
  assert.equal(sampleTime(null), null);
});

// Central bug #8: the server was down for two hours and the chart drew a smooth line across it.
test("a stretch without samples becomes a break; regular samples do not", () => {
  const at = (h, m) => `29-09-2026 ${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:00`;
  const points = [[11, 40], [11, 45], [11, 50], [11, 55], [13, 50], [13, 55]].map(([h, m]) => ({ sampled_at: at(h, m), cpu: 10 }));
  const series = historySeries(points);
  assert.equal(series.length, 7);
  const gap = series.find((point) => point.gap);
  assert.ok(gap, "a break point is inserted");
  assert.equal(gap.cpu, undefined, "the break carries no value, so the line stops");
  assert.equal(new Date(gap.t).toISOString(), "2026-09-29T12:00:00.000Z");
  assert.equal(historySeries(points.slice(0, 4)).filter((p) => p.gap).length, 0);
});
