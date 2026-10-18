// S-02: a "Volumes" backup of a container site with none (IT-Tools) fails every
// run, so the form blocks it. `hasVolumes` is the `has_volumes` of
// GET /applications/{id}/backup-target: true, false, or null — unknown, or not a
// container site. Null stays silent, as in `hasNoDatabase`: the save still
// refuses, so a wrong "no volumes" would only argue someone out of a valid choice.
export function blocksVolumesOnly(hasVolumes) {
  return hasVolumes === false;
}

// What a saved "Volumes" target moves to when that is blocked: the same family,
// still keeping the compose file — the container backup that works on such a site.
export function withoutVolumesOnly(type, hasVolumes) {
  return type === "volumes" && blocksVolumesOnly(hasVolumes) ? "volumes_config" : type;
}
