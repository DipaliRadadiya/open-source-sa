function canView(item) {
  return item?.level === "application" && Boolean(item.permissions?.view || item.permissions?.manage);
}

export function firstAllowedScreen(catalog) {
  const item = (catalog ?? []).find((entry) => canView(entry) && typeof entry.url === "string");
  return item ? item.url : null;
}

export function canOpenApplication(catalog) {
  if (catalog === null || catalog === undefined) return true;
  return catalog.some((entry) => canView(entry) && typeof entry.url === "string");
}
