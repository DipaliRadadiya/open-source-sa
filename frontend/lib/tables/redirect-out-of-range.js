import { redirect } from "next/navigation";
import { outOfRangeHref } from "@/lib/tables/out-of-range-href";

/**
 * Move off a page that no longer exists, before anything renders.
 *
 * The API answers `?page=99` with 200 and an empty array, so a list falls
 * through to its "nothing here yet" state — and the pager that would take you
 * back only renders when there are rows. Deleting the last row on the last
 * page lands here too, and there the reader did not ask to be anywhere
 * unusual: they deleted a thing and the page they were on stopped existing.
 *
 * Done on the server rather than in a client effect. The effect version worked,
 * but it had to paint something first, and what it painted was a panel headed
 * "That page does not exist" — an error report for a situation the panel was
 * about to resolve by itself. Redirecting during the render means the browser
 * is never sent the page at all.
 *
 * `page` is the only param that moves; the filters are what the reader chose.
 *
 * `failed` suppresses it: a fetcher that could not reach the API answers with
 * an empty page-1 meta, and bouncing someone to page 1 to read the same load
 * error is a worse answer than leaving them where they were.
 */
export function redirectOutOfRange(pathname, searchParams, meta, failed = false) {
  if (failed) return;

  const lastPage = Math.max(1, Number(meta?.last_page ?? 1));
  const page = Number(searchParams?.page ?? 1);

  // A non-numeric ?page is left alone — the API reads it as page 1, and
  // redirecting on it would fight a URL nobody is actually on.
  if (!Number.isFinite(page) || page <= lastPage) return;

  const href = outOfRangeHref(
    new URLSearchParams(
      Object.entries(searchParams ?? {}).filter(([, v]) => typeof v === "string"),
    ),
    lastPage,
  );

  redirect(href === "?" ? pathname : `${pathname}${href}`);
}
