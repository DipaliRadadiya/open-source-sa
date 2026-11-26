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

export function DataTable({
  columns,
  data,
  emptyMessage,
  emptyIcon = Rows3,
  sortable = false,
  stickyHeader = false,
  defaultSorting = [],
  onSortingChange,
  rowClassName,
  fixedLayout = false,
  contextMenu,
  spanCells,
  bare = false,
  // Use meta instead of closures: a new cell function each render remounts the cell.
  meta,
  rowSelection,
  onRowSelectionChange,
  rowId,
  roomy = false,
}) {
  const tc = useTranslations("common");
  const pending = useNavPending();
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
    ...(rowId || idsUnique ? { getRowId: rowId ?? ((row) => String(row.id)) } : null),
  });

  return (
    <div
      className={cn(
        "transition-opacity",
        bare ? "[&_tbody_tr:last-child]:border-0" : "rounded-2xl border border-border/70 bg-card shadow-e1",
        !stickyHeader && "overflow-x-auto",
        pending && "pointer-events-none opacity-60",
      )}
    >
      <Table className={fixedLayout ? "table-fixed" : undefined}>
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
                      className={cn(
                        stickyHeader && "bg-[color-mix(in_srgb,var(--muted)_40%,var(--card))]",
                        header.column.columnDef.meta?.className,
                        header.column.columnDef.meta?.headClassName,
                      )}
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
