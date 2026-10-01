import { ACCESS_NONE, accessFromGrant } from "@/lib/schemas/role";

// Counts on `access`, not the boolean pair: the API sends the level and may
// omit the pair.
export function grantedCount(role) {
  return (role.permissions ?? []).filter(
    (entry) =>
      (entry.access ?? accessFromGrant(entry.permissions?.view, entry.permissions?.manage)) !==
      ACCESS_NONE,
  ).length;
}
