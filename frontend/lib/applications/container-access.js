export function showsContainerAccess(application) {
  return application?.serving_profile === "docker";
}

export function defaultContainer(containers) {
  return (containers ?? []).find((container) => container.main) ?? containers?.[0] ?? null;
}

export function shellCommandFor(access, container) {
  const command = access?.commands?.shell ?? "";
  if (!command || !container || container.main) return command;
  const appPick = access.app ? ` @${access.app}` : null;
  if (appPick && command.endsWith(appPick)) return `${command}/${container.service}`;
  return `${command} @${container.service}`;
}

export function turnOnNeeds(access) {
  const owner = access?.owner;
  if (!owner || owner.ssh_keys > 0) return "nothing";
  if (access.password_login === false) return "key";
  if (access.password_login !== true) return "nothing";
  return owner.has_password ? "nothing" : "keyOrPassword";
}

export function hostSshStillOn(access, enforced = null) {
  const owner = access?.owner;
  if (!owner || owner.container_login) return false;
  const canSignIn = owner.ssh_keys > 0 || access.password_login !== false;
  if (enforced === false) return canSignIn;
  return owner.ssh_access && canSignIn;
}
