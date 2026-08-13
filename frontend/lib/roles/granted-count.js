import { ACCESS_NONE, accessFromGrant } from "@/lib/schemas/role";

// Counts on `access`, not on the boolean pair: the API now sends the level and
// may omit the pair entirely, which would have made every role read as zero.
export function grantedCount(role) {
  return (role.permissions ?? []).filter(
    (entry) =>
      (entry.access ?? accessFromGrant(entry.permissions?.view, entry.permissions?.manage)) !==
      ACCESS_NONE,
  ).length;
}
