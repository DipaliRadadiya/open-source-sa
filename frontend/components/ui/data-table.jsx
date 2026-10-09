import { cloneElement, useState } from "react";
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { ArrowDown, ArrowUp, ArrowUpDown, Rows3 } from "lucide-react";
import { EmptyArt } from "@/components/data-table/empty-art";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useNavPending } from "@/components/data-table/nav-transition";
import { sortDirection } from "@/lib/data-table/sort-direction";
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

// Server-driven by default: pagination, filtering and sorting come from URL controls.
// `fixedLayout` makes `meta.className` widths binding rather than hints.
export function DataTable({
  columns,
  data,
  // Defaults to a translated t() string below, never an English literal here.
  emptyMessage,
  // The picture over an empty list: the subject's own icon (an archive for backups…).
  emptyIcon = Rows3,
  sortable = false,
  stickyHeader = false,
  defaultSorting = [],
  onSortingChange,
  rowClassName,
  fixedLayout = false,
  contextMenu,
  // `{ columns: [id, …], render: (rowOriginal) => node | null }`; null renders normally.
  spanCells,
  // Drops the border and rounding, for tables already inside a Card.
  bare = false,
  // Readable from any cell as `table.options.meta`. Use this instead of a
  // closure: a new cell function each render remounts the cell and loses its state.
  meta,
  // Caller-owned selection; `rowId` keeps it stable across refetch or reorder.
  rowSelection,
  onRowSelectionChange,
  rowId,
  // Taller rows for lists people scan rather than read line by line.
  roomy = false,
}) {
  const tc = useTranslations("common");
  const pending = useNavPending();
  // Read once here rather than per header cell.
  const sortParam = useSearchParams().get("sort");
  const [sorting, setSorting] = useState(defaultSorting);
  const selectable = rowSelection !== undefined && onRowSelectionChange !== undefined;
  const idsUnique = data.every((row) => row?.id != null) && new Set(data.map((row) => row.id)).size === data.length;
  // eslint-disable-next-line react-hooks/incompatible-library -- TanStack Table's useReactTable is a known false positive for the React Compiler lint
  const table = useReactTable({
    data,
    columns,
    meta,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualFiltering: true,
    enableSorting: sortable,
    manualSorting: !sortable,
    ...(sortable
      ? {
          state: { sorting },
          onSortingChange: (updater) => {
            const next = typeof updater === "function" ? updater(sorting) : updater;
            setSorting(next);
            onSortingChange?.(next);
          },
          getSortedRowModel: getSortedRowModel(),
        }
      : null),
    ...(selectable
      ? {
          state: { ...(sortable ? { sorting } : null), rowSelection },
          onRowSelectionChange,
          enableRowSelection: true,
        }
      : null),
    // Key rows by record, not index, so cell state does not pass to another
    // row when the list changes.
    ...(rowId || idsUnique ? { getRowId: rowId ?? ((row) => String(row.id)) } : null),
  });

  return (
    <div
      className={cn(
        "transition-opacity",
        // Avoids a double line against the enclosing card's edge.
        // White and lifted like a card: the page behind is tinted.
        bare ? "[&_tbody_tr:last-child]:border-0" : "rounded-2xl border border-border/70 bg-card shadow-e1",
        // Scroll rather than clip wide tables. Skipped with stickyHeader:
        // overflow-x:auto would nest a second scroller and break sticky.
        !stickyHeader && "overflow-x-auto",
        pending && "pointer-events-none opacity-60",
      )}
    >
      <Table className={fixedLayout ? "table-fixed" : undefined}>
        {/* No column headings (or select-all box) over a list with nothing in it. */}
        {table.getRowModel().rows.length ? (
          <TableHeader className={cn(stickyHeader && "sticky top-0 z-10 shadow-sm")}>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id} className="bg-muted/40 hover:bg-muted/40">
                {headerGroup.headers.map((header) => {
                  const canSort = header.column.getCanSort();
                  const direction = header.column.getIsSorted();
                  return (
                    <TableHead
                      key={header.id}
                      // Sticky headers need an opaque background.
                      // `meta.className` lets a caller constrain a column's width.
                      className={cn(
                        stickyHeader && "bg-muted",
                        header.column.columnDef.meta?.className,
                      )}
                      /* Server-driven columns sort via the URL; `meta.sortKey` supplies aria-sort. */
                      aria-sort={
                        canSort
                          ? { asc: "ascending", desc: "descending" }[direction] ?? "none"
                          : header.column.columnDef.meta?.sortKey
                            ? sortDirection(sortParam, header.column.columnDef.meta.sortKey)
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
        ) : null}
        <TableBody>
          {table.getRowModel().rows.length ? (
            table.getRowModel().rows.map((row) => {
              const span = spanCells?.render(row.original) ?? null;
              const tableRow = (
                <TableRow className={rowClassName?.(row.original)}>
                  {row.getVisibleCells().map((cell) => {
                    if (span && spanCells.columns.includes(cell.column.id)) {
                      // Only the first of the run draws; colSpan covers the rest.
                      if (cell.column.id !== spanCells.columns[0]) return null;
                      return (
                        <TableCell key={cell.id} colSpan={spanCells.columns.length}>
                          {span}
                        </TableCell>
                      );
                    }

                    return (
                      <TableCell key={cell.id} className={cn(roomy && "py-4", cell.column.columnDef.meta?.className)}>
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
              <TableCell colSpan={columns.length} className="whitespace-normal">
                {/* The panel's empty state, not a bare sentence: picture, then the message. */}
                <div className="flex flex-col items-center gap-3 py-8 text-center">
                  <EmptyArt icon={emptyIcon} />
                  <p className="max-w-sm text-sm font-medium text-pretty text-foreground">
                    {emptyMessage ?? tc("noResults")}
                  </p>
                </div>
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
