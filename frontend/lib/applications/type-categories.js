/**
 * Display groups for the application-type grid. The API's `category` is too
 * fine to filter by, so categories are folded into a few buckets; unknown ones
 * land in `others`. The grouping is deliberately a frontend decision.
 */
export const CATEGORY_GROUPS = [
  { key: "cms", categories: ["cms", "ecommerce"] },
  { key: "development", categories: ["developer"] },
  { key: "tools", categories: ["utility", "automation", "monitoring"] },
  {
    key: "productivity",
    categories: ["productivity", "business", "education", "marketing", "community"],
  },
];

/** The group a type belongs to; `others` for an unmapped category. */
export function groupForType(type) {
  const category = String(type?.category ?? "").toLowerCase();
  const group = CATEGORY_GROUPS.find((g) => g.categories.includes(category));
  return group?.key ?? "others";
}

/**
 * The non-empty groups for a catalogue, in order, with counts. Empty groups
 * (including `others`) are dropped rather than shown disabled.
 */
export function groupsWithTypes(types = []) {
  const counts = new Map();
  for (const type of types) {
    const key = groupForType(type);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }

  const groups = CATEGORY_GROUPS.filter((g) => counts.has(g.key)).map((g) => ({
    key: g.key,
    count: counts.get(g.key),
  }));

  if (counts.has("others")) groups.push({ key: "others", count: counts.get("others") });
  return groups;
}
