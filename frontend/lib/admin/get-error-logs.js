import { read } from "@/lib/api/read";
import {
  errorLogsResponseSchema,
  LINE_OPTIONS,
  DEFAULT_LINES,
} from "@/lib/schemas/error-log";

/**
 * How many entries to ask for, from the URL. Anything not one of the offered
 * sizes falls back to the default rather than being forwarded — the backend
 * would clamp it silently and the selector would then disagree with the list.
 */
export function linesFromSearchParams(searchParams = {}) {
  const asked = Number(searchParams.lines);
  return LINE_OPTIONS.includes(asked) ? asked : DEFAULT_LINES;
}

/**
 * Recorded API failures (GET /admin/error-logs). Admin-only on the backend.
 *
 * Returns the full read() result — the page needs `failed`/`status` to tell a
 * 403 from a dead API, and "no errors" is the healthy state here, so an empty
 * list must never be confused with a failed fetch.
 */
export function getErrorLogs(lines = DEFAULT_LINES) {
  return read("/admin/error-logs", errorLogsResponseSchema, {
    searchParams: { lines },
  });
}
