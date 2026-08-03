"use client";

import { useState } from "react";
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useNavPending } from "@/components/data-table/nav-transition";
import { cn } from "@/lib/utils";

const SORT_ICONS = { asc: ArrowUp, desc: ArrowDown };

function SortableHeader({ header, label }) {
  const direction = header.column.getIsSorted();
  const Icon = SORT_ICONS[direction] ?? ArrowUpDown;

  return (
    <button
      type="button"
      onClick={header.column.getToggleSortingHandler()}
      className="-mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      {flexRender(label, header.getContext())}
      <Icon className={cn("size-3.5", !direction && "opacity-50")} />
    </button>
  );
}

/**
 * Generic presentational data grid (TanStack Table v8 + shadcn Table).
 * Server-driven by default: it receives already-fetched `data` and renders it —
 * pagination/filtering/sorting are manual (handled by URL controls).
 *
 * `sortable` opts a fully-client-side dataset into in-table sorting, and
 * `stickyHeader` pins the header row for tables inside a scroll container.
 * Both default off so server-driven callers are unaffected.
 * `rowClassName(row)` styles rows by their data (e.g. de-emphasising a paused
 * record) — it receives the original row object.
 */
export function DataTable({
  columns,
  data,
  emptyMessage = "No results.",
  sortable = false,
  stickyHeader = false,
  defaultSorting = [],
  rowClassName,
  // Passed straight to TanStack and readable from any cell as
  // `table.options.meta`. It's how a cell gets per-table context without
  // closing over it — a closure would change identity every render, and
  // flexRender treats a new cell function as a new component type, remounting
  // the cell and destroying whatever state it held.
  meta,
}) {
  const pending = useNavPending();
  const [sorting, setSorting] = useState(defaultSorting);
  // eslint-disable-next-line react-hooks/incompatible-library -- TanStack Table's useReactTable is a known false positive for the React Compiler lint
  const table = useReactTable({
    data,
    columns,
    meta,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualFiltering: true,
    // Off unless opted in, so server-driven tables render plain headers.
    enableSorting: sortable,
    manualSorting: !sortable,
    ...(sortable
      ? {
          state: { sorting },
          onSortingChange: setSorting,
          getSortedRowModel: getSortedRowModel(),
        }
      : null),
  });

  return (
    <div
      className={cn(
        "rounded-xl border transition-opacity",
        // A wide table must scroll rather than silently clip columns. Skipped
        // when stickyHeader is on: the caller already supplies the scroll
        // container, and overflow-x:auto here would compute overflow-y to auto
        // too, nesting a second scroller and killing the sticky header.
        !stickyHeader && "overflow-x-auto",
        pending && "pointer-events-none opacity-60",
      )}
    >
      <Table>
        <TableHeader className={cn(stickyHeader && "sticky top-0 z-10 shadow-sm")}>
          {table.getHeaderGroups().map((headerGroup) => (
            <TableRow key={headerGroup.id} className="bg-muted/40 hover:bg-muted/40">
              {headerGroup.headers.map((header) => {
                const canSort = header.column.getCanSort();
                const direction = header.column.getIsSorted();
                return (
                  <TableHead
                    key={header.id}
                    // Sticky rows are transparent by default — the body would
                    // scroll through them without an opaque background.
                    // `meta.className` lets a caller constrain a column's width;
                    // without it the first column eats the table and the rest
                    // bunch up against it.
                    className={cn(
                      stickyHeader && "bg-muted",
                      header.column.columnDef.meta?.className,
                    )}
                    aria-sort={
                      canSort
                        ? { asc: "ascending", desc: "descending" }[direction] ?? "none"
                        : undefined
                    }
                  >
                    {header.isPlaceholder ? null : canSort ? (
                      <SortableHeader
                        header={header}
                        label={header.column.columnDef.header}
                      />
                    ) : (
                      flexRender(header.column.columnDef.header, header.getContext())
                    )}
                  </TableHead>
                );
              })}
            </TableRow>
          ))}
        </TableHeader>
        <TableBody>
          {table.getRowModel().rows.length ? (
            table.getRowModel().rows.map((row) => (
              <TableRow key={row.id} className={rowClassName?.(row.original)}>
                {row.getVisibleCells().map((cell) => (
                  <TableCell key={cell.id} className={cell.column.columnDef.meta?.className}>
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : (
            <TableRow className="hover:bg-transparent">
              <TableCell
                colSpan={columns.length}
                className="h-28 text-center text-sm text-muted-foreground"
              >
                {emptyMessage}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
