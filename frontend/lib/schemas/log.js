import { z } from "zod";

export const LOG_GROUPS = ["web", "database", "php", "system", "security", "daemon"];

// Line counts the API accepts; 5000 is its hard cap.
export const LINE_OPTIONS = [100, 200, 500, 1000, 5000];

/** The API's own bounds — `ApplicationLogManager::MAX_LINES`, clamped server-side. */
export const MIN_LINES = 1;
export const MAX_LINES = 5000;

// Null when not a number; clamped at the ends rather than rejected, as the server does.
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
  // Whether the panel will empty this one. Must be declared or Zod strips it.
  // Defaults to false: a server too old to send it has no DELETE route either.
  clearable: z.boolean().optional().default(false),
  // Clearable, but a system record (auth.log, ufw.log, syslog…): the confirm
  // names what is destroyed. Read from the API, never inferred from the key.
  clear_sensitive: z.boolean().optional().default(false),
  // Only plain files have byte offsets; the journal and privileged reads cannot
  // answer `?after=` and return the whole window again.
  follow: z.boolean().optional().default(true),
  // `/download` refuses the same sources with a 422.
  downloadable: z.boolean().optional().default(true),
});

export const logSourcesResponseSchema = z.object({
  logs: z.array(logSourceSchema),
});

export const logReadSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  lines: z.array(z.string()),
  // Byte offset to pass back as `after` when tailing. Null for sources that
  // cannot be tailed by offset (journal, privileged reads, worker logs).
  cursor: z.number().nullable(),
  truncated: z.boolean().optional(),
});

export const logReadResponseSchema = z.object({ log: logReadSchema });
