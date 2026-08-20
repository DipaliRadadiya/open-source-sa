"use client";

import { useTranslations } from "next-intl";
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

  /*
   * Nothing to page through. Three applications sat under a Previous / 1 /
   * Next row with both arrows greyed out — a control that can only ever say
   * "1", plus a rows-per-page selector for a list that fits on any setting.
   *
   * The per-page selector goes with it rather than staying behind alone: with
   * everything already on screen there is no answer it could change. Once
   * there is a second page both come back together.
   *
   * Only when we can positively tell. `last_page` missing means an older or
   * unexpected `meta`, and the honest response to not knowing is to leave the
   * controls where they are.
   */
  if (lastPage != null && lastPage <= 1) return null;

  return (
    <div className="flex flex-col-reverse items-center justify-between gap-3 sm:flex-row">
      <PerPageSelect label={t("perPage")} />
      <Pager
        page={page}
        lastPage={lastPage}
        total={total}
        pending={pending}
        // page 1 drops the param entirely (keeps URLs clean, matches search/filter).
        onPageChange={(n) => setQuery({ page: n <= 1 ? undefined : n })}
      />
    </div>
  );
}
