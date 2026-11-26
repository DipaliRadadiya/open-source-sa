import { read } from "@/lib/api/read";
import {
  errorLogsResponseSchema,
  isReference,
  LINE_OPTIONS,
  DEFAULT_LINES,
} from "@/lib/schemas/error-log";

export function referenceFromSearchParams(searchParams = {}) {
  const asked = searchParams.reference;
  return isReference(asked) ? String(asked).trim() : null;
}

export function linesFromSearchParams(searchParams = {}) {
  const asked = Number(searchParams.lines);
  return LINE_OPTIONS.includes(asked) ? asked : DEFAULT_LINES;
}

export function getErrorLogs(lines = DEFAULT_LINES, reference = null) {
  return read("/admin/error-logs", errorLogsResponseSchema, {
    searchParams: reference ? { lines, reference } : { lines },
  });
}
