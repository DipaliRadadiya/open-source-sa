// A site's cron job that does not run on its site's PHP (a338f771), or null. `target` is the
// site's version when the page has it: the applications list is read only for people who can
// manage cron jobs, and only they can switch it.
export function phpMismatch(job, applications = []) {
  if (!job?.application_id || job.matches_site_php !== false) return null;
  const target = applications.find((application) => application.id === job.application_id)?.php_version ?? null;
  return { current: job.php_version ?? null, target };
}
