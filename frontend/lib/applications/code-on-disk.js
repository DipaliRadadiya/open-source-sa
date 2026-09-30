/**
 * Which commit a site is really running, and whether it got there cleanly.
 *
 * `last_commit` is written on a successful deploy only. Deploys replace the
 * files before the build, so a deploy that fails after its checkout leaves the
 * new commit serving while `last_commit` still names the old one — and the
 * screens said "the previous version is still live", which was the opposite of
 * true. `code_on_disk` is the backend's answer; `last_commit` is only the
 * fallback for a payload that does not carry it (the site list).
 */
export function liveCommit(application) {
  const onDisk = application?.code_on_disk?.commit;
  if (onDisk) return onDisk;
  const last = application?.last_commit;
  if (typeof last === "string") return last;
  return last?.sha ?? last?.hash ?? null;
}

/** The last deploy failed after its checkout: the new code is live, not fully deployed. */
export function isDeployIncomplete(application) {
  return application?.code_on_disk?.state === "incomplete";
}
