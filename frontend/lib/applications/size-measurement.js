import { parseApiDate } from "../format/api-date.js";

// A measurement this soon after creation was taken while setup was still writing the files:
// a new WordPress read "5.0 KB" for six minutes until the next sweep.
export const SETUP_WINDOW_MS = 90 * 1000;
// An application this young with no size yet gets its first measurement from the open page.
export const NEW_APP_MS = 10 * 60 * 1000;

export function sizeMeasuredDuringSetup(application) {
  if (application?.directory_size_bytes == null) return false;
  const measured = parseApiDate(application.directory_size_measured_at);
  const created = parseApiDate(application.created_at);
  return Boolean(measured && created && measured - created < SETUP_WINDOW_MS);
}

/** The stored size, or null when there is none worth showing ("Not measured"). */
export function shownSizeBytes(application) {
  return sizeMeasuredDuringSetup(application) ? null : (application?.directory_size_bytes ?? null);
}
