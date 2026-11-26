function isIpv4(value) {
  const parts = value.split(".");
  if (parts.length !== 4) return false;
  return parts.every((part) => /^\d{1,3}$/.test(part) && Number(part) <= 255);
}

function isIpv6(value) {
  return /^[0-9a-f:]+$/i.test(value) && value.includes(":") && !value.includes(":::");
}

export function isIpAddress(value) {
  const ip = value.trim();
  return isIpv4(ip) || isIpv6(ip);
}

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
