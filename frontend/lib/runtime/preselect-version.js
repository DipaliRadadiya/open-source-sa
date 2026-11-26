export function preselectVersion(versions = [], preferred = null) {
  const list = Array.isArray(versions) ? versions : [];
  const has = (v) => Boolean(v) && list.some((item) => item?.version === v);

  if (has(preferred)) return preferred;

  const marked = list.find((item) => item?.is_default)?.version;
  if (marked) return marked;

  return list[0]?.version;
}

export function preselectOption(options = []) {
  const list = Array.isArray(options) ? options : [];
  return list.find((option) => option?.is_default)?.value ?? list[0]?.value;
}
