import { ACCESS_MANAGE, ACCESS_NONE, accessFromGrant } from "../schemas/role.js";

export function countAccess(levels = []) {
  let view = 0;
  let manage = 0;
  for (const level of levels) {
    if (level === ACCESS_NONE || !level) continue;
    view += 1;
    if (level === ACCESS_MANAGE) manage += 1;
  }
  return { view, manage };
}

export function accessCounts(role) {
  return countAccess(
    (role.permissions ?? []).map(
      (entry) => entry.access ?? accessFromGrant(entry.permissions?.view, entry.permissions?.manage),
    ),
  );
}
