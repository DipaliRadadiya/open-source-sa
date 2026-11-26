export const TITLE_FIELDS = new Set([
  "site_title",
  "site_name",
  "shop_name",
  "company_name",
]);

export function siteTitleFrom(name) {
  const value = String(name ?? "").trim();
  if (!value) return "";

  return value
    .replace(/[-_.]+/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .split(" ")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}
