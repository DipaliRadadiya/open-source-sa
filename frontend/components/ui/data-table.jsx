"use client";

import { cloneElement, useState } from "react";
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
import { useTranslations } from "next-intl";
import { useNavPending } from "@/components/data-table/nav-transition";
import { ContextMenu, ContextMenuContent, ContextMenuTrigger } from "@/components/ui/context-menu";
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
 * `fixedLayout` switches to `table-layout: fixed` so each column's
 * `meta.className` width (e.g. `w-[35%]`) is actually respected, instead of
 * the browser's default `auto` layout treating it as a soft hint and still
 * dumping any leftover width into whichever column lacks an explicit one.
 * The table stays full width either way — this only changes how that width
 * is divided, not whether the table fills its container. Off by default so
 * every existing table's column sizing is unaffected.
 * `contextMenu(row)` opts a row into right-click support — return the menu's
 * `<ContextMenuItem>`s (or a falsy value to skip that row). Undefined by
 * default, so every other table's rows behave exactly as before.
 */
export function DataTable({
  columns,
  data,
  // Defaulted below rather than here: a literal default is invisible to the
  // i18n gate, which only reads t() calls — so twenty of the thirty tables in
  // the panel printed English "No results." to Spanish and Hindi readers while
  // every check stayed green. A caller with something better to say still
  // passes its own.
  emptyMessage,
  sortable = false,
  stickyHeader = false,
  defaultSorting = [],
  rowClassName,
  fixedLayout = false,
  contextMenu,
  // Opt-in: lets a row collapse a run of columns into one spanning cell.
  // `{ columns: [id, …], render: (rowOriginal) => node | null }` — when
  // `render` returns null the row is drawn normally, so this costs nothing for
  // every other table. Exists because a row whose columns all say "not set up"
  // reads as one statement, and repeating it four times per row turns ten rows
  // into a wall of grey.
  spanCells,
  // Drops this component's own border and rounding, for callers that already
  // sit inside a Card. Nested, the two read as a box drawn twice.
  bare = false,
  // Passed straight to TanStack and readable from any cell as
  // `table.options.meta`. It's how a cell gets per-table context without
  // closing over it — a closure would change identity every render, and
  // flexRender treats a new cell function as a new component type, remounting
  // the cell and destroying whatever state it held.
  meta,
  // Opt-in row selection. The state is owned by the caller, not by this
  // component: the thing that acts on a selection — a toolbar, a delete
  // dialog — lives outside the table, and a selection the table kept to
  // itself would be invisible to it. `rowId` maps a row to a stable key so
  // the selection survives the data being refetched; without it TanStack
  // keys by array index and a list that reorders selects different rows than
  // the ones that were ticked.
  rowSelection,
  onRowSelectionChange,
  rowId,
}) {
  const tc = useTranslations("common");
  const pending = useNavPending();
  const [sorting, setSorting] = useState(defaultSorting);
  const selectable = rowSelection !== undefined && onRowSelectionChange !== undefined;
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
    ...(selectable
      ? {
          state: { ...(sortable ? { sorting } : null), rowSelection },
          onRowSelectionChange,
          enableRowSelection: true,
          ...(rowId ? { getRowId: rowId } : null),
        }
      : null),
  });

  return (
    <div
      className={cn(
        "transition-opacity",
        // The last row's own rule would otherwise sit a pixel above the
        // enclosing card's edge — two lines where the eye expects one.
        bare ? "[&_tbody_tr:last-child]:border-0" : "rounded-xl border",
        // A wide table must scroll rather than silently clip columns. Skipped
        // when stickyHeader is on: the caller already supplies the scroll
        // container, and overflow-x:auto here would compute overflow-y to auto
        // too, nesting a second scroller and killing the sticky header.
        !stickyHeader && "overflow-x-auto",
        pending && "pointer-events-none opacity-60",
      )}
    >
      <Table className={fixedLayout ? "table-fixed" : undefined}>
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
            table.getRowModel().rows.map((row) => {
              const span = spanCells?.render(row.original) ?? null;
              const tableRow = (
                <TableRow className={rowClassName?.(row.original)}>
                  {row.getVisibleCells().map((cell) => {
                    if (span && spanCells.columns.includes(cell.column.id)) {
                      // Only the first of the run draws; the rest are absorbed
                      // by its colSpan.
                      if (cell.column.id !== spanCells.columns[0]) return null;
                      return (
                        <TableCell key={cell.id} colSpan={spanCells.columns.length}>
                          {span}
                        </TableCell>
                      );
                    }

                    return (
                      <TableCell key={cell.id} className={cell.column.columnDef.meta?.className}>
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    );
                  })}
                </TableRow>
              );
              const menuContent = contextMenu?.(row.original);
              if (!menuContent) return cloneElement(tableRow, { key: row.id });
              return (
                <ContextMenu key={row.id}>
                  <ContextMenuTrigger asChild>{tableRow}</ContextMenuTrigger>
                  <ContextMenuContent className="w-48" onCloseAutoFocus={(e) => e.preventDefault()}>
                    {menuContent}
                  </ContextMenuContent>
                </ContextMenu>
              );
            })
          ) : (
            <TableRow className="hover:bg-transparent">
              <TableCell
                colSpan={columns.length}
                className="h-28 text-center text-sm text-muted-foreground"
              >
                {emptyMessage ?? tc("noResults")}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
