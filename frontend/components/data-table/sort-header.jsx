import { useSearchParams } from "next/navigation";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { useSetQuery } from "@/hooks/use-set-query";
import { sortDirection } from "@/lib/data-table/sort-direction";
import { cn } from "@/lib/utils";

// col must be one of the API's sort keys, otherwise 422.
export function SortHeader({ col, children, descFirst = false, className }) {
  const setQuery = useSetQuery();
  const params = useSearchParams();
  const current = params.get("sort");

  const direction = sortDirection(current, col);
  const asc = direction === "ascending";
  const desc = direction === "descending";
  const Icon = asc ? ArrowUp : desc ? ArrowDown : ArrowUpDown;

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
      data-state={asc ? "asc" : desc ? "desc" : "none"}
      className={cn(
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
