import { z } from "zod";

export const LOG_GROUPS = ["web", "database", "php", "system", "security", "daemon"];

// Line counts the API accepts; 5000 is its hard cap.
export const LINE_OPTIONS = [100, 200, 500, 1000, 5000];

/** The API's own bounds — `ApplicationLogManager::MAX_LINES`, clamped server-side. */
export const MIN_LINES = 1;
export const MAX_LINES = 5000;

/**
 * A typed line count, or null when it is not a number this API will take.
 *
 * The presets cover the common windows; this is for the person who wants the
 * last 37 lines, or 2500. Clamped rather than rejected at the ends — someone
 * typing 9000 means "as much as I can have", and the server clamps to the same
 * ceiling anyway, so refusing would only make them guess it.
 */
export function normalizeLineCount(value) {
  const n = Number.parseInt(String(value ?? "").trim(), 10);
  if (!Number.isFinite(n)) return null;
  return Math.min(Math.max(n, MIN_LINES), MAX_LINES);
}

export const logSourceSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  size: z.number().nullable().optional(),
  modified: z.string().nullable().optional(),
  readable: z.boolean(),
});

export const logSourcesResponseSchema = z.object({
  logs: z.array(logSourceSchema),
});

export const logReadSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  lines: z.array(z.string()),
  // Byte offset to pass back as `after` when tailing.
  cursor: z.number(),
  truncated: z.boolean().optional(),
});

export const logReadResponseSchema = z.object({ log: logReadSchema });
