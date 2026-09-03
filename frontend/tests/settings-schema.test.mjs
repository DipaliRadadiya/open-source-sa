import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { settingsResponseSchema, settingsSchema } from "../lib/schemas/settings.js";

const root = path.join(import.meta.dirname, "..");
const backend = path.join(root, "..", "backend");

/*
 * Zod strips unknown keys, and that is how two settings groups disappeared.
 *
 * Two groups once shipped on the backend, the API returned them correctly, and
 * the page still rendered its "nothing here" state — because `settingsSchema`
 * did not name them and the parse deleted them before any page saw them. No
 * error, no warning, a 200 response, and several rounds of debugging aimed at
 * a backend that had been answering correctly the whole time.
 *
 * Those groups have since been removed, but the trap has not: it applies to
 * every group added from now on.
 *
 * A group missing here is invisible in exactly the way that is hardest to
 * find, so it is worth a test that reads the backend rather than a list
 * somebody has to remember to update.
 */

/** Every group key the backend can return, read from the setting groups. */
function backendGroupKeys() {
  const dir = path.join(backend, "app/Services/Server/Settings");
  const keys = [];

  for (const file of fs.readdirSync(dir)) {
    if (!file.endsWith("Settings.php")) continue;

    const source = fs.readFileSync(path.join(dir, file), "utf8");

    // `public function key(): string { return 'general'; }`
    const match = source.match(/function key\(\)\s*:\s*string\s*\{\s*return\s*'([a-z_]+)'/);
    if (match) keys.push(match[1]);
  }

  return keys;
}

test("every backend settings group is named in the frontend schema", () => {
  const declared = Object.keys(settingsSchema.shape);
  const missing = backendGroupKeys().filter((key) => !declared.includes(key));

  assert.deepEqual(
    missing,
    [],
    `these groups would be silently stripped from the API response: ${missing.join(", ")}`,
  );
});

test("the backend groups this test can see are the ones we expect", () => {
  // Guards the guard: if the regex above stops matching, the test above would
  // pass vacuously by finding no groups at all.
  const keys = backendGroupKeys();

  assert.ok(keys.length >= 6, `only found ${keys.length} backend groups — the parse is probably broken`);
  // Two groups that have been there since the beginning; if the regex breaks,
  // these disappear and the check above starts passing vacuously.
  assert.ok(keys.includes("general"));
  assert.ok(keys.includes("security"));
});

test("a real API payload survives the parse intact", () => {
  // Captured from a live panel: unknown-key stripping is invisible unless a
  // real response is parsed and checked field by field.
  const parsed = settingsResponseSchema.safeParse({
    settings: {
      general: {
        timezone: "Etc/UTC",
        ntp: true,
        clock_synchronized: true,
        hostname: "suresh-dont-delete",
      },
      redis: {
        maxmemory: "0",
        maxmemory_policy: "noeviction",
        has_password: true,
        password_manageable: true,
        running: true,
      },
    },
  });

  assert.equal(parsed.success, true);
  assert.equal(parsed.data.settings.general.hostname, "suresh-dont-delete");
  assert.equal(parsed.data.settings.redis.running, true);
});

test("a group the schema does not name is dropped, which is the point", () => {
  // Documents the behaviour the cross-check above exists to catch, so the
  // reason for that test survives even when nobody remembers the incident.
  const parsed = settingsResponseSchema.safeParse({
    settings: {
      general: {
        timezone: "Etc/UTC",
        ntp: true,
        clock_synchronized: true,
        hostname: "box",
      },
      invented_group: { a: 1 },
    },
  });

  assert.equal(parsed.success, true);
  assert.equal("invented_group" in parsed.data.settings, false);
});
