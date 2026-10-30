// The shapes the backend accepts for a container's limits; the server still checks the core count.
export const DOCKER_MEMORY_PATTERN = /^\d+(b|k|m|g)?$/i;
export const DOCKER_CPU_PATTERN = /^\d+(\.\d{1,2})?$/;

const RULES = {
  memory_limit: { pattern: DOCKER_MEMORY_PATTERN, message: "dockerMemory", scope: "validation" },
  cpu_limit: { pattern: DOCKER_CPU_PATTERN, message: "dockerCpu", scope: "validation" },
};

/** The rule for a container limit field, or null for any other field. `scope` is its message namespace. */
export function dockerLimitRule(name) {
  return RULES[name] ?? null;
}

/** Whether a filled-in limit breaks its rule; empty is a real answer (default or no limit). */
export function breaksDockerLimit(name, value) {
  const rule = dockerLimitRule(name);
  const text = String(value ?? "").trim();
  return Boolean(rule) && text !== "" && !rule.pattern.test(text);
}
