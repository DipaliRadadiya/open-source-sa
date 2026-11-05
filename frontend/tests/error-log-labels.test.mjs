// Readable names in the Error Log and the "into itself" bulk reason (backend fix/plain-texts-errorlog-dipali, 5 Nov).
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { errorLogsResponseSchema } from "../lib/schemas/error-log.js";
import { groupErrorLogs, groupMatches } from "../lib/admin/group-error-logs.js";
import { failureReason } from "../lib/files/bulk-result.js";

const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`messages/${l}.json`, "utf8"))]));

const op = (values) => ({
  occurred_at: "2026-11-05T10:00:00+00:00",
  feature: "pm2",
  operation: "pm2_jlist_user",
  exit_code: 1,
  ...values,
});

test("feature_label and operation_label reach the group, and older entries without them still parse", () => {
  const { error_logs } = errorLogsResponseSchema.parse({
    error_logs: [op({ feature_label: "PM2", operation_label: "List PM2 processes" }), op({ operation: "old_op", feature_label: null })],
  });
  const [named, bare] = groupErrorLogs(error_logs).sort((a, b) => a.operation.localeCompare(b.operation)).reverse();
  assert.equal(named.featureLabel, "PM2");
  assert.equal(named.operationLabel, "List PM2 processes");
  assert.equal(bare.operationLabel, null);
  assert.equal(bare.featureLabel, null);
});

test("search finds a group by its readable name and by its id", () => {
  const [group] = groupErrorLogs([op({ feature_label: "PM2", operation_label: "List PM2 processes" })]);
  assert.ok(groupMatches(group, "list pm2"));
  assert.ok(groupMatches(group, "pm2_jlist_user"));
});

test("the row shows the names, falling back to the ids", () => {
  const row = readFileSync("components/admin/error-logs/error-group-row.jsx", "utf8");
  assert.match(row, /operation: group\.operationLabel \?\? group\.operation \?\? t\("unknownOperation"\)/);
  assert.match(row, /\{group\.featureLabel \?\? group\.feature \?\? t\("unknownFeature"\)\}/);
  assert.match(row, /group\.operationLabel \?\? group\.operation \? ` · \$\{group\.operationLabel \?\? group\.operation\}` : null/);
});

test("bulk move/copy: into_itself is named in every language", () => {
  for (const locale of LOCALES) {
    const reasons = messages[locale].applications.files.bulk.reason;
    const t = (key) => key.split(".").reduce((o, k) => o?.[k], { bulk: { reason: reasons } });
    assert.ok(reasons.into_itself, `${locale}: bulk.reason.into_itself`);
    assert.equal(failureReason("into_itself", t), reasons.into_itself, locale);
    if (locale !== "en") assert.notEqual(reasons.into_itself, messages.en.applications.files.bulk.reason.into_itself, locale);
  }
  assert.equal(failureReason("brand_new", () => "x"), "brand_new", "an unknown reason still shows verbatim");
});
