import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * The six states the live server will not produce, forced through a stub API
 * against a real standalone build: paused, provisioning, pending, failed,
 * process-down and deploy-failed. All six render distinctly; two were wrong.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("a status explanation wraps; only the support reference truncates", () => {
  /*
   * The Status column is 125px wide and these were `truncate`, so
   *
   *   "Could not create the PHP pool"     -> "Could not create the…"
   *   "Setting up PHP for this application" -> "Setting up PHP for t…"
   *
   * — the reason for a failure with the reason removed, and no tooltip, so
   * the rest existed nowhere on the page.
   *
   * The reference keeps its truncation on purpose: a 36-character UUID is
   * still recognisable from a prefix, a sentence is not, and wrapping it would
   * shift every row beneath a failure.
   */
  const src = strip(read("components/applications/application-status-badge.jsx"));

  for (const re of [
    /<p className="max-w-40 text-xs text-pretty text-muted-foreground">/,     // provisioning step
    /<p className="max-w-52 text-xs text-pretty text-destructive">[\s\S]{0,80}failed_reason_title/, // the reason
  ]) {
    assert.match(src, re);
  }
  // and the one that still truncates, with its reason still on it
  assert.match(src, /max-w-52 truncate font-mono text-xs text-destructive/);
  assert.match(
    read("components/applications/application-status-badge.jsx"),
    /A partial\s+\* reference is still recognisable|partial\s+reference is still recognisable/,
    "the deliberate truncation must keep its justification",
  );
});

test("every status badge is filled, including the quiet one", () => {
  /*
   * `pending` mapped to `secondary`, which the design pass had turned into a
   * quiet LABEL with no fill — so a Pending application sat in a column of
   * coloured pills with nothing drawn around it and read as a badge that had
   * failed to render.
   */
  const badge = read("components/ui/badge.jsx");
  assert.match(badge, /muted:\s*\n\s*"bg-muted text-muted-foreground/);

  const src = read("components/applications/application-status-badge.jsx");
  const variants = src.match(/export const STATUS_VARIANTS = \{([\s\S]*?)\}/)[1];
  assert.match(variants, /pending: "muted"/);
  // No status may fall back to the fill-less label variant.
  assert.doesNotMatch(variants, /"secondary"/);
  for (const status of ["active", "failed", "provisioning", "pending"]) {
    assert.match(variants, new RegExp(`${status}: "`), `${status} needs a variant`);
  }
});

test("the four statuses the API can send all have a variant", () => {
  // APPLICATION_STATUSES is the list the filter dropdown offers; a status with
  // no variant renders as an unstyled fallback.
  const src = read("components/applications/application-status-badge.jsx");
  const statuses = src.match(/export const APPLICATION_STATUSES = \[([^\]]+)\]/)[1]
    .split(",").map((s) => s.trim().replace(/['"]/g, "")).filter(Boolean);
  const variants = src.match(/export const STATUS_VARIANTS = \{([\s\S]*?)\}/)[1];
  for (const s of statuses) assert.match(variants, new RegExp(`\\b${s}:`), `no variant for ${s}`);
});
