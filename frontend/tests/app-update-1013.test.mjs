import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { backupIsFresh, parseBackupStamp, updateBlockedKey, updateCardState, UPDATABLE_SITE_TYPES } from "../lib/applications/app-update.js";
import { applicationUpdateResponseSchema } from "../lib/schemas/application-update.js";

// One-click update card (backend cd4a443f), Krishna 13 Oct.
const read = (p) => fs.readFileSync(p, "utf8");

test("the documented GET response parses", () => {
  const body = {
    update: { supported: true, installed_version: "5.0.9", latest_version: "5.0.11", newest_version: "5.3.0", update_available: true, newer_major: "5.3.0", blocked_reason: null, last_backup_at: "2026-10-13 09:10:02" },
    history: [{ id: 3, from_version: "5.0.8", to_version: "5.0.9", status: "completed", status_title: "Updated", reason: null, reason_title: null, step: null, reference: null, user: "admin", started_at: "13-10-2026 09:00:00", finished_at: "13-10-2026 09:03:00", created_at: "13-10-2026 09:00:00" }],
  };
  assert.ok(applicationUpdateResponseSchema.safeParse(body).success);
});

test("one badge state, a run in flight first", () => {
  assert.equal(updateCardState({ update_available: true, blocked_reason: null }, []), "available");
  assert.equal(updateCardState({ update_available: true, blocked_reason: "running" }, [{ status: "completed" }]), "running");
  assert.equal(updateCardState({ update_available: true, blocked_reason: null }, [{ status: "pending" }]), "running");
  assert.equal(updateCardState({ update_available: true, blocked_reason: null }, [{ status: "failed" }]), "failed");
  assert.equal(updateCardState({ update_available: false, blocked_reason: "up_to_date" }, [{ status: "failed" }]), "up_to_date");
  for (const reason of ["lookup_failed", "version_unknown", "not_active"]) {
    assert.equal(updateCardState({ update_available: false, blocked_reason: reason }, []), reason);
  }
});

test("Update is greyed with a reason", () => {
  assert.equal(updateBlockedKey({ blocked_reason: null }, { canManage: false }), "noPermission");
  assert.equal(updateBlockedKey({ blocked_reason: null }, { canManage: true }), null);
  assert.equal(updateBlockedKey({ blocked_reason: "running" }, { canManage: true }), "running");
  assert.equal(updateBlockedKey({ blocked_reason: "something_new" }, { canManage: true }), "unsupported");
});

test("last_backup_at in either date format; fresh means under a day", () => {
  assert.equal(parseBackupStamp("2026-10-13 09:10:02").getDate(), 13);
  assert.equal(parseBackupStamp("13-10-2026 09:10:02").getMonth(), 9);
  assert.equal(parseBackupStamp(null), null);
  const now = new Date("2026-10-13T12:00:00").getTime();
  assert.equal(backupIsFresh("2026-10-13 09:10:02", now), true);
  assert.equal(backupIsFresh("2026-10-11 09:10:02", now), false);
  assert.equal(backupIsFresh(null, now), false);
});

test("the dashboard asks only for the six updatable types, and streams it", () => {
  assert.deepEqual([...UPDATABLE_SITE_TYPES].sort(), ["adminer", "craftcms", "mautic", "moodle", "n8n", "nodered", "phpmyadmin", "statamic", "uptimekuma"]);
  const page = read("app/(app)/applications/[application]/page.jsx");
  assert.match(page, /UPDATABLE_SITE_TYPES\.includes\(application\.site_type\)[\s\S]{0,80}<Suspense fallback=\{<UpdateCardSkeleton \/>\}>/);
  // Every message key the card uses exists in English.
  const card = read("components/applications/update-card.jsx");
  const en = JSON.parse(read("messages/en.json")).applications.update;
  const get = (key) => key.split(".").reduce((o, k) => o?.[k], en);
  for (const [, key] of card.matchAll(/\bt\("([a-zA-Z.]+)"/g)) assert.ok(get(key), `missing applications.update.${key}`);
  for (const state of ["running", "failed", "available", "up_to_date", "lookup_failed", "version_unknown", "not_active"]) assert.ok(en.state[state]);
  for (const key of ["noPermission", "not_active", "version_unknown", "lookup_failed", "up_to_date", "running", "unsupported"]) assert.ok(en.blocked[key]);
});
