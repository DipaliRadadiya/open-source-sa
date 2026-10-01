// A silently discarded parse looks like the server sent nothing, so mismatches warn with the field.

// Lets callers tell "the server said no" from "this build cannot read the response".
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

// For action paths, where swallowing the failure would make the caller believe it worked.
export function parsedOrThrow(schema, data, source) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  throw new ResponseShapeError(source, result.error.issues);
}
