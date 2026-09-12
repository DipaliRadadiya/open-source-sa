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
  const colours = option.series[0].data.map((d) => d.itemStyle.color);

  assert.deepEqual(colours.slice(0, 5), SERIES_TOKENS.map((tk) => tokens[tk]));
  // The tail wears the de-emphasis grey — it is the rest, not a sixth kind.
  assert.equal(colours.at(-1), "#999999");
});

test("no value is printed on every slice", () => {
  // A number beside every arc is chaos and goes unread; the legend names them
  // and the table carries the sizes.
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

  assert.equal(option.series[0].itemStyle.borderColor, "#ffffff");
  assert.equal(option.series[0].itemStyle.borderWidth, 2);
});

test("it is a donut, not a pie", () => {
  // The hole stops the eye comparing angles at the centre, where they all
  // converge. It was briefly a horizontal stacked bar, which was the right
  // shape for a 340px rail competing with the listing for width — in a sheet
  // there is no width to compete for, and a donut is what this question looks
  // like.
  const option = breakdownOption({
    slices: [cat("images", 5)],
    tokens: {},
    label: (k) => k,
  });

  assert.equal(option.series[0].type, "pie");
  assert.ok(Array.isArray(option.series[0].radius));
  assert.notEqual(option.series[0].radius[0], 0);
});

test("the pie chart type is registered, or the canvas draws nothing", () => {
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

  assert.match(registry, /\bPieChart\b/, "PieChart must be registered with echarts.use");
  // And nothing draws a bar: the registry's whole point is that it lists only
  // what is drawn, so a type left behind after its chart changed shape is the
  // bundle paying for something nobody renders.
  assert.ok(
    !/BarChart/.test(wrapper),
    "no chart draws bars any more, so BarChart must not stay registered",
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
