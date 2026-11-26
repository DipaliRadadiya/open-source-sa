export const CATEGORY_GROUPS = [
  { key: "ai", categories: ["ai"] },
  { key: "cms", categories: ["cms", "ecommerce"] },
  { key: "development", categories: ["developer", "development"] },
  { key: "tools", categories: ["utility", "automation", "monitoring"] },
  {
    key: "productivity",
    categories: ["productivity", "business", "education", "marketing", "community"],
  },
];

export function groupForType(type) {
  const category = String(type?.category ?? "").toLowerCase();
  const group = CATEGORY_GROUPS.find((g) => g.categories.includes(category));
  return group?.key ?? "others";
}

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
