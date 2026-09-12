import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import {
  MAX_SLICES,
  OTHER_TOKEN,
  SERIES_TOKENS,
  breakdownOption,
  foldCategories,
} from "../lib/charts/breakdown-option.js";

const root = path.join(import.meta.dirname, "..");

const cat = (key, bytes, count = 1) => ({ key, bytes, count });

test("a list that already fits is left alone", () => {
  const list = [cat("images", 5), cat("code", 3)];

  assert.deepEqual(foldCategories(list), { slices: list, folded: [] });
});

test("exactly six categories still fit", () => {
  const list = Array.from({ length: 6 }, (_, i) => cat(`k${i}`, 10 - i));

  assert.equal(foldCategories(list).slices.length, 6);
  assert.equal(MAX_SLICES, 6);
});

test("a seventh category folds into one Other slice", () => {
  // Never a generated hue: the panel has five categorical tokens, and a sixth
  // would be indistinguishable from an existing one under colour-blindness.
  const list = Array.from({ length: 9 }, (_, i) => cat(`k${i}`, 100 - i, 2));

  const { slices, folded } = foldCategories(list);

  assert.equal(slices.length, MAX_SLICES);
  assert.equal(slices.at(-1).key, "other");
  assert.equal(slices.at(-1).isTail, true);
  assert.equal(folded.length, 4);
});

test("the folded slice carries the tail's whole size and count", () => {
  // Otherwise the donut's total silently disagrees with the table's.
  const list = Array.from({ length: 8 }, (_, i) => cat(`k${i}`, 10, 3));

  const { slices } = foldCategories(list);

  assert.equal(slices.at(-1).bytes, 30); // three tail categories × 10
  assert.equal(slices.at(-1).count, 9);
});

test("a missing list is empty rather than a crash", () => {
  assert.deepEqual(foldCategories(undefined), { slices: [], folded: [] });
});

test("slices take the categorical tokens in fixed order", () => {
  const tokens = Object.fromEntries(
    SERIES_TOKENS.map((token, i) => [token, `#00000${i}`]),
  );
  tokens[OTHER_TOKEN] = "#999999";

  const { slices } = foldCategories(
    Array.from({ length: 9 }, (_, i) => cat(`k${i}`, 100 - i)),
  );

  const option = breakdownOption({ slices, tokens, label: (k) => k });
  // One series per segment: `stack` needs separate series to stack, and it is
  // what gives each segment its own name for the tooltip.
  const colours = option.series.map((series) => series.itemStyle.color);

  assert.deepEqual(colours.slice(0, 5), SERIES_TOKENS.map((tk) => tokens[tk]));
  // The tail wears the de-emphasis grey — it is the rest, not a sixth kind.
  assert.equal(colours.at(-1), "#999999");
});

test("no value is printed inside the segments", () => {
  // An interior stacked segment has no free end to put a label outside of, and
  // most segments here are far too narrow to hold one — so the legend and the
  // tooltip carry it rather than clipping text inside a fill.
  const option = breakdownOption({
    slices: [cat("images", 5)],
    tokens: {},
    label: (k) => k,
  });

  assert.equal(option.series[0].label.show, false);
});

test("segments are separated by a surface-coloured gap", () => {
  const option = breakdownOption({
    slices: [cat("images", 5), cat("code", 3)],
    tokens: { card: "#ffffff" },
    label: (k) => k,
  });

  for (const series of option.series) {
    assert.equal(series.itemStyle.borderColor, "#ffffff");
    assert.equal(series.itemStyle.borderWidth, 2);
  }
});

test("it is one horizontal stacked bar", () => {
  // Part-to-whole is a stacked bar, and it goes horizontal for many or
  // long-named categories — both true here. The donut this replaced was chosen
  // for a 340px rail, and the rail is what cost the listing its width.
  const option = breakdownOption({
    slices: [cat("images", 5), cat("code", 3)],
    tokens: {},
    label: (k) => k,
  });

  assert.ok(
    option.series.every((series) => series.type === "bar"),
    "every segment must be a bar series",
  );
  assert.ok(
    option.series.every((series) => series.stack === "size"),
    "segments that do not share a stack draw as separate bars",
  );
  // Horizontal: the value runs along x and the single band sits on y.
  assert.equal(option.xAxis.type, "value");
  assert.equal(option.yAxis.type, "category");
  assert.equal(option.series[0].barWidth, 24);
});

test("only the bar's outer ends are rounded", () => {
  // A rounded interior edge reads as a gap that is not there. The single-slice
  // case is both ends at once, which a left-or-right rule gets wrong — and it
  // is the common case on a folder holding one kind of file.
  const [first, middle, last] = breakdownOption({
    slices: [cat("a", 3), cat("b", 2), cat("c", 1)],
    tokens: {},
    label: (k) => k,
  }).series;

  assert.deepEqual(first.itemStyle.borderRadius, [4, 0, 0, 4]);
  assert.deepEqual(middle.itemStyle.borderRadius, [0, 0, 0, 0]);
  assert.deepEqual(last.itemStyle.borderRadius, [0, 4, 4, 0]);

  const [only] = breakdownOption({
    slices: [cat("a", 3)],
    tokens: {},
    label: (k) => k,
  }).series;

  assert.deepEqual(only.itemStyle.borderRadius, [4, 4, 4, 4]);
});

test("the axes are off, so nobody reads bytes off a pixel position", () => {
  // A value axis under a part-to-whole bar invites exactly that; the legend's
  // formatted sizes are what answer "how big".
  const option = breakdownOption({
    slices: [cat("images", 5)],
    tokens: {},
    label: (k) => k,
  });

  assert.equal(option.xAxis.show, false);
  assert.equal(option.yAxis.show, false);
});

test("the bar chart type is registered, or the canvas draws nothing", () => {
  // echart.jsx registers chart types by hand so the bundle stays small; a
  // series type that is not in that list renders an empty canvas silently.
  const wrapper = fs.readFileSync(
    path.join(root, "components/ui/echart.jsx"),
    "utf8",
  );

  // Scoped to the `echarts.use([…])` call, not the whole file. Matching
  // anywhere passes on a BarChart that is imported and never registered —
  // which is the silent empty canvas this test exists to prevent, and is
  // exactly what a mutation run proved it would miss.
  const registry = wrapper.slice(
    wrapper.indexOf("echarts.use(["),
    wrapper.indexOf("]);", wrapper.indexOf("echarts.use([")),
  );

  assert.match(registry, /\bBarChart\b/, "BarChart must be registered with echarts.use");
  // And the one it replaced is gone: this was the only pie on the panel, and
  // the registry's whole point is that it lists only what is drawn.
  assert.ok(
    !/PieChart/.test(wrapper),
    "PieChart is no longer drawn anywhere and must not stay registered",
  );
});

test("every breakdown message and type label exists in all three locales", () => {
  const keys = ["title", "subtitle", "unavailable", "empty", "truncated", "columnType", "columnSize", "columnFiles"];
  const types = ["images", "video", "audio", "code", "documents", "archives", "database", "logs", "fonts", "other"];

  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );
    const block = messages.applications.files.breakdown;

    for (const key of keys) {
      assert.equal(typeof block[key], "string", `${locale}: missing breakdown.${key}`);
    }
    for (const type of types) {
      assert.equal(typeof block.types[type], "string", `${locale}: missing breakdown.types.${type}`);
    }
  }
});
