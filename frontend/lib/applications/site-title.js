/**
 * The field each site type uses for the site's title, prefilled from the
 * application name. Excluded on purpose: `admin_name` (Joomla, a person) and
 * `short_name` (Moodle, a separate abbreviation). Check what an installer does
 * with a field before adding it.
 */
export const TITLE_FIELDS = new Set([
  "site_title", // WordPress, Mautic
  "site_name", // Craft CMS, Joomla, Moodle
  "shop_name", // PrestaShop
  "company_name", // Akaunting
]);

/**
 * A title from a slug-like name ("my-shop" → "My Shop"). Only the first letter
 * of each word is raised (title-case rules are language-specific); existing
 * capitals are kept.
 */
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
