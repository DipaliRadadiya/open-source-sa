/**
 * Parse an API response against its schema, and say so when it does not match.
 *
 * Three bugs in one day came from a schema quietly rejecting a response the
 * server had sent correctly:
 *
 *   - database exports: `requested_by` was declared a string and arrives as an
 *     object, so the polled list silently stayed empty for hours
 *   - panel update: `rolled_back` used `.default(false)`, which fills
 *     `undefined` but not the `null` the API sends — so a *successful* start
 *     threw, and the page reported "Couldn't start the update" while the
 *     update ran to completion behind it
 *   - the same line again on the polling path, so the progress bar never moved
 *
 * They are all the same failure: on a polling loop, a discarded parse is
 * indistinguishable from the server sending nothing. Nothing appears in the
 * console, nothing appears in the network tab, and the only symptom is a UI
 * that does not update — which reads as a backend problem and sends whoever is
 * debugging it to the wrong half of the system. Every one of these cost hours.
 *
 * One `console.warn` naming the field would have ended each of them on the
 * first poll, which is the whole point of this module.
 */

/**
 * Thrown instead of a bare ZodError so callers can tell "the server said no"
 * from "the server said something this build cannot read".
 *
 * `apiMessage()` reads `error.response.data.message`; a ZodError has no
 * `response`, so it fell through to generic copy and the real cause never
 * reached anyone. This at least carries a name worth logging.
 */
export class ResponseShapeError extends Error {
  constructor(source, issues) {
    super(`${source}: the server's reply did not match the expected shape`);
    this.name = "ResponseShapeError";
    this.source = source;
    this.issues = issues;
  }
}

/**
 * `field.path (expected X, received Y)` for each problem, capped.
 *
 * The field path is the only part anyone needs: every one of these bugs was
 * one key, and naming it turns a mystery into a one-line fix.
 */
function describe(issues) {
  return issues
    .slice(0, 5)
    .map((issue) => {
      const path = issue.path?.join(".") || "(root)";
      return `${path}: ${issue.message}`;
    })
    .join("; ");
}

function warn(source, issues) {
  // console.warn, not error: the page usually still renders something, and an
  // error-level entry in a browser console people already ignore is worse than
  // a warning they can find when something looks wrong.
  console.warn(
    `[${source}] response did not match the expected shape, so it was discarded — ${describe(issues)}`,
    { issues },
  );
}

/**
 * Parse, or return `fallback` after saying loudly what was wrong.
 *
 * For the read paths that degrade rather than fail: a list that cannot be
 * parsed becomes an empty list, and the user sees an empty page — but now the
 * console says which field caused it.
 */
export function parsedOr(schema, data, source, fallback = null) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  return fallback;
}

/**
 * Parse, or throw a {@see ResponseShapeError} after warning.
 *
 * For the action paths, where swallowing the failure would leave the caller
 * believing something worked. The throw is what the callers already expect;
 * the warning is what was missing.
 */
export function parsedOrThrow(schema, data, source) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  throw new ResponseShapeError(source, result.error.issues);
}
