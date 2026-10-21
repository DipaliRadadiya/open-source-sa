// Server screens that exist only on some stacks. The API hides their permission
// from everyone, administrators included, when the server does not run them
// (VisiblePermissions / ServerCapabilities): PHP needs PHP sites; Node.js and
// databases need a PHP or Node site; Docker and registries need Docker.
const NEEDS = {
  php: ["php"],
  node: ["php", "node"],
  database: ["php", "node"],
  docker: ["docker"],
  registry: ["docker"],
};

/** True only when the server is known not to run what `feature` needs. */
export function unavailableOnServer(feature, servingProfiles) {
  const needs = NEEDS[feature];
  if (!needs || !Array.isArray(servingProfiles)) return false;
  return !needs.some((profile) => servingProfiles.includes(profile));
}
