// Said at their own field already: the env rows ask for the required settings, the Port field
// explains a missing or ambiguous port.
const SAID_AT_FIELD = new Set(["required_env", "no_port", "several_ports"]);

/**
 * The image warnings worth a "Before you deploy" box: the ones not already said at their
 * own field. By `warning_codes` (RC-32) when they line up with `warnings`; an older API
 * sends sentences only, in a fixed order (required settings first, the port note last).
 */
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
