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
 * `message` USED to be near-constant ("Unexpected API error." / "Server
 * operation failed.") and was deliberately not rendered. Since 2026-08-31 it is
 * the exception's own message, redacted and capped at 500 characters, and it is
 * the first thing worth reading. Entries written before that carry the old
 * constant, so the screen has to survive both.
 *
 * `file` (`path:line`, relative to the install) and `trace` (up to five
 * application frames, vendor dropped) arrived with it and are absent on older
 * entries — hence nullish rather than defaulted.
 *
 * `command` is the command line that failed, redacted where it was written.
 * It is the field an operation entry was missing: "log / exists / exit 1" says
 * nothing, `sudo -n test -f /var/log/…` says everything. `attempts` and
 * `duration_ms` separate a lock retried three times over twelve seconds from
 * something that died once in 40ms — a distinction the timestamps cannot make.
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
    file: z.string().nullish(),
    // PHP's empty array serialises as [] rather than an object, and older
    // entries omit it entirely — both have to parse, or one legacy row would
    // reject the whole page.
    trace: z.array(z.string()).nullish().catch(null),
    command: z.string().nullish(),
    duration_ms: z.number().nullish(),
    attempts: z.number().int().nullish(),
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
