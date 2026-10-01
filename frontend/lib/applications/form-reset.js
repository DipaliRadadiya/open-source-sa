// One react-hook-form instance serves every site type, so the previous type's fields
// must be cleared explicitly; shared names keep their value.

/** Dropped whole (value, error, dirty state); `common` fields belong to the form. */
export function orphanFieldNames(previousFields, nextFields, common = new Set()) {
  const next = new Set(fieldNames(nextFields));
  return fieldNames(previousFields).filter((name) => !next.has(name) && !common.has(name));
}

/** The value stays but errors are cleared: they came from the old type's rules. */
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
