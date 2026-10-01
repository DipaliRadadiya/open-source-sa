/**
 * Display groups for the application-type grid.
 *
 * The API sends a `category` per type, and it is too fine to filter by: 11
 * categories across 17 types, 8 of them holding exactly ONE — a row of eleven
 * chips where clicking "education" finds Moodle alone. So the raw categories
 * are folded into a handful of buckets a person would actually reach for, and
 * anything the API adds tomorrow lands in `others` rather than vanishing.
 *
 * The grouping is a FRONTEND decision, deliberately: it is about how many
 * chips fit on a row and which words a beginner recognises, neither of which
 * the backend can know. If the catalogue grows enough that a bucket is worth
 * splitting, this file is the only place that changes.
 */
export const CATEGORY_GROUPS = [
  /*
   * Databases, which are NOT applications and are in this grid anyway.
   *
   * A database has no domain and no web address, so it cannot be a site type —
   * `domain` is required for every application and provisioning always writes a
   * vhost. But "create a database" is something people come to the create page to
   * do, and they looked for MySQL in this grid three times before it was here.
   * Where the control lives is not a consequence of how the model is shaped.
   *
   * First in the row rather than last: the cards are the only ones here that do
   * not make a website, so burying them under "others" would repeat the original
   * problem in a smaller font.
   */
  { key: "database", categories: ["database"] },
  { key: "cms", categories: ["cms", "ecommerce"] },
  { key: "development", categories: ["developer"] },
  { key: "tools", categories: ["utility", "automation", "monitoring"] },
  {
    key: "productivity",
    categories: [
      "productivity",
      "business",
      "education",
      "marketing",
      "community",
    ],
  },
];

/** The group a type belongs to — `others` for a category we have not placed. */
export function groupForType(type) {
  const category = String(type?.category ?? "").toLowerCase();
  const group = CATEGORY_GROUPS.find((g) => g.categories.includes(category));
  return group?.key ?? "others";
}

/**
 * The groups worth showing for a given catalogue, in order, with counts.
 *
 * Empty groups are dropped rather than rendered disabled: a chip that filters
 * to nothing is a promise the panel cannot keep, and on a small install it
 * would be most of the row. `others` only appears when something actually
 * landed in it.
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

  if (counts.has("others"))
    groups.push({ key: "others", count: counts.get("others") });
  return groups;
}
