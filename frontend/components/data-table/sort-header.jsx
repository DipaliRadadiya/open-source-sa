import { useSearchParams } from "next/navigation";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { useSetQuery } from "@/hooks/use-set-query";
import { sortDirection } from "@/lib/data-table/sort-direction";
import { cn } from "@/lib/utils";

/**
 * A column header that sorts through the API (via `?sort=`) rather than in the
 * table, for server-paged lists where a local sort would only reorder one page.
 *
 * `col` must be one of the API's `SORTS` keys; anything else is a 422.
 * Cycles unsorted → ascending → descending → API default. `descFirst` starts
 * with descending (e.g. size columns).
 */
export function SortHeader({ col, children, descFirst = false, className }) {
  const setQuery = useSetQuery();
  const params = useSearchParams();
  const current = params.get("sort");

  // Same reading the `<th>` uses for `aria-sort`, so the arrow and screen reader agree.
  const direction = sortDirection(current, col);
  const asc = direction === "ascending";
  const desc = direction === "descending";
  const Icon = asc ? ArrowUp : desc ? ArrowDown : ArrowUpDown;

  // Sorting resets the page.
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
      /*
       * Deliberately no `aria-label`: it would replace the column name for the
       * button and for every cell under the `<th>`. Direction is exposed via
       * `aria-sort` on the `<th>`.
       */
      data-state={asc ? "asc" : desc ? "desc" : "none"}
      className={cn(
        // `text-transform:inherit` undoes Preflight's `button { text-transform: none }`
        // so sortable headers match TableHead's casing.
        "-mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 [text-transform:inherit] hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        (asc || desc) && "text-foreground",
        className,
      )}
    >
      {children}
      <Icon className={cn("size-3.5", !asc && !desc && "opacity-50")} />
    </button>
  );
}
