/**
 * Parse an API response against its schema, and warn with the failing field
 * when it does not match. A silently discarded parse looks exactly like the
 * server sending nothing (e.g. Zod's `.default()` does not replace `null`).
 */

/**
 * Thrown instead of a bare ZodError so callers can tell "the server said no"
 * from "the server said something this build cannot read".
 */
export class ResponseShapeError extends Error {
  constructor(source, issues) {
    super(`${source}: the server's reply did not match the expected shape`);
    this.name = "ResponseShapeError";
    this.source = source;
    this.issues = issues;
  }
}

/** `field.path: message` for each problem, capped at five. */
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
  console.warn(
    `[${source}] response did not match the expected shape, so it was discarded — ${describe(issues)}`,
    { issues },
  );
}

/** Parse, or warn and return `fallback`. For read paths that degrade rather than fail. */
export function parsedOr(schema, data, source, fallback = null) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  return fallback;
}

/**
 * Parse, or warn and throw a {@see ResponseShapeError}. For action paths, where
 * swallowing the failure would make the caller believe it worked.
 */
export function parsedOrThrow(schema, data, source) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  throw new ResponseShapeError(source, result.error.issues);
}
