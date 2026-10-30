// Recipes and Container-tab apps; the endpoint refuses every other site.
export function showsContainerAccess(application) {
  return application?.serving_profile === "docker";
}

export function defaultContainer(containers) {
  return (containers ?? []).find((container) => container.main) ?? containers?.[0] ?? null;
}

// The server's line opens the main container. Another one is picked with
// `@<service>`, or `@<app>/<service>` when the owner has several Docker apps and
// the line already ends in `@<app>`. Only the tail changes, so quoting and `-p`
// stay exactly as the API wrote them.
export function shellCommandFor(access, container) {
  const command = access?.commands?.shell ?? "";
  if (!command || !container || container.main) return command;
  const appPick = access.app ? ` @${access.app}` : null;
  if (appPick && command.endsWith(appPick)) return `${command}/${container.service}`;
  return `${command} @${container.service}`;
}

// Which sign-in the turn-on step must collect, so the owner is never left with
// no way in. The API refuses the same cases. The key field shows whenever the
// owner has no key; this only decides what is required.
export function turnOnNeeds(access) {
  const owner = access?.owner;
  if (!owner || owner.ssh_keys > 0) return "nothing";
  if (access.password_login === false) return "key";
  // An unread sshd setting: the server decides.
  if (access.password_login !== true) return "nothing";
  return owner.has_password ? "nothing" : "keyOrPassword";
}

// Container login off only stops routing SSH into the container (RC-28): with SSH access on and
// a way to sign in, the owner's next login is a shell on the server. Said, never assumed off.
// `enforced` is the System Users list's `meta.ssh_access_enforced`: when the server does not enforce
// SSH groups (false), ssh_access stops nobody, so only a way to sign in counts.
export function hostSshStillOn(access, enforced = null) {
  const owner = access?.owner;
  if (!owner || owner.container_login) return false;
  const canSignIn = owner.ssh_keys > 0 || access.password_login !== false;
  if (enforced === false) return canSignIn;
  return owner.ssh_access && canSignIn;
}
