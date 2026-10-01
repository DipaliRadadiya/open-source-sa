/**
 * What survives a change of site type on the create form. One react-hook-form
 * instance serves every type, so the previous type's fields must be cleared
 * explicitly. Split by field name: shared names keep their value.
 */

/**
 * Fields the previous type asked for that the new one does not; dropped whole
 * (value, error, dirty state). `common` fields belong to the form and are skipped.
 */
export function orphanFieldNames(previousFields, nextFields, common = new Set()) {
  const next = new Set(fieldNames(nextFields));
  return fieldNames(previousFields).filter((name) => !next.has(name) && !common.has(name));
}

/**
 * Fields both types ask for. The value stays but errors are cleared: they came
 * from the server under the old type's rules.
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
