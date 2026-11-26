export function bulkResult(data, paths) {
  const succeeded = Array.isArray(data?.succeeded) ? data.succeeded : null;
  const failed = Array.isArray(data?.failed) ? data.failed : [];

  return {
    succeeded: succeeded ?? paths,
    failed,
    total: paths.length,
    allFailed: failed.length > 0 && failed.length >= paths.length,
    partial: failed.length > 0 && failed.length < paths.length,
  };
}

export function failureReason(reason, t) {
  return ["not_found", "exists", "failed", "into_itself"].includes(reason)
    ? t(`bulk.reason.${reason}`)
    : reason;
}
