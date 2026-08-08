import { test } from "node:test";
import assert from "node:assert/strict";
import { BACKUP_DEFAULT_TIME, backupTargetFormSchema } from "../lib/schemas/backup.js";

const timeError = (value) => {
  const result = backupTargetFormSchema.shape.schedule_time.safeParse(value);
  return result.success ? null : result.error.issues[0].message;
};

/** Mirrors `date_format:H:i` in `SaveBackupTargetRequest`. */

test("accepts 24-hour times the API would accept", () => {
  for (const value of ["00:00", "02:00", "09:05", "14:30", "23:59"]) {
    assert.equal(timeError(value), null, value);
  }
});

test("refuses anything the backend's date_format would refuse", () => {
  for (const value of ["24:00", "2:00", "02:60", "0200", "2 pm", "02:00:00", ""]) {
    assert.equal(timeError(value), "scheduleTime", JSON.stringify(value));
  }
});

test("the default matches the hour the backend falls back to", () => {
  // `BackupTarget::CRON` is `0 2 * * *`; if that ever moves, the field would
  // otherwise go on claiming 02:00 for every target that has never set a time.
  assert.equal(BACKUP_DEFAULT_TIME, "02:00");
  assert.equal(timeError(BACKUP_DEFAULT_TIME), null);
});
