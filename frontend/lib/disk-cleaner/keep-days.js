// The keep choice (API 2026-10-26): 0 = delete all, N = keep N days, null = each
// category's old behaviour. Sizes come from the API per choice; nothing is estimated here.

export const DEFAULT_KEEP = { choices: [0, 1, 3, 7, 14, 30], recommended: 7 };

export function keepOptions(keepDays) {
  const choices = keepDays?.choices?.length ? keepDays.choices : DEFAULT_KEEP.choices;
  const recommended = keepDays?.recommended ?? DEFAULT_KEEP.recommended;
  return { choices, recommended: choices.includes(recommended) ? recommended : choices.at(-1) };
}

/** What this category frees under `keep`: `{bytes, human}`. `null` keep is the old size. */
export function sizeFor(category, keep) {
  if (keep != null && category?.keeps_days !== false) {
    const entry = category?.reclaimable_by_keep_days?.find((row) => row.keep_days === keep);
    if (entry) return { bytes: entry.reclaimable ?? 0, human: entry.reclaimable_human ?? null };
  }
  // "Always removes all" categories, an API without the per-choice sizes, or no choice.
  if (keep === 0 || category?.keeps_days === false || keep == null || !category?.reclaimable_by_keep_days?.length) {
    return { bytes: category?.reclaimable ?? 0, human: category?.reclaimable_human ?? null };
  }
  return { bytes: 0, human: null };
}

/** The "Can be freed" total under `keep`: available categories with something to free, `{bytes, count}`. */
export function reclaimableFor(categories, keep) {
  const sizes = (categories ?? []).filter((c) => c.available).map((c) => sizeFor(c, keep).bytes).filter((bytes) => bytes > 0);
  return { bytes: sizes.reduce((total, bytes) => total + bytes, 0), count: sizes.length };
}
