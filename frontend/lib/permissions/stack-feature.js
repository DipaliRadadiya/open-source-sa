const NEEDS = {
  php: ["php"],
  node: ["php", "node"],
  database: ["php", "node"],
  docker: ["docker"],
  registry: ["docker"],
};

export function unavailableOnServer(feature, servingProfiles) {
  const needs = NEEDS[feature];
  if (!needs || !Array.isArray(servingProfiles)) return false;
  return !needs.some((profile) => servingProfiles.includes(profile));
}
