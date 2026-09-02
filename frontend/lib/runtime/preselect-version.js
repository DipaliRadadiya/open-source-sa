/**
 * Which runtime version a form should start on.
 *
 * The API returns versions newest-first and marks one `is_default` — the one
 * the server is actually configured to use. Losing that flag anywhere in the
 * chain silently preselects the newest instead, which on a server defaulting to
 * Node 24 offered every new site an end-of-life Node 25. The bug looks like
 * nothing: a version number, in a dropdown, that is a real version.
 *
 * Written three times inside the create form before this — once for runtime
 * fields, once for PHP, once for Node — and mirrored a fourth time inside its
 * test, which is why the test could not have caught a change to any of them.
 */

/**
 * From a list the API shaped: `[{ version, is_default }]`.
 *
 * `preferred` is a server-declared default that arrives separately from the
 * list (the create form receives one per runtime). It wins when it names a
 * version the list actually contains — a stale preference must not select
 * something uninstallable — and is otherwise ignored.
 */
export function preselectVersion(versions = [], preferred = null) {
  const list = Array.isArray(versions) ? versions : [];
  const has = (v) => Boolean(v) && list.some((item) => item?.version === v);

  if (has(preferred)) return preferred;

  const marked = list.find((item) => item?.is_default)?.version;
  if (marked) return marked;

  return list[0]?.version;
}

/**
 * The same rule for a list already mapped to select options
 * (`[{ value, label, is_default }]`), which is the shape the generic runtime
 * field builds before it ever sees a version object.
 */
export function preselectOption(options = []) {
  const list = Array.isArray(options) ? options : [];
  return list.find((option) => option?.is_default)?.value ?? list[0]?.value;
}
