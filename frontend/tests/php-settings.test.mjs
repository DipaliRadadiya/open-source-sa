import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  budgetWith,
  memoryCeilingBytes,
  phpSettingsFormSchema,
  phpSizeToBytes,
} from "../lib/schemas/php-settings.js";

const MB = 1024 * 1024;
const PHP_PANEL_SOURCE = readFileSync(
  new URL("../components/applications/php/php-panel.jsx", import.meta.url),
  "utf8",
);

/** Mirrors `ApplicationPhpSettings::toBytes()`. */

test("reads PHP's own size vocabulary", () => {
  assert.equal(phpSizeToBytes("128M"), 128 * MB);
  assert.equal(phpSizeToBytes("1G"), 1024 * MB);
  assert.equal(phpSizeToBytes("512K"), 512 * 1024);
  assert.equal(phpSizeToBytes("1048576"), 1048576);
  assert.equal(phpSizeToBytes("256m"), 256 * MB);
});

test("unlimited is budgeted as 128M, not as nothing", () => {
  // Zero would report a server full of unlimited pools as comfortably empty.
  assert.equal(phpSizeToBytes("-1"), 128 * MB);
});

test("the ceiling is memory per request times workers", () => {
  assert.equal(memoryCeilingBytes("256M", 6), 6 * 256 * MB);
  assert.equal(memoryCeilingBytes("256M", 0), 0);
});

test("the budget takes this site's saved ceiling back out before adding the proposed one", () => {
  // Otherwise every keystroke would count this site twice and the bar would
  // claim the server was full when it was not.
  const memory = { total: 4096 * MB, committed: 1024 * MB, this_site: 512 * MB, sites: 3 };
  const budget = budgetWith(memory, "256M", 4);

  assert.equal(budget.others, 512 * MB);
  assert.equal(budget.thisSite, 1024 * MB);
  assert.equal(budget.committed, 1536 * MB);
  assert.equal(budget.available, 2560 * MB);
  assert.equal(budget.overCommitted, false);
});

test("over-commitment is reported when the numbers exceed the machine", () => {
  const memory = { total: 1024 * MB, committed: 512 * MB, this_site: 256 * MB, sites: 2 };
  assert.equal(budgetWith(memory, "512M", 4).overCommitted, true);
});

test("a server that did not report its memory is never called over-committed", () => {
  assert.equal(budgetWith({ total: 0 }, "512M", 8).overCommitted, false);
});

/** Mirrors `SavePhpSettingsRequest`. */

const sizeError = (value) => {
  const result = phpSettingsFormSchema.shape.memory_limit.safeParse(value);
  return result.success ? null : result.error.issues[0].message;
};

test("accepts the sizes the API accepts and refuses the rest", () => {
  for (const value of ["128M", "1G", "512K", "-1", "268435456"]) {
    assert.equal(sizeError(value), null, value);
  }
  for (const value of ["128 M", "128MB", "1.5G", "M", "-2"]) {
    assert.equal(sizeError(value), "phpSize", value);
  }
});

test("a [section] header is refused — it would start a second pool", () => {
  const field = phpSettingsFormSchema.shape.additional_directives;
  assert.equal(field.safeParse("opcache.enable = 1").success, true);
  assert.equal(field.safeParse("[www]\nopcache.enable = 1").success, false);
  assert.equal(field.safeParse("opcache.enable = 1\n [pool]").success, false);
});

test("disable_functions takes function names and nothing else", () => {
  const field = phpSettingsFormSchema.shape.disable_functions;
  assert.equal(field.safeParse("exec,passthru, shell_exec").success, true);
  assert.equal(field.safeParse("exec; rm -rf /").success, false);
});

test("PHP directive names stay inline with their labels", () => {
  assert.match(PHP_PANEL_SOURCE, /function Label\(\{ label, name, directive \}\)/);
  assert.match(PHP_PANEL_SOURCE, /\(\{directive\}\)/);
  assert.doesNotMatch(PHP_PANEL_SOURCE, /function Directive\(/);
  assert.doesNotMatch(PHP_PANEL_SOURCE, /<Directive\b/);
});

test("auto_prepend_file cannot climb out of the site", () => {
  const field = phpSettingsFormSchema.shape.auto_prepend_file;
  assert.equal(field.safeParse("prepend.php").success, true);
  assert.equal(field.safeParse("../../etc/passwd").success, false);
});
