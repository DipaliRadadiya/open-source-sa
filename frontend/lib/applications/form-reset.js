/**
 * What survives a change of site type on the create form, and what does not.
 *
 * Each type declares its own fields, and the form is one react-hook-form
 * instance for all of them. Nothing dropped the previous type's answers, so
 * picking PrestaShop, filling it in and switching to WordPress left
 * `shop_name`, `admin_first_name` and the rest sitting in form state: invisible,
 * because only the new type's fields render, but still holding the form dirty,
 * still there if you switched back, and still carrying whatever error the
 * server had set on them.
 *
 * The split is by NAME, not by value. A field both types declare is the same
 * question asked twice — an admin email is an admin email — so the answer is
 * kept. A field only the old type declares has no meaning under the new one.
 */

/**
 * Fields the previous type asked for that the new one does not.
 *
 * These are dropped whole: value, error and edited state. `common` is the set
 * the form owns rather than the site type (name, domain, system user, the git
 * fields) — those belong to no type and are never touched here.
 */
export function orphanFieldNames(previousFields, nextFields, common = new Set()) {
  const next = new Set(fieldNames(nextFields));
  return fieldNames(previousFields).filter((name) => !next.has(name) && !common.has(name));
}

/**
 * Fields both types ask for.
 *
 * The value stays — retyping an admin email because you changed your mind
 * about the CMS is its own annoyance — but any error on it goes. The only
 * errors these fields can carry come back from the server, generated from the
 * OLD type's rules, so under the new type they are describing a validation
 * that no longer applies.
 */
export function sharedFieldNames(previousFields, nextFields, common = new Set()) {
  const next = new Set(fieldNames(nextFields));
  return fieldNames(previousFields).filter((name) => next.has(name) && !common.has(name));
}

/** Declared names, deduplicated, with anything unnamed dropped. */
function fieldNames(fields) {
  return [
    ...new Set(
      (Array.isArray(fields) ? fields : [])
        .map((field) => field?.name)
        .filter((name) => typeof name === "string" && name !== ""),
    ),
  ];
}
