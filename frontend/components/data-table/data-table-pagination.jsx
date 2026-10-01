"use client";

import { useTranslations } from "next-intl";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";
import { PerPageSelect } from "@/components/data-table/per-page-select";
import { Pager } from "@/components/data-table/pager";
import { useSetQuery } from "@/hooks/use-set-query";
import { useNavPending } from "@/components/data-table/nav-transition";

/**
 * Numbered pagination driven by `meta` from the server (current_page, last_page,
 * total), with a per-page selector. The page number lives in the URL so a list
 * you were reading survives a reload.
 */
export function DataTablePagination({ meta }) {
  const t = useTranslations("pagination");
  const setQuery = useSetQuery();
  const pending = useNavPending();

  const { current_page: page, last_page: lastPage, total } = meta;

  // Hide the pager only when a single page is certain (`last_page` known).
  const showPager = lastPage == null || lastPage > 1;

  // Decided by total, not page count: otherwise picking a larger size would
  // hide the selector and remove the way back.
  const showPerPage = total == null || total > PER_PAGE_OPTIONS[0];

  if (!showPager && !showPerPage) return null;

  return (
    <div className="flex flex-col-reverse items-center justify-between gap-3 sm:flex-row">
      {showPerPage ? <PerPageSelect label={t("perPage")} /> : null}
      {showPager ? (
        <Pager
          page={page}
          lastPage={lastPage}
          total={total}
          pending={pending}
          // page 1 drops the param entirely (keeps URLs clean, matches search/filter).
          onPageChange={(n) => setQuery({ page: n <= 1 ? undefined : n })}
        />
      ) : null}
    </div>
  );
}
