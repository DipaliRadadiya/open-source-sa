import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

/*
 * ECharts is imported module by module to keep the bundle small, and every
 * module has to be handed to `echarts.use()`. A config for one that was never
 * registered is DROPPED — no error, no console warning, nothing on the canvas.
 *
 * The load chart asked for a `markLine` marking the core count. The option was
 * built correctly and reached `setOption`; `MarkLineComponent` was not in the
 * list, so the line was never drawn. The chart still forced its ceiling up to
 * the core count to make room for it, so the visible result was a flat line
 * pinned to the floor of an 85%-empty box — which reads as broken data rather
 * than an idle server.
 *
 * Nothing catches this: not the build (plain JS), not lint, not a render test
 * against a canvas. So the option keys are matched against the registration
 * list directly.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const echart = read("components/ui/echart.jsx");

// option key -> the module that has to be registered for it to do anything
const NEEDS = {
  markLine: "MarkLineComponent",
  markPoint: "MarkPointComponent",
  markArea: "MarkAreaComponent",
  dataZoom: "DataZoomComponent",
  legend: "LegendComponent",
  tooltip: "TooltipComponent",
  grid: "GridComponent",
  aria: "AriaComponent",
};

const registered = (() => {
  const block = echart.match(/echarts\.use\(\[([\s\S]*?)\]\)/);
  assert.ok(block, "echarts.use([...]) not found");
  // Comments first, THEN split: the prose in this list contains commas, so
  // splitting first cut "MarkLineComponent" onto the tail of a comment clause
  // and the name stopped matching. A parser that quietly finds nothing is the
  // same failure this whole file is about.
  return new Set(
    block[1]
      .replace(/\/\/.*$/gm, "")
      .split(",")
      .map((name) => name.trim())
      .filter((name) => /^[A-Za-z]+$/.test(name)),
  );
})();

test("every chart feature the panel configures is actually registered", () => {
  const files = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        if (!/node_modules|\.next/.test(p)) walk(p);
      } else if (/\.jsx?$/.test(entry.name)) files.push(p);
    }
  };
  walk("components");
  walk("lib/charts");

  const missing = [];
  for (const file of files) {
    if (file.endsWith("components/ui/echart.jsx")) continue;
    const src = read(file).replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    for (const [key, component] of Object.entries(NEEDS)) {
      // An option key, i.e. `markLine:` or `markLine =` in a builder signature.
      if (!new RegExp(`\\b${key}\\s*[:=]`).test(src)) continue;
      if (registered.has(component)) continue;
      missing.push(`${file} configures \`${key}\` but ${component} is not in echarts.use([...])`);
    }
  }
  assert.deepEqual(missing, [], `\n${missing.join("\n")}`);
});

test("the capacity line on the load chart is one of them", () => {
  // The specific regression. MarkLine is the only registered component with no
  // second caller, so a tidy-up could plausibly drop it again.
  assert.ok(registered.has("MarkLineComponent"), "MarkLineComponent must stay registered");
  assert.match(read("lib/charts/time-series-option.js"), /built\[0\]\.markLine = \{/);
  assert.match(read("components/dashboard/server-load-chart.jsx"), /markLine: cores/);
});

test("a forced ceiling lands on a tick, instead of printing one beside it", async () => {
  /*
   * `cores * 1.05` is 4.2 on a four-core box, and a forced `max` always gets
   * its own label — so "4.2" printed hard against the "4" tick below it.
   *
   * Rounded by the CALLER, not inside axisMax: the two I/O charts floor at
   * 65536 (64 KB/s, already round in the units their axis prints) and this
   * would push them to 100000, i.e. "97.7 KB/s".
   */
  const src = read("lib/charts/time-series-option.js");
  assert.match(src, /export function niceCeiling/);
  assert.match(read("components/dashboard/server-load-chart.jsx"), /floor: niceCeiling\(cores \* 1\.05\)/);
  for (const chart of ["disk-io-chart", "network-io-chart"]) {
    const io = read(`components/dashboard/${chart}.jsx`);
    assert.match(io, /floor: 65536/, chart);
    assert.doesNotMatch(io, /niceCeiling/, `${chart} must keep its binary-round floor`);
  }

  const { niceCeiling } = await import("../lib/charts/time-series-option.js");
  assert.equal(niceCeiling(4.2), 5);
  assert.equal(niceCeiling(1), 1);
  assert.equal(niceCeiling(2.1), 2.5);
  assert.equal(niceCeiling(67.2), 100, "a 64-core box still gets a round ceiling");
  assert.equal(niceCeiling(0), 1, "never zero — an axis with max 0 has no scale");
});

test("the stop control looks like a stop control", () => {
  /*
   * It was a bare outlined `Square`: at 16px that is the same glyph as an
   * unticked checkbox, and in destructive red on a table row it read as a
   * rendering fault rather than a button.
   */
  const src = read("components/dashboard/kill-process-button.jsx");
  assert.match(src, /<CircleStop className="size-4" \/>/);
  assert.doesNotMatch(src, /<Square\b/);
});
