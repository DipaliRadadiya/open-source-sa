// Never render an empty disabled picker; fall back to a text field.

export function branchFieldMode({ application, state, branches = [] } = {}) {
  const linked =
    Boolean(application?.git_account_id) &&
    Boolean(application?.repository) &&
    !application?.git_account_missing;

  if (!linked) return "text";
  if (state !== "ready" || branches.length === 0) return "text";
  return "picker";
}

export function branchFieldNotice({ application, state, branches, current } = {}) {
  if (application?.git_account_missing && application?.repository) return "unlinked";
  if (!application?.git_account_id || !application?.repository) return null;
  if (state === "loading") return "loading";
  if (state === "error") return "error";
  if (state === "empty") return "empty";
  if (state === "ready" && current && Array.isArray(branches)) {
    const names = branches.map((item) => (typeof item === "string" ? item : item?.name));
    if (!names.includes(current)) return "missing";
  }
  return null;
}

export function branchOptions(branches = [], current) {
  const names = (Array.isArray(branches) ? branches : [])
    .map((item) => (typeof item === "string" ? item : item?.name))
    .filter(Boolean);

  const withCurrent = current && !names.includes(current) ? [current, ...names] : names;
  return withCurrent.map((name) => ({ value: name, label: name }));
}
