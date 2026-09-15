/**
 * The site's own title, guessed from the name it is being created under.
 *
 * `site_title` is `required: true` with nothing but a placeholder behind it
 * (WordPressSiteType::fields()), so it is the one box on the simple path that
 * is empty, mandatory, and asks for something the form already knows. Mautic
 * declares the same field and means the same thing by it, so this keys off the
 * field name rather than the site type.
 *
 * The transform is de-sluggifying and nothing else. People name sites the way
 * they name directories — "my-shop", "acme_blog" — and that string is the
 * heading WordPress prints on the page, so the separators have to go. A name
 * already written for humans passes through untouched: the rule only ever
 * splits on the characters a title would not contain in the first place.
 *
 * Capitalisation is first-letter-only per word. Title case proper ("of", "and"
 * staying lowercase) is language-specific and this panel runs in eight
 * languages, so it would be right in one of them and wrong in the rest.
 * Existing capitals are kept rather than normalised — "iPhone Repair" and
 * "ACME" are what the user typed, and they are the cases a case-fold would
 * damage.
 */
/**
 * The field each site type uses for "what is this site called".
 *
 * Seven types ask the question and no two of them agree on the name, because
 * each mirrors whatever its own installer calls it — WordPress and Mautic a
 * site title, Craft/Joomla/Moodle a site name, PrestaShop a shop, Akaunting a
 * company. They are one question wearing five labels, so they get one answer.
 *
 * Two near-misses are excluded on purpose, and the exclusion is the point:
 *
 *   `admin_name`  (Joomla) — a PERSON, not the site. It already defaults to
 *                 "Administrator", and writing the site's name into it would
 *                 put "My Shop" where a human's name goes.
 *   `short_name`  (Moodle) — a separate abbreviation that sits BESIDE the site
 *                 name on the same form. Filling both boxes with the same
 *                 words is not a suggestion, it is noise the user has to undo.
 *
 * Adding a type here is safe only after reading what its installer does with
 * the value. A field is not a title because its name contains "name".
 */
export const TITLE_FIELDS = new Set([
  "site_title", // WordPress, Mautic
  "site_name", // Craft CMS, Joomla, Moodle
  "shop_name", // PrestaShop
  "company_name", // Akaunting
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
