import { redirect } from "next/navigation";
import { outOfRangeHref } from "@/lib/tables/out-of-range-href";

// The API answers an out-of-range page with 200 and an empty array. Skipped when
// `failed`: a failed fetch reports an empty page-1 meta.
export function redirectOutOfRange(pathname, searchParams, meta, failed = false) {
  if (failed) return;

  const lastPage = Math.max(1, Number(meta?.last_page ?? 1));
  const page = Number(searchParams?.page ?? 1);

  // A non-numeric ?page is left alone: the API reads it as page 1.
  if (!Number.isFinite(page) || page <= lastPage) return;

  const href = outOfRangeHref(
    new URLSearchParams(
      Object.entries(searchParams ?? {}).filter(([, v]) => typeof v === "string"),
    ),
    lastPage,
  );

  redirect(href === "?" ? pathname : `${pathname}${href}`);
}
