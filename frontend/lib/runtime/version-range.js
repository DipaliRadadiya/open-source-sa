export function versionsInRange(versions, range) {
  const list = Array.isArray(versions) ? versions : [];
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min === null && max === null) return list;

  return list.filter((item) => versionWithin(item?.version, range));
}

export function rangeUnsatisfied(versions, range) {
  const list = Array.isArray(versions) ? versions : [];
  if (list.length === 0) return false;
  if ((range?.min ?? null) === null && (range?.max ?? null) === null) return false;
  return versionsInRange(list, range).length === 0;
}

export function rangeLabel(range, { upTo } = {}) {
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min !== null && max !== null) return `${min} – ${max}`;
  if (min !== null) return `${min}+`;
  if (max !== null) return upTo ? `${upTo} ${max}` : `≤ ${max}`;
  return "";
}

export function versionWithin(version, range) {
  if (typeof version !== "string" || version === "") return false;
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min !== null && compareVersions(version, min) < 0) return false;
  if (max !== null && compareVersions(toPrecisionOf(version, max), max) > 0) return false;
  return true;
}

function toPrecisionOf(version, bound) {
  const segments = String(bound).split(".").length;
  return String(version).split(".").slice(0, segments).join(".");
}

export function highestInRange(versions, range) {
  return sortedInRange(versions, range).at(-1) ?? null;
}

export function installTarget(versions, range) {
  const candidates = versionsInRange(Array.isArray(versions) ? versions : [], range)
    .filter((item) => typeof item?.version === "string" && item.version !== "")
    .sort((a, b) => compareVersions(a.version, b.version));
  if (candidates.length === 0) return null;

  const supported = candidates.filter((item) => item?.lifecycle?.status !== "eol");
  const chosen = supported[0] ?? candidates[candidates.length - 1];

  return { version: chosen.version, eol: chosen?.lifecycle?.status === "eol" };
}

export function lowestInRange(versions, range) {
  return sortedInRange(versions, range)[0] ?? null;
}

function sortedInRange(versions, range) {
  return versionsInRange(versions, range)
    .map((item) => item?.version)
    .filter((version) => typeof version === "string" && version !== "")
    .sort(compareVersions);
}

export function compareVersions(a, b) {
  const left = String(a).split(".");
  const right = String(b).split(".");

  for (let i = 0; i < Math.max(left.length, right.length); i += 1) {
    const x = Number.parseInt(left[i] ?? "0", 10) || 0;
    const y = Number.parseInt(right[i] ?? "0", 10) || 0;
    if (x !== y) return x < y ? -1 : 1;
  }
  return 0;
}
