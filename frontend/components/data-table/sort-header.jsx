"use client";

import { useSearchParams } from "next/navigation";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { useTranslations } from "next-intl";
import { useSetQuery } from "@/hooks/use-set-query";
import { cn } from "@/lib/utils";

/**
 * A column header that sorts through the API rather than in the table.
 *
 * DataTable's own `sortable` prop reorders the rows it holds, which is right
 * for a list that arrives whole and wrong for a paged one: it sorts the ten
 * rows on screen and presents that as the order of the whole list. This puts
 * the column in the URL instead, so the server answers and paging still means
 * something.
 *
 * `col` is the API's own sort key — the values in each list request's `SORTS`
 * whitelist. Anything else is a 422 rather than an ignored parameter, which is
 * deliberate on their side and worth keeping: a silently dropped sort looks
 * exactly like a working one.
 *
 * Three states, because two would strand the reader: unsorted → ascending →
 * descending → back to the API's own default. `descFirst` flips the first
 * click for columns where the interesting end is the top — nobody opens a size
 * column to find their smallest database.
 */
export function SortHeader({ col, children, descFirst = false, className }) {
  const t = useTranslations("common");
  const setQuery = useSetQuery();
  const params = useSearchParams();
  const current = params.get("sort");

  const asc = current === col;
  const desc = current === `-${col}`;
  const Icon = asc ? ArrowUp : desc ? ArrowDown : ArrowUpDown;

  // Sorting has to reset the page: page 4 of the old order holds nothing a
  // reader was looking for, and on a shorter list it is past the end entirely.
  const onClick = () => {
    const first = descFirst ? `-${col}` : col;
    const second = descFirst ? col : `-${col}`;
    const next = current === first ? second : current === second ? null : first;
    setQuery({ sort: next }, { resetPage: true });
  };

  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={t("sortBy")}
      data-state={asc ? "asc" : desc ? "desc" : "none"}
      className={cn(
        "-mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        (asc || desc) && "text-foreground",
        className,
      )}
    >
      {children}
      <Icon className={cn("size-3.5", !asc && !desc && "opacity-50")} />
    </button>
  );
}
