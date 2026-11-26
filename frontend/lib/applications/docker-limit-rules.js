export const DOCKER_MEMORY_PATTERN = /^\d+(b|k|m|g)?$/i;
export const DOCKER_CPU_PATTERN = /^\d+(\.\d{1,2})?$/;

const RULES = {
  memory_limit: { pattern: DOCKER_MEMORY_PATTERN, message: "dockerMemory", scope: "validation" },
  cpu_limit: { pattern: DOCKER_CPU_PATTERN, message: "dockerCpu", scope: "validation" },
};

export function dockerLimitRule(name) {
  return RULES[name] ?? null;
}

export function breaksDockerLimit(name, value) {
  const rule = dockerLimitRule(name);
  const text = String(value ?? "").trim();
  return Boolean(rule) && text !== "" && !rule.pattern.test(text);
}
