import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { derivedSpareServers, phpSettingsFormSchemaFor, spareServersAgree } from "../lib/schemas/php-settings.js";

// PHP-FPM tuning fields (backend f462eabc), Krishna 13 Oct.
const base = {
  php_version: "8.4", memory_limit: "256M", upload_max_filesize: "64M", post_max_size: "64M",
  max_execution_time: 30, max_input_time: 60, max_input_vars: 1000, session_gc_maxlifetime: 1440,
  pm_type: "dynamic", pm_max_children: 10, pm_max_requests: 500,
};
const parse = (extra) => phpSettingsFormSchemaFor(0, "/home/u/app").safeParse({ ...base, ...extra });

test("derived numbers match PoolManager::spareServers", () => {
  assert.deepEqual(derivedSpareServers(10), { pm_start_servers: 5, pm_min_spare_servers: 3, pm_max_spare_servers: 8 });
  assert.deepEqual(derivedSpareServers(1), { pm_start_servers: 1, pm_min_spare_servers: 1, pm_max_spare_servers: 2 });
});

test("empty means automatic and is sent as null", () => {
  const r = parse({ pm_start_servers: "", pm_min_spare_servers: "", pm_max_spare_servers: "", pm_process_idle_timeout: "", pm_max_spawn_rate: "" });
  assert.ok(r.success);
  assert.equal(r.data.pm_start_servers, null);
  assert.equal(r.data.pm_process_idle_timeout, null);
});

test("spare servers: all three or none, in php-fpm's order", () => {
  assert.ok(parse({ pm_start_servers: 3, pm_min_spare_servers: 2, pm_max_spare_servers: 6 }).success);
  for (const bad of [{ pm_start_servers: 3 }, { pm_start_servers: 5, pm_min_spare_servers: 6, pm_max_spare_servers: 8 }, { pm_start_servers: 5, pm_min_spare_servers: 2, pm_max_spare_servers: 12 }]) {
    const r = parse(bad);
    assert.equal(r.success, false);
    assert.deepEqual(r.error.issues.map((i) => [i.path.join("."), i.message]), [["pm_start_servers", "pmSpareOrder"]]);
  }
  assert.equal(spareServersAgree({ pm_max_children: 4, pm_start_servers: 2, pm_min_spare_servers: 1, pm_max_spare_servers: 4 }), true);
});

test("ranges match SavePhpSettingsRequest", () => {
  assert.equal(parse({ pm_process_idle_timeout: 0 }).error.issues[0].message, "rangeIdleTimeout");
  assert.equal(parse({ pm_process_idle_timeout: 86401 }).error.issues[0].message, "rangeIdleTimeout");
  assert.equal(parse({ pm_max_spawn_rate: 1025 }).error.issues[0].message, "rangeSpawnRate");
  assert.ok(parse({ pm_process_idle_timeout: 86400, pm_max_spawn_rate: 1024 }).success);
});

test("pool lines pass the client directive check", () => {
  assert.ok(parse({ additional_directives: "env[APP_ENV] = production\nrequest_terminate_timeout = 300\ncatch_workers_output = yes" }).success);
  assert.equal(parse({ additional_directives: "[www]\nuser = root" }).success, false);
});

test("the Advanced tab shows only the numbers the mode reads", () => {
  const panel = fs.readFileSync("components/applications/php/php-panel.jsx", "utf8");
  assert.match(panel, /pmType === "dynamic" \|\| errors\.pm_start_servers \?/);
  assert.match(panel, /compareVersions\(version, "8\.1"\) >= 0 \?[\s\S]{0,160}name="pm_max_spawn_rate"/);
  assert.match(panel, /pmType === "ondemand" \?[\s\S]{0,160}name="pm_process_idle_timeout"/);
  for (const key of ["pm_start_servers", "pm_min_spare_servers", "pm_max_spare_servers", "pm_max_spawn_rate", "pm_process_idle_timeout"]) {
    assert.match(panel, new RegExp(`advanced: \\[[^\\]]*"${key}"`));
  }
});
