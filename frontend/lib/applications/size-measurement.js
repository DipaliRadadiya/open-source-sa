import { parseApiDate } from "../format/api-date.js";

export const SETUP_WINDOW_MS = 90 * 1000;
export const NEW_APP_MS = 10 * 60 * 1000;

export function sizeMeasuredDuringSetup(application) {
  if (application?.directory_size_bytes == null) return false;
  const measured = parseApiDate(application.directory_size_measured_at);
  const created = parseApiDate(application.created_at);
  return Boolean(measured && created && measured - created < SETUP_WINDOW_MS);
}

export function shownSizeBytes(application) {
  return sizeMeasuredDuringSetup(application) ? null : (application?.directory_size_bytes ?? null);
}
