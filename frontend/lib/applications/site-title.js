// Excluded on purpose: `admin_name` (Joomla, a person) and `short_name` (Moodle, an abbreviation).
// Check what an installer does with a field before adding it.
export const TITLE_FIELDS = new Set([
  "site_title", // WordPress, Mautic
  "site_name", // Craft CMS, Joomla, Moodle
  "shop_name", // PrestaShop
  "company_name", // Akaunting
]);

// Only each word's first letter is raised (title-case rules are language-specific).
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
