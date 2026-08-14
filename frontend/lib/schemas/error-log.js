import { z } from "zod";

/**
 * One recorded failure (GET /admin/error-logs).
 *
 * The backend unified two logs into this one endpoint, so an entry is now one
 * of TWO different things and the fields that identify each are disjoint:
 *
 * - an **API exception**: status/method/route/exception, and null for every
 *   operation field;
 * - a **failed server operation** (a shell command run by ServerOps):
 *   feature/operation/exit_code/error, and null for route and exception.
 *
 * Nothing distinguishes them by a type field — you infer it from which side is
 * populated. See entryKind() in lib/admin/group-error-logs.js.
 *
 * `error` is the command's stderr, put through a redactor that strips
 * credentials in URLs, bearer/token/password assignments and provider token
 * prefixes (gh*_, glpat-, npm_), then truncated to 1000 characters. It is the
 * only field that says what actually went wrong, and it is the reason this
 * screen is worth opening.
 *
 * `reference` is now genuinely actionable for operations: ServerOps mints it
 * per command and hands it back to the caller, so the panel shows it on a
 * failure ("Install failed — reference abc-…") and this endpoint can look that
 * exact entry up via `?reference=`. It stays useless for API exceptions, where
 * ApiErrorLogWriter still mints a fresh uuid at log time that is never sent to
 * the client — so a reference search finds operations, not exceptions.
 *
 * `message` is near-constant on both sides ("Unexpected API error." /
 * "Server operation failed.") and so is still not rendered as a column.
 *
 * `occurred_at` is Monolog's ISO-8601 datetime, NOT the "DD-MM-YYYY HH:mm:ss"
 * every other endpoint sends — parseApiDate() returns null for it.
 */
export const errorLogEntrySchema = z
  .object({
    occurred_at: z.string().nullish(),
    status: z.number().int().nullish(),
    method: z.string().nullish(),
    route: z.string().nullish(),
    exception: z.string().nullish(),
    message: z.string().nullish(),
    reference: z.string().nullish(),
    user_id: z.number().int().nullish(),
    feature: z.string().nullish(),
    operation: z.string().nullish(),
    exit_code: z.number().int().nullish(),
    error: z.string().nullish(),
  })
  .passthrough();

export const errorLogsResponseSchema = z
  .object({
    error_logs: z.array(errorLogEntrySchema).default([]),
    meta: z
      .object({ truncated: z.boolean().default(false) })
      .passthrough()
      .default({ truncated: false }),
  })
  .passthrough();

// The backend clamps to 1–500; these are the three sizes worth offering.
export const LINE_OPTIONS = [100, 250, 500];
export const DEFAULT_LINES = 100;

/**
 * The backend validates `reference` as `uuid` and 422s anything else, so a
 * mistyped or partial paste has to be caught here — otherwise pasting half a
 * reference returns a validation error where the reader expects "not found".
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isReference(value) {
  return UUID.test(String(value ?? "").trim());
}
