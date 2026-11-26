export const DEFAULT_KEEP = { choices: [0, 1, 3, 7, 14, 30], recommended: 7 };

export function hasKeepChoice(keepDays) {
  return Array.isArray(keepDays?.choices) && keepDays.choices.length > 0;
}

export function keepOptions(keepDays) {
  const choices = keepDays?.choices?.length ? keepDays.choices : DEFAULT_KEEP.choices;
  const recommended = keepDays?.recommended ?? DEFAULT_KEEP.recommended;
  return { choices, recommended: choices.includes(recommended) ? recommended : choices.at(-1) };
}

export function sizeFor(category, keep) {
  if (keep != null && category?.keeps_days !== false) {
    const entry = category?.reclaimable_by_keep_days?.find((row) => row.keep_days === keep);
    if (entry) return { bytes: entry.reclaimable ?? 0, human: entry.reclaimable_human ?? null };
  }
  if (keep === 0 || category?.keeps_days === false || keep == null || !category?.reclaimable_by_keep_days?.length) {
    return { bytes: category?.reclaimable ?? 0, human: category?.reclaimable_human ?? null };
  }
  return { bytes: 0, human: null };
}

export function reclaimableFor(categories, keep) {
  const sizes = (categories ?? []).filter((c) => c.available).map((c) => sizeFor(c, keep).bytes).filter((bytes) => bytes > 0);
  return { bytes: sizes.reduce((total, bytes) => total + bytes, 0), count: sizes.length };
}
