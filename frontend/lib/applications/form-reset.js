// One form serves every site type, so the previous type's fields must be cleared.

export function orphanFieldNames(previousFields, nextFields, common = new Set()) {
  const next = new Set(fieldNames(nextFields));
  return fieldNames(previousFields).filter((name) => !next.has(name) && !common.has(name));
}

export function sharedFieldNames(previousFields, nextFields, common = new Set()) {
  const next = new Set(fieldNames(nextFields));
  return fieldNames(previousFields).filter((name) => next.has(name) && !common.has(name));
}

function fieldNames(fields) {
  return [
    ...new Set(
      (Array.isArray(fields) ? fields : [])
        .map((field) => field?.name)
        .filter((name) => typeof name === "string" && name !== ""),
    ),
  ];
}
