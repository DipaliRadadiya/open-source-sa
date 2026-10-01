/**
 * Whether the deployment screen's branch field is a picker or a text box.
 * When the list is unavailable it falls back to free text and says why; it
 * must never render an empty, disabled picker.
 */

/**
 * "picker" | "text". `text` when there is no linked account, the account is
 * gone (`git_account_missing`), or the request failed or returned nothing.
 */
export function branchFieldMode({ application, state, branches = [] } = {}) {
  const linked =
    Boolean(application?.git_account_id) &&
    Boolean(application?.repository) &&
    !application?.git_account_missing;

  if (!linked) return "text";
  if (state !== "ready" || branches.length === 0) return "text";
  return "picker";
}

/**
 * The notice for the text-box state, or `null` when nothing is wrong (e.g. a
 * site with no linked account).
 */
export function branchFieldNotice({ application, state, branches, current } = {}) {
  // Checked first: a deleted account nulls `git_account_id`.
  if (application?.git_account_missing && application?.repository) return "unlinked";
  if (!application?.git_account_id || !application?.repository) return null;
  if (state === "loading") return "loading";
  if (state === "error") return "error";
  if (state === "empty") return "empty";
  // Saved branch missing upstream: the next deploy would fail at the fetch.
  if (state === "ready" && current && Array.isArray(branches)) {
    const names = branches.map((item) => (typeof item === "string" ? item : item?.name));
    if (!names.includes(current)) return "missing";
  }
  return null;
}

/**
 * Branch options, always including the saved branch even if deleted upstream,
 * so the picker never silently shows a different branch.
 */
export function branchOptions(branches = [], current) {
  const names = (Array.isArray(branches) ? branches : [])
    .map((item) => (typeof item === "string" ? item : item?.name))
    .filter(Boolean);

  const withCurrent = current && !names.includes(current) ? [current, ...names] : names;
  return withCurrent.map((name) => ({ value: name, label: name }));
}
