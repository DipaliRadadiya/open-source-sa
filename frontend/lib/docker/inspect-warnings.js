/**
 * The image warnings worth a "Before you deploy" box: the ones not already said
 * at their own field. Two are: required settings (the env rows ask for a value)
 * and the port note (the Port field says it). The API sends sentences, not codes,
 * in a fixed order (ImageInspector): required settings first, the port note last.
 * Asked for codes in RC-32; until then the position is the key.
 */
export function beforeDeployWarnings(detected) {
  const warnings = [...(detected?.warnings ?? [])];
  const hasRequiredEnv = (detected?.env ?? []).some((item) => item?.required);
  const portNote =
    detected?.port_confidence === "none" || (detected?.exposed_ports ?? []).length > 1;
  if (portNote && warnings.length) warnings.pop();
  if (hasRequiredEnv && warnings.length) warnings.shift();
  return warnings;
}
