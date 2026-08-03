"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

/**
 * Build a windowed page list with first/last anchors and ellipses, e.g.
 * 1 … 4 5 6 … 10. Small ranges (≤ 7) render every page.
 */
export function pageList(current, last) {
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
 * The page numbers themselves, with no opinion on where the page number lives.
 *
 * Server-driven tables keep it in the URL; a list that is already fully loaded
 * in the browser (PHP extensions) keeps it in component state. Both should look
 * and behave identically, so the buttons live here and the caller owns the state.
 */
export function Pager({ page, lastPage, total, onPageChange, pending = false }) {
  const t = useTranslations("pagination");
  const pages = pageList(page, lastPage);

  return (
    <div className="flex items-center gap-3">
      <span className="hidden text-sm text-muted-foreground sm:inline">{t("total", { total })}</span>
      <nav className="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon"
          className="size-9"
          aria-label={t("prev")}
          disabled={page <= 1 || pending}
          onClick={() => onPageChange(page - 1)}
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
              onClick={() => onPageChange(p)}
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
          onClick={() => onPageChange(page + 1)}
        >
          <ChevronRight className="size-4" />
        </Button>
      </nav>
    </div>
  );
}
