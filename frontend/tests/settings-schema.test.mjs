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
 * `mysql` and `mysql_binlog` shipped on the backend, the API returned them
 * correctly, `available()` was true, `read()` was correct — and the Database
 * tab still said "No MySQL or MariaDB server is running on this machine",
 * because `settingsSchema` did not name them and the parse deleted them before
 * any page saw them. No error, no warning, a 200 response, and four rounds of
 * debugging aimed at the backend.
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
  assert.ok(keys.includes("mysql"));
});

test("a real API payload survives the parse intact", () => {
  // Captured from a live panel, which is how the stripping was finally found.
  const payload = {
    settings: {
      mysql: {
        engine: "mariadb",
        engine_label: "MariaDB",
        present: true,
        reachable: true,
        max_connections: 151,
        configured_max_connections: null,
        capped: false,
        open_files_limit: 32183,
        connections: 1,
        floor: 10,
        recommended_max: 73,
        memory_mb: 1968,
      },
      mysql_binlog: {
        engine: "mariadb",
        engine_label: "MariaDB",
        present: true,
        reachable: true,
        enabled: false,
        format: "MIXED",
        expire_seconds: 864000,
        max_binlog_size: 1073741824,
        log_count: 0,
        log_bytes: 0,
        oldest_log: null,
        // PHP renders an empty map as `[]`, so both shapes have to parse.
        configured: [],
      },
    },
  };

  const parsed = settingsResponseSchema.safeParse(payload);

  assert.equal(parsed.success, true);
  assert.equal(parsed.data.settings.mysql.max_connections, 151);
  assert.equal(parsed.data.settings.mysql_binlog.format, "MIXED");
});

test("the unreachable shape parses too, with its nulls", () => {
  // The engine is installed but the panel cannot authenticate: readings are
  // null rather than 0, and null must not be a parse failure.
  const parsed = settingsResponseSchema.safeParse({
    settings: {
      mysql: {
        engine: "mariadb",
        engine_label: "MariaDB",
        present: true,
        reachable: false,
        max_connections: null,
        configured_max_connections: null,
      },
    },
  });

  assert.equal(parsed.success, true);
  assert.equal(parsed.data.settings.mysql.reachable, false);
  assert.equal(parsed.data.settings.mysql.max_connections, null);
});
