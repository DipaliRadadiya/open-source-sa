export function sizeFromValues(values) {
  if (!values || !Number.isFinite(values.required_mb)) return null;
  if (Number.isFinite(values.free_mb)) {
    return { haveMb: values.free_mb, kind: "free", swapMb: null, needMb: values.required_mb };
  }
  if (Number.isFinite(values.available_mb)) {
    const swapMb = Number.isFinite(values.swap_mb) ? values.swap_mb : null;
    return { haveMb: values.available_mb + (swapMb ?? 0), kind: "available", swapMb, needMb: values.required_mb };
  }
  return null;
}

export function isUnknownDetail(detail) {
  return String(detail ?? "").trim() === "unknown";
}

export function megabytes(mb) {
  if (!Number.isFinite(mb) || mb < 0) return null;
  if (mb < 1024) return { value: mb, unit: "MB", maximumFractionDigits: 0 };
  const gb = mb / 1024;
  return { value: gb, unit: "GB", maximumFractionDigits: gb >= 10 ? 0 : 1 };
}
