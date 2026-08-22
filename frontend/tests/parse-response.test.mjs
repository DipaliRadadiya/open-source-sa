import assert from "node:assert/strict";
import test from "node:test";
import { z } from "zod";
import { ResponseShapeError, parsedOr, parsedOrThrow } from "../lib/api/parse-response.js";

function captureWarnings(run) {
  const original = console.warn;
  const seen = [];
  console.warn = (...args) => seen.push(args.map(String).join(" "));
  try {
    return { result: run(), seen };
  } catch (error) {
    return { error, seen };
  } finally {
    console.warn = original;
  }
}

const schema = z.object({ rolled_back: z.boolean(), name: z.string() });

test("a good response is returned and says nothing", () => {
  const { result, seen } = captureWarnings(() =>
    parsedOr(schema, { rolled_back: false, name: "ok" }, "source"),
  );

  assert.deepEqual(result, { rolled_back: false, name: "ok" });
  assert.equal(seen.length, 0, "a valid response must not warn");
});

test("a discarded response names the field that broke it", () => {
  // The whole reason this module exists: `requested_by` and `rolled_back` were
  // each one key, and nothing anywhere said which.
  const { result, seen } = captureWarnings(() =>
    parsedOr(schema, { rolled_back: null, name: "x" }, "getExports", { fallback: true }),
  );

  assert.deepEqual(result, { fallback: true }, "the caller's fallback is returned");
  assert.equal(seen.length, 1);
  assert.match(seen[0], /getExports/, "names the call site");
  assert.match(seen[0], /rolled_back/, "names the offending field");
});

test("an action path throws something identifiable, not a bare ZodError", () => {
  // apiMessage() reads error.response.data.message; a ZodError has no
  // `response`, so it fell through to generic copy — "Couldn't start the
  // update" on an update that was running.
  const { error, seen } = captureWarnings(() =>
    parsedOrThrow(schema, { rolled_back: null, name: "x" }, "startPanelUpdate"),
  );

  assert.ok(error instanceof ResponseShapeError);
  assert.equal(error.source, "startPanelUpdate");
  assert.equal(seen.length, 1, "it warns before throwing");
  assert.match(seen[0], /rolled_back/);
});

test("several bad fields are all reported, capped", () => {
  const wide = z.object(Object.fromEntries(Array.from({ length: 9 }, (_, i) => [`f${i}`, z.string()])));
  const { seen } = captureWarnings(() => parsedOr(wide, {}, "wide"));

  assert.equal(seen.length, 1);
  // Capped at five so a wholly wrong payload does not bury the console.
  assert.equal((seen[0].match(/f\d: /g) || []).length, 5);
});
