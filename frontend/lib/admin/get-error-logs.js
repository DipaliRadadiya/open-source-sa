import { read } from "@/lib/api/read";
import {
  errorLogsResponseSchema,
  isReference,
  LINE_OPTIONS,
  DEFAULT_LINES,
} from "@/lib/schemas/error-log";

// Anything that is not a uuid is dropped rather than forwarded.
export function referenceFromSearchParams(searchParams = {}) {
  const asked = searchParams.reference;
  return isReference(asked) ? String(asked).trim() : null;
}

// Values outside the offered sizes fall back to the default (the backend would clamp silently).
export function linesFromSearchParams(searchParams = {}) {
  const asked = Number(searchParams.lines);
  return LINE_OPTIONS.includes(asked) ? asked : DEFAULT_LINES;
}

// Returns the full read() result so an empty list is never confused with a failed fetch.
export function getErrorLogs(lines = DEFAULT_LINES, reference = null) {
  return read("/admin/error-logs", errorLogsResponseSchema, {
    // `reference` narrows to one entry; only well-formed uuids are sent
    // (the backend 422s anything else).
    searchParams: reference ? { lines, reference } : { lines },
  });
}
