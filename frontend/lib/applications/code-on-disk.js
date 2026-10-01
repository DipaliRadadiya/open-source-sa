// A deploy failing after checkout leaves new code live, so `code_on_disk` wins over `last_commit`.
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
