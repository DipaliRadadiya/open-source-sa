/**
 * The outcome of a bulk file operation: success, partial, or all failed.
 * `succeeded` / `failed` come from the server; an older response with neither
 * means all succeeded.
 */
export function bulkResult(data, paths) {
  const succeeded = Array.isArray(data?.succeeded) ? data.succeeded : null;
  const failed = Array.isArray(data?.failed) ? data.failed : [];

  return {
    succeeded: succeeded ?? paths,
    failed,
    total: paths.length,
    // Separate from `partial` because the wording differs.
    allFailed: failed.length > 0 && failed.length >= paths.length,
    partial: failed.length > 0 && failed.length < paths.length,
  };
}

/**
 * Translates `not_found`, `exists` or `failed`; any newer backend reason is
 * shown verbatim.
 */
export function failureReason(reason, t) {
  return ["not_found", "exists", "failed"].includes(reason)
    ? t(`bulk.reason.${reason}`)
    : reason;
}
