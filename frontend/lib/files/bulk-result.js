/**
 * The outcome of a bulk file operation, in the three states it actually has.
 *
 * A batch can half-work, and that is a first-class outcome rather than an edge
 * case: reporting it as success hides the failures, reporting it as failure
 * hides everything that did change and invites a retry of work already done.
 *
 * `succeeded` / `failed` are what the server reports. An older response that
 * carries neither is read as "all of them worked", which is what the single
 * path form has always meant.
 */
export function bulkResult(data, paths) {
  const succeeded = Array.isArray(data?.succeeded) ? data.succeeded : null;
  const failed = Array.isArray(data?.failed) ? data.failed : [];

  return {
    succeeded: succeeded ?? paths,
    failed,
    total: paths.length,
    // Nothing landed. Worth its own name because the wording differs: "none of
    // the 5 could be moved" is a different sentence from "3 of 5 moved".
    allFailed: failed.length > 0 && failed.length >= paths.length,
    partial: failed.length > 0 && failed.length < paths.length,
  };
}

/**
 * `reason` is one of `not_found`, `exists` or `failed`. Anything else is a
 * reason the backend added after this shipped — show it verbatim rather than
 * swallowing it, since an unexplained row is worse than an untranslated one.
 */
export function failureReason(reason, t) {
  return ["not_found", "exists", "failed"].includes(reason)
    ? t(`bulk.reason.${reason}`)
    : reason;
}
