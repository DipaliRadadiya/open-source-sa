import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { execSync } from "node:child_process";
import { pathToFileURL } from "node:url";

/*
 * Every `result.data?.key` a fetcher reads must be a key its schema declares.
 *
 * Zod drops what the schema does not name, so a wrong key does not throw: it
 * reads `undefined`, falls back to `[]`, and the screen shows an empty list as
 * if that were the answer. The webhook providers fetcher read `providers` while
 * the API and the schema say `webhook_providers`, and for two days the webhook
 * card had no provider names, no setup steps and no way to switch on.
 */
const root = process.cwd();
const schemaDir = path.join(root, "lib/schemas");
const schemas = {};
for (const f of fs.readdirSync(schemaDir).filter((f) => f.endsWith(".js"))) {
  try {
    Object.assign(schemas, await import(pathToFileURL(path.join(schemaDir, f))));
  } catch {
    // A schema module importing through "@/" cannot load under plain node.
  }
}

function keysOf(schema) {
  let d = schema;
  for (let i = 0; i < 6 && d; i++) {
    if (d.shape) return Object.keys(typeof d.shape === "function" ? d.shape() : d.shape);
    d = d._def?.schema ?? d._def?.innerType ?? d._def?.in;
  }
  return null;
}

test("fetchers only read keys their response schema declares", () => {
  const files = execSync("grep -rl 'result\\.data?\\.' lib", { encoding: "utf8" }).trim().split("\n");
  let checked = 0;
  for (const f of files) {
    const src = fs.readFileSync(f, "utf8");
    for (const fn of src.split(/\nexport /)) {
      const used = [...fn.matchAll(/read\([^,]+,\s*(\w+)/g)].map((m) => m[1]);
      const keys = [...new Set([...fn.matchAll(/result\.data\?\.(\w+)/g)].map((m) => m[1]))];
      if (!used.length || !keys.length) continue;
      const declared = used.map((s) => (schemas[s] ? keysOf(schemas[s]) : null));
      if (declared.some((k) => k === null)) continue;
      for (const key of keys) {
        assert.ok(declared.flat().includes(key), `${f}: reads result.data?.${key}, but ${used.join("/")} declares ${declared.flat().join(", ")}`);
      }
      checked++;
    }
  }
  assert.ok(checked >= 15, `expected to check the fetchers, checked ${checked}`);
});
