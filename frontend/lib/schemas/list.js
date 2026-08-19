import { z } from "zod";

/**
 * The paging envelope every list endpoint now returns.
 *
 * Five lists grew this at once when the backend stopped returning whole tables,
 * and the frontend read every one of them as an unpaged array — so each showed
 * its first ten rows and searched only those. Kept in one place so the next
 * list to be paginated is three lines rather than a rediscovery.
 *
 * Deliberately NOT optional. A tolerant schema would have let exactly that
 * silence continue; a missing `meta` should fail loudly as a shape error, which
 * `read()` reports to the service journal by name.
 */
export const listMetaSchema = z.object({
  current_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  last_page: z.number(),
});

// The page sizes the API accepts. Anything else is a 422 rather than a clamp,
// so a stale URL is corrected here rather than sent.
export const LIST_PER_PAGE_OPTIONS = [10, 20, 50, 100];

export const EMPTY_LIST_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

/**
 * Turn a page's own query string into the API's query shape.
 *
 * Takes the serialised string rather than the searchParams object so callers
 * can pass it through React's `cache` — an object literal is a fresh identity
 * every call and defeats the dedupe entirely.
 *
 * `filters` maps our URL key to the API's `filter[…]` key, because the two
 * differ on purpose: `?status=failed` reads better in a shared link than
 * `?filter%5Bstatus%5D=failed`.
 */
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
