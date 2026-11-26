import { redirect } from "next/navigation";
import { outOfRangeHref } from "@/lib/tables/out-of-range-href";

export function redirectOutOfRange(pathname, searchParams, meta, failed = false) {
  if (failed) return;

  const lastPage = Math.max(1, Number(meta?.last_page ?? 1));
  const page = Number(searchParams?.page ?? 1);

  if (!Number.isFinite(page) || page <= lastPage) return;

  const href = outOfRangeHref(
    new URLSearchParams(
      Object.entries(searchParams ?? {}).filter(([, v]) => typeof v === "string"),
    ),
    lastPage,
  );

  redirect(href === "?" ? pathname : `${pathname}${href}`);
}
