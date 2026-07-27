"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PerPageSelect } from "@/components/data-table/per-page-select";
import { useSetQuery } from "@/hooks/use-set-query";
import { useNavPending } from "@/components/data-table/nav-transition";

/**
 * Build a windowed page list with first/last anchors and ellipses, e.g.
 * 1 … 4 5 6 … 10. Small ranges (≤ 7) render every page.
 */
function pageList(current, last) {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1);
  }
  const pages = [1];
  const left = Math.max(2, current - 1);
  const right = Math.min(last - 1, current + 1);
  if (left > 2) pages.push("gap-left");
  for (let i = left; i <= right; i++) pages.push(i);
  if (right < last - 1) pages.push("gap-right");
  pages.push(last);
  return pages;
}

/**
 * Numbered pagination (1 2 3 … 10) with prev/next arrows and a per-page
 * selector, driven by `meta` from the server (current_page, last_page, total).
 * Reusable across list pages.
 */
export function DataTablePagination({ meta }) {
  const t = useTranslations("pagination");
  const setQuery = useSetQuery();
  const pending = useNavPending();

  const { current_page: page, last_page: lastPage, total } = meta;
  // page 1 drops the param entirely (keeps URLs clean, matches search/filter).
  const goTo = (n) => setQuery({ page: n <= 1 ? undefined : n });

  const pages = pageList(page, lastPage);

  return (
    <div className="flex flex-col-reverse items-center justify-between gap-3 sm:flex-row">
      <PerPageSelect label={t("perPage")} />

      <div className="flex items-center gap-3">
        <span className="hidden text-sm text-muted-foreground sm:inline">
          {t("total", { total })}
        </span>
        <nav className="flex items-center gap-1">
          <Button
            variant="outline"
            size="icon"
            className="size-9"
            aria-label={t("prev")}
            disabled={page <= 1 || pending}
            onClick={() => goTo(page - 1)}
          >
            <ChevronLeft className="size-4" />
          </Button>

          {pages.map((p) =>
            typeof p === "string" ? (
              <span
                key={p}
                className="flex size-9 items-center justify-center text-sm text-muted-foreground"
                aria-hidden
              >
                …
              </span>
            ) : (
              <Button
                key={p}
                variant={p === page ? "default" : "outline"}
                size="icon"
                className={cn("size-9", p === page && "pointer-events-none")}
                aria-label={t("pageAria", { page: p })}
                aria-current={p === page ? "page" : undefined}
                disabled={pending && p !== page}
                onClick={() => goTo(p)}
              >
                {p}
              </Button>
            ),
          )}

          <Button
            variant="outline"
            size="icon"
            className="size-9"
            aria-label={t("next")}
            disabled={page >= lastPage || pending}
            onClick={() => goTo(page + 1)}
          >
            <ChevronRight className="size-4" />
          </Button>
        </nav>
      </div>
    </div>
  );
}
