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
