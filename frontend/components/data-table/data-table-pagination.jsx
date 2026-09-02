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

  /*
   * Nothing to page through: three applications under a Previous / 1 / Next
   * row with both arrows greyed out is a control that can only ever say "1".
   *
   * Only when we can positively tell. `last_page` missing means an older or
   * unexpected `meta`, and the honest response to not knowing is to leave the
   * control where it is.
   */
  const showPager = lastPage == null || lastPage > 1;

  /*
   * The rows-per-page selector used to be hidden alongside the pager, on the
   * reasoning that with everything already on screen there is no answer it
   * could change. That reasoning ignored how the state was reached: choose 20
   * on a list of 15 and the list stops paginating, so the selector vanishes —
   * taking with it the only way back to 10. A door that locks behind you.
   *
   * So it is the list, not the current page count, that decides. More rows
   * than the smallest option means some setting does paginate, and the choice
   * is real whichever one is selected now. Fewer, and no option changes
   * anything, so both controls go.
   */
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
