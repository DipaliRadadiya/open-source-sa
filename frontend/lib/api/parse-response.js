export class ResponseShapeError extends Error {
  constructor(source, issues) {
    super(`${source}: the server's reply did not match the expected shape`);
    this.name = "ResponseShapeError";
    this.source = source;
    this.issues = issues;
  }
}

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

export function parsedOr(schema, data, source, fallback = null) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  return fallback;
}

export function parsedOrThrow(schema, data, source) {
  const result = schema.safeParse(data);

  if (result.success) {
    return result.data;
  }

  warn(source, result.error.issues);

  throw new ResponseShapeError(source, result.error.issues);
}
