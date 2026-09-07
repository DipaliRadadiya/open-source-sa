/**
 * The install picker's options, with the ones already on the server marked.
 *
 * The two runtimes disagreed about this and nothing in the frontend noticed,
 * because the filtering was happening in the backend or not at all. PHP's
 * `installable()` excludes what is installed; Node's returns the six newest
 * majors from `fnm list-remote` and excludes nothing. So the same dialog
 * offered PHP 8.4 nowhere and Node 24.20.0 as if it were new.
 *
 * Marked rather than removed. Node offers six versions in total: drop the two
 * that are installed and you cannot tell whether 24 is missing because you have
 * it or because fnm never offered it. And once every offered version is
 * installed, a filtered list is empty — at which point the button says "no new
 * versions are available", which is the wrong sentence for "you have them all".
 *
 * Matching is exact, not by major. `fnm` offers the newest patch of each major,
 * so with 24.20.0 installed and 24.20.1 published, 24.20.1 is a real upgrade
 * and must stay selectable.
 */

/** `[{ version, ... }]` → the same, each with `installed: boolean`. */
export function installOptions(installable = [], installed = []) {
  const have = new Set(
    (Array.isArray(installed) ? installed : [])
      .map((item) => (typeof item === "string" ? item : item?.version))
      .filter(Boolean)
      .map(String),
  );

  return (Array.isArray(installable) ? installable : [])
    .filter((option) => option?.version)
    .map((option) => ({ ...option, installed: have.has(String(option.version)) }));
}

/**
 * Which version the dialog should open on.
 *
 * The first option that can actually be installed, not simply the first — a
 * disabled item sitting preselected means the Install button is live and does
 * nothing useful, and the picker looks broken rather than informative.
 *
 * Falls back to "" when everything is installed, which leaves Install disabled;
 * `allInstalled` is what the caller uses to say why.
 */
export function firstInstallable(options = []) {
  return (Array.isArray(options) ? options : []).find((option) => !option.installed)?.version ?? "";
}

/** Something is offered, and every bit of it is already here. */
export function allInstalled(options = []) {
  const list = Array.isArray(options) ? options : [];
  return list.length > 0 && list.every((option) => option.installed);
}
