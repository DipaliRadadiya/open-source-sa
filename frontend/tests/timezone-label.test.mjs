import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { timezoneLabel } from "../lib/format/timezone.js";

// Krishna, 5 Nov: the Cron Jobs header read "NEXT RUN · ETC/UTC".
test("UTC aliases display as UTC; real zones are left alone", () => {
  for (const zone of ["Etc/UTC", "UTC", "Etc/UCT", "Etc/Universal", "Etc/Zulu", "Etc/GMT", "GMT"]) assert.equal(timezoneLabel(zone), "UTC", zone);
  for (const zone of ["Asia/Kolkata", "Europe/Berlin", "America/New_York", "Etc/GMT+5"]) assert.equal(timezoneLabel(zone), zone, zone);
  assert.equal(timezoneLabel(null), null);
});

test("every place that prints the server zone uses the label, not the raw id", () => {
  for (const file of ["components/cron-jobs/cronjobs-table.jsx", "components/cron-jobs/cronjobs-cards.jsx", "components/cron-jobs/schedule-field.jsx", "components/cron-jobs/run-history-sheet.jsx", "components/backups/backup-settings-fields.jsx", "components/backups/coverage-table.jsx", "components/backups/coverage-cards.jsx"]) {
    // The Overview's narrow Schedule header uses the label that may break after "/".
    assert.match(fs.readFileSync(file, "utf8"), /timezone: (?:wrappableT|t)imezoneLabel\(/, file);
  }
});
