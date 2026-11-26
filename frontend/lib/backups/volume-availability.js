export function blocksVolumesOnly(hasVolumes) {
  return hasVolumes === false;
}

export function withoutVolumesOnly(type, hasVolumes) {
  return type === "volumes" && blocksVolumesOnly(hasVolumes) ? "volumes_config" : type;
}
