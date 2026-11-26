export function liveCommit(application) {
  const onDisk = application?.code_on_disk?.commit;
  if (onDisk) return onDisk;
  const last = application?.last_commit;
  if (typeof last === "string") return last;
  return last?.sha ?? last?.hash ?? null;
}

export function isDeployIncomplete(application) {
  return application?.code_on_disk?.state === "incomplete";
}
