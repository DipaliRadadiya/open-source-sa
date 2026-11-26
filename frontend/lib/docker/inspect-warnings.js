const SAID_AT_FIELD = new Set(["required_env", "no_port", "several_ports"]);

export function beforeDeployWarnings(detected) {
  const warnings = [...(detected?.warnings ?? [])];
  const codes = detected?.warning_codes ?? [];
  if (codes.length && codes.length === warnings.length) {
    return warnings.filter((_, index) => !SAID_AT_FIELD.has(codes[index]));
  }
  const hasRequiredEnv = (detected?.env ?? []).some((item) => item?.required);
  const portNote =
    detected?.port_confidence === "none" || (detected?.exposed_ports ?? []).length > 1;
  if (portNote && warnings.length) warnings.pop();
  if (hasRequiredEnv && warnings.length) warnings.shift();
  return warnings;
}
