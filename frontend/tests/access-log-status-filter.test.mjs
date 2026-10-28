import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { isAccessLog, matchesSeverity } from "../lib/logs/severity.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// Reported 28 Oct: the Access log offered "Errors" and "Warnings", levels a request log does not have.
test("only request logs count as access logs; the web servers' error logs do not", () => {
  for (const key of ["access", "nginx_access", "apache_access", "openlitespeed_access", "waf_detect"]) assert.ok(isAccessLog(key), key);
  for (const key of ["error", "nginx_error", "apache_error", "php_fpm", "access_log_old", undefined, null]) assert.ok(!isAccessLog(key), String(key));
});

test("on an access log the buckets are the HTTP status: 5xx, and 4xx + 5xx", () => {
  const line = (status) => `1.2.3.4 - - [28/Oct/2026:09:00:00 +0000] "GET / HTTP/1.1" ${status} 512 "-" "curl"`;
  assert.ok(matchesSeverity(line(502), "web", "errors"));
  assert.ok(!matchesSeverity(line(404), "web", "errors"));
  assert.ok(matchesSeverity(line(404), "web", "warnings"));
  assert.ok(!matchesSeverity(line(200), "web", "warnings"));
});

test("the toolbar names the filter by status on access logs, by level everywhere else", () => {
  const toolbar = read("components/logs/log-toolbar.jsx");
  assert.match(toolbar, /aria-label=\{t\(statusFilter \? "statusFilterLabel" : "severityLabel"\)\}/);
  assert.match(toolbar, /\{key !== "all" && statusFilter \? t\(`statusFilter\.\$\{key\}`\) : t\(`severity\.\$\{key\}`\)\}/);
  for (const p of ["components/applications/logs/application-logs-panel.jsx", "components/logs/logs-panel.jsx"]) assert.match(read(p), /statusFilter=\{isAccessLog\(source\?\.key\)\}/, p);
  for (const locale of LOCALES) {
    const logs = JSON.parse(read(`messages/${locale}.json`)).logs;
    assert.deepEqual(logs.statusFilter, { errors: "5xx", warnings: "4xx + 5xx" }, locale);
    assert.ok(logs.statusFilterLabel, locale);
  }
});
