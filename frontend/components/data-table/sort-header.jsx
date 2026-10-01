import { useSearchParams } from "next/navigation";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { useSetQuery } from "@/hooks/use-set-query";
import { sortDirection } from "@/lib/data-table/sort-direction";
import { cn } from "@/lib/utils";

// Sorts through the API (`?sort=`); `col` must be an API `SORTS` key, else 422.
// Cycles unsorted → asc → desc → default; `descFirst` starts with descending.
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
      /* No `aria-label`: it would rename the button and every cell under the `<th>`. */
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
