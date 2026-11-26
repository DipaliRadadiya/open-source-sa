export function phpMismatch(job, applications = []) {
  if (!job?.application_id || job.matches_site_php !== false) return null;
  const target = applications.find((application) => application.id === job.application_id)?.php_version ?? null;
  return { current: job.php_version ?? null, target };
}
