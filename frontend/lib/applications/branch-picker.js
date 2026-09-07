/**
 * Whether the branch field can be a picker, or has to stay a text box.
 *
 * The deployment screen edits a site that already exists and already deploys.
 * That makes it different from the create form, where a branch list that will
 * not load simply blocks a site nobody has made yet: here, the same failure
 * would lock the owner out of a field on a running site.
 *
 * Every reason the list can be unavailable ends the same way — fall back to
 * free text, and say why. A picker that renders empty and disabled is the one
 * outcome that must not happen, because it looks like the branch is gone.
 */

/**
 * "picker" | "text"
 *
 * `text` whenever we cannot produce a trustworthy list:
 *
 * - no linked account (a public repository, or one cloned by URL) — there is
 *   no credential to list branches with;
 * - the account was deleted or its token revoked (`git_account_missing`), the
 *   case this screen exists to let people repair;
 * - the request failed or returned nothing.
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
 * Which state the field should report while it is still a text box.
 *
 * `null` where there is nothing worth saying: a site with no linked account is
 * not broken, and telling its owner that branches "could not be loaded" would
 * invent a fault. Only a genuine attempt that failed gets a message.
 */
export function branchFieldNotice({ application, state } = {}) {
  if (!application?.git_account_id || !application?.repository) return null;
  if (application?.git_account_missing) return "unlinked";
  if (state === "loading") return "loading";
  if (state === "error") return "error";
  if (state === "empty") return "empty";
  return null;
}

/**
 * The saved branch, kept in the list even when the provider no longer reports
 * it.
 *
 * A branch deleted upstream is still what this site deploys, and dropping it
 * would leave the picker showing someone else's branch while the form holds
 * the real one — a silent switch of what the next deploy builds.
 */
export function branchOptions(branches = [], current) {
  const names = (Array.isArray(branches) ? branches : [])
    .map((item) => (typeof item === "string" ? item : item?.name))
    .filter(Boolean);

  const withCurrent = current && !names.includes(current) ? [current, ...names] : names;
  return withCurrent.map((name) => ({ value: name, label: name }));
}
