import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { serviceSchema } from "../lib/schemas/service.js";

const read = (p) => fs.readFileSync(p, "utf8");
const base = { key: "nginx", label: "Nginx", unit: "nginx.service", status: "running", enabled: true };

// 6015911f: each service row carries its installed package version.
test("the schema keeps version and package_version, and reads an older API without them", () => {
  const row = serviceSchema.parse({ ...base, version: "1.24.0", package_version: "1.24.0-2ubuntu7.5" });
  assert.equal(row.version, "1.24.0");
  assert.equal(row.package_version, "1.24.0-2ubuntu7.5");
  assert.equal(serviceSchema.parse({ ...base, version: null, package_version: null }).version, null);
  assert.equal(serviceSchema.parse(base).version, undefined);
});

test("the version follows the unit name in the table and on phones, dpkg's string on hover", () => {
  const label = read("components/services/service-version.jsx");
  assert.match(label, /if \(!version\) return null;/);
  assert.match(label, /title=\{packageVersion \? t\("packageVersion", \{ version: packageVersion \}\) : undefined\}/);
  // On the unit line, not beside the name: there it split "PostgreSQL 16.15" in the narrow column.
  assert.match(read("components/services/services-table.jsx"), /\{unit\}\s*<ServiceVersion version=\{version\} packageVersion=\{packageVersion\} \/>/);
  assert.match(read("components/services/services-cards.jsx"), /\{service\.unit\}\s*<ServiceVersion version=\{service\.version\} packageVersion=\{service\.package_version\} \/>/);
  assert.match(label, /className="whitespace-nowrap tabular-nums"/);
  for (const locale of ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"]) {
    assert.match(JSON.parse(read(`messages/${locale}.json`)).services.packageVersion, /\{version\}/, locale);
  }
});
