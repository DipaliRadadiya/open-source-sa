import { parseApiDate } from "../format/api-date.js";
import { UPDATE_IN_FLIGHT } from "../schemas/application-update.js";

// Installers implementing ApplicationUpdater (backend cd4a443f). Only decides whether the
// dashboard asks at all, so other types get no skeleton flash; the API's `supported` decides
// the card. A new updatable type needs adding here.
export const UPDATABLE_SITE_TYPES = ["moodle", "mautic", "statamic", "craftcms", "uptimekuma", "phpmyadmin", "adminer"];

const BACKUP_FRESH_MS = 24 * 60 * 60 * 1000;

// `last_backup_at` comes as "YYYY-MM-DD HH:mm:ss" (RC-10); accept the panel's usual
// "DD-MM-YYYY HH:mm:ss" too, so a backend fix does not break this.
export function parseBackupStamp(value) {
  const panel = parseApiDate(value);
  if (panel) return panel;
  const m = String(value ?? "").match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);
  if (!m) return null;
  const date = new Date(`${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function backupIsFresh(value, now = Date.now()) {
  const date = parseBackupStamp(value);
  return Boolean(date) && now - date.getTime() < BACKUP_FRESH_MS;
}

/**
 * One state for the card's badge. A run in flight outranks everything; a failed last run
 * stays visible while the same update is still on offer.
 *
 * running | failed | available | up_to_date | lookup_failed | version_unknown | not_active
 */
export function updateCardState(update, history = []) {
  const latest = history[0] ?? null;
  if ((latest && UPDATE_IN_FLIGHT.includes(latest.status)) || update?.blocked_reason === "running") {
    return "running";
  }
  if (latest?.status === "failed" && update?.update_available) return "failed";
  if (update?.update_available && !update?.blocked_reason) return "available";
  if (update?.blocked_reason === "up_to_date") return "up_to_date";
  if (["lookup_failed", "version_unknown", "not_active"].includes(update?.blocked_reason)) {
    return update.blocked_reason;
  }
  return update?.update_available ? "available" : "up_to_date";
}

// Why Update cannot be pressed, as a message key under `applications.update.blocked`, or null.
export function updateBlockedKey(update, { canManage }) {
  if (!canManage) return "noPermission";
  const reason = update?.blocked_reason;
  if (!reason) return null;
  return ["not_active", "version_unknown", "lookup_failed", "up_to_date", "running"].includes(reason)
    ? reason
    : "unsupported";
}
