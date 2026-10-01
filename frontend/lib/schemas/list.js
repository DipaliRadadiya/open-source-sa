import { z } from "zod";

// Deliberately NOT optional: a missing `meta` must fail loudly, not pass page one off as the whole list.
export const listMetaSchema = z.object({
  current_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  last_page: z.number(),
});

// The page sizes the API accepts (others are a 422, not a clamp).
export const LIST_PER_PAGE_OPTIONS = [10, 20, 50, 100];

export const EMPTY_LIST_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

// Takes the serialised string so `cache` can dedupe. `filters` maps page URL keys to `filter[…]` keys.
export function listQuery(query = "", { filters = {}, sort = true } = {}) {
  const params = new URLSearchParams(query);
  const perPage = LIST_PER_PAGE_OPTIONS.includes(Number(params.get("per_page")))
    ? Number(params.get("per_page"))
    : 10;

  const out = {
    search: params.get("search")?.trim() || undefined,
    per_page: perPage,
    page: Math.max(1, Number(params.get("page")) || 1),
  };
  if (sort && params.get("sort")) out.sort = params.get("sort");

  for (const [urlKey, apiKey] of Object.entries(filters)) {
    const value = params.get(urlKey);
    if (value) out[`filter[${apiKey}]`] = value;
  }

  return out;
}
