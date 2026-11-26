"use client";

import { useTranslations } from "next-intl";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";
import { PerPageSelect } from "@/components/data-table/per-page-select";
import { Pager } from "@/components/data-table/pager";
import { useSetQuery } from "@/hooks/use-set-query";
import { useNavPending } from "@/components/data-table/nav-transition";

export function DataTablePagination({ meta }) {
  const t = useTranslations("pagination");
  const setQuery = useSetQuery();
  const pending = useNavPending();

  const { current_page: page, last_page: lastPage, total } = meta;

  const showPager = lastPage == null || lastPage > 1;

  // Decided by total, otherwise picking a bigger size hides the selector.
  const showPerPage = total == null || total > PER_PAGE_OPTIONS[0];

  if (!showPager && !showPerPage) return null;

  return (
    <div className="flex flex-col-reverse items-center justify-between gap-3 sm:flex-row">
      {showPerPage ? <PerPageSelect label={t("perPage")} fallback={meta.per_page} /> : null}
      {showPager ? (
        <Pager
          page={page}
          lastPage={lastPage}
          total={total}
          pending={pending}
          onPageChange={(n) => setQuery({ page: n <= 1 ? undefined : n })}
        />
      ) : null}
    </div>
  );
}
