/**
 * The install picker's options, with installed ones marked rather than removed
 * (PHP's `installable()` excludes them, Node's does not). Matching is exact,
 * not by major: a newer patch of an installed major is a real upgrade.
 */

/**
 * `[{ version, ... }]` → the same, each with `installed: boolean`. A failed
 * install does not count (so it can be retried); in-flight ones do, to avoid
 * a second concurrent apt run.
 */
export function installOptions(installable = [], installed = []) {
  const have = new Set(
    (Array.isArray(installed) ? installed : [])
      .filter((item) => (typeof item === "string" ? true : item?.status !== "failed"))
      .map((item) => (typeof item === "string" ? item : item?.version))
      .filter(Boolean)
      .map(String),
  );

  return (Array.isArray(installable) ? installable : [])
    .filter((option) => option?.version)
    .map((option) => ({ ...option, installed: have.has(String(option.version)) }));
}

/**
 * The first installable version, or "" when everything is installed (Install
 * stays disabled; `allInstalled` explains why).
 */
export function firstInstallable(options = []) {
  return (Array.isArray(options) ? options : []).find((option) => !option.installed)?.version ?? "";
}

/** True when options exist and all are installed. */
export function allInstalled(options = []) {
  const list = Array.isArray(options) ? options : [];
  return list.length > 0 && list.every((option) => option.installed);
}

/**
 * The version the picker should show. Starting an install removes that version
 * from the options, and a `<Select>` whose value matches no item renders empty,
 * so the stored choice is kept only while still installable. Derived, not set
 * in an effect, to avoid a blank frame.
 */
export function resolveVersion(chosen, options = []) {
  const list = Array.isArray(options) ? options : [];
  const stillValid = list.some((option) => option.version === chosen && !option.installed);
  return stillValid ? chosen : firstInstallable(list);
}
