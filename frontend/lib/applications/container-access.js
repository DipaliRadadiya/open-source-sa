// Literal, never translated: it is pasted into a terminal.
export const PLACEHOLDER_USER = "your-user";

// Recipes and Container-tab apps; the endpoint refuses every other site.
export function showsContainerAccess(application) {
  return application?.serving_profile === "docker";
}

// Ready means the step-1 text: SSH access, a password for sudo to ask for, and sudo.
export function readySshUsers(users) {
  return (users ?? []).filter(
    (user) => user.ssh_access && user.sudo && user.has_password !== false,
  );
}

export function defaultContainer(containers) {
  return (containers ?? []).find((container) => container.main) ?? containers?.[0] ?? null;
}

export function buildAccessCommand({ user, host, port, container }) {
  const portFlag = port && Number(port) !== 22 ? `-p ${port} ` : "";
  return `ssh -t ${portFlag}${user || PLACEHOLDER_USER}@${host} 'sudo docker exec -it ${container} sh'`;
}
