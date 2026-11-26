import { z } from "zod";

// Required: a missing meta must fail instead of treating page one as the whole list.
export const listMetaSchema = z.object({
  current_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  last_page: z.number(),
});

export const LIST_PER_PAGE_OPTIONS = [10, 20, 50, 100];

export const PER_PAGE_COOKIE = "list_per_page";

export const EMPTY_LIST_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

export function listQuery(query = "", { filters = {}, sort = true, defaultPerPage = 10 } = {}) {
  const params = new URLSearchParams(query);
  const perPage = LIST_PER_PAGE_OPTIONS.includes(Number(params.get("per_page")))
    ? Number(params.get("per_page"))
    : defaultPerPage;

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
