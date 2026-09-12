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
  // Whether the panel will empty this one. **Named here or it is lost**: Zod
  // strips unknown keys, so leaving it out meant the Clear action appeared on
  // the server render and then vanished the moment the source poll replaced the
  // catalog — a button that disappears while you look at it.
  //
  // Defaults to false rather than true: a server too old to send the field is
  // one whose DELETE route does not exist either, and offering the action there
  // would fail at the click.
  clearable: z.boolean().optional().default(false),
  // Clearable, but it is the machine's own record — auth.log, ufw.log,
  // fail2ban.log, syslog, kern.log, mail.log, the Let's Encrypt log. Drives a
  // confirmation that names what is being destroyed rather than the sentence
  // used for an access log.
  //
  // Read from the API, never inferred from the key: the registry decides which
  // sources these are, and a list kept here would drift from it.
  clear_sensitive: z.boolean().optional().default(false),
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
