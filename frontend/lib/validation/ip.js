/**
 * Address checks for anything the user types into a firewall config.
 *
 * These lists are written straight into fail2ban's config file, so a typo does
 * not fail loudly — it becomes a line that silently protects nobody. Catching
 * it in the field is the only place the user still has the context to fix it.
 *
 * Deliberately permissive on IPv6 (shape, not full RFC grammar): rejecting a
 * valid address someone needs is worse than passing an odd one to the server,
 * which validates too.
 */

function isIpv4(value) {
  const parts = value.split(".");
  if (parts.length !== 4) return false;
  return parts.every((part) => /^\d{1,3}$/.test(part) && Number(part) <= 255);
}

function isIpv6(value) {
  return /^[0-9a-f:]+$/i.test(value) && value.includes(":") && !value.includes(":::");
}

/** A single address, no range — what the ban endpoint accepts. */
export function isIpAddress(value) {
  const ip = value.trim();
  return isIpv4(ip) || isIpv6(ip);
}

/** An address or a CIDR range — what the never-ban list accepts. */
export function isIpOrCidr(value) {
  const [ip, mask, ...rest] = value.trim().split("/");
  if (rest.length) return false;
  if (mask !== undefined) {
    if (!/^\d{1,3}$/.test(mask)) return false;
    const limit = isIpv4(ip) ? 32 : 128;
    if (Number(mask) > limit) return false;
  }
  return isIpv4(ip) || isIpv6(ip);
}
