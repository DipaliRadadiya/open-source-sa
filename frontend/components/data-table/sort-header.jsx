import { useSearchParams } from "next/navigation";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { useSetQuery } from "@/hooks/use-set-query";
import { sortDirection } from "@/lib/data-table/sort-direction";
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
  const setQuery = useSetQuery();
  const params = useSearchParams();
  const current = params.get("sort");

  // Same reading of `?sort=` the `<th>` uses for `aria-sort`, so the arrow and
  // what a screen reader is told can never disagree.
  const direction = sortDirection(current, col);
  const asc = direction === "ascending";
  const desc = direction === "descending";
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
      /*
       * No `aria-label` here, deliberately. It used to carry "Sort by this
       * column", which is the one thing this button must not be called: an
       * `aria-label` REPLACES the element's text, so all four buttons
       * announced identically and the column names vanished — the header row
       * read out as "Sort by this column, PHP, Sort by this column, System
       * user, Sort by this column, Sort by this column". It also named the
       * `<th>`, so every cell beneath was announced as "Sort by this column:
       * 4.2 GB" instead of "Size: 4.2 GB".
       *
       * The column name is the correct accessible name, and a button inside a
       * `columnheader` already implies sorting. Which way it is sorted is
       * `aria-sort` on the `<th>`, which DataTable sets from the same
       * `sortDirection` reading of the URL this component uses.
       */
      data-state={asc ? "asc" : desc ? "desc" : "none"}
      className={cn(
        /*
         * `text-transform:inherit` because Tailwind's Preflight resets
         * `button { text-transform: none }` — a real reset, for an old
         * Edge/Firefox inheritance bug. So when `TableHead` became uppercase,
         * only the NON-sortable headers followed: the list read
         * "Application / SYSTEM USER / Size" in one row. Inherit rather than
         * hard-coding `uppercase`, so a table that opts out of it later takes
         * its sortable headers with it.
         */
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
