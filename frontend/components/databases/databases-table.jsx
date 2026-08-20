"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ChevronRight, Database, Plus, SearchX, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { useSearchParams } from "next/navigation";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { PageOutOfRange } from "@/components/data-table/page-out-of-range";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { useSetQuery } from "@/hooks/use-set-query";
import { SortHeader } from "@/components/data-table/sort-header";
import { RefreshButton } from "@/components/data-table/refresh-button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { CreateDatabaseDialog } from "@/components/databases/create-database-dialog";
import { DeleteDatabaseDialog } from "@/components/databases/delete-database-dialog";

/* Cells are module-level components: flexRender treats a cell function's
 * identity as the component type, so inline definitions remount every cell on
 * each keystroke in the search box that lives in this same component. */

// The name is the way in: users, credentials and the connection string all
// live on the detail page, so the row has to lead somewhere.
function NameCell({ row }) {
  return (
    <Link
      href={`/databases/${row.original.id}`}
      // Primary colour, not plain text: nothing else said this name was the
      // way into the database. The chevron repeats it for anyone who does not
      // read colour as "clickable".
      className="group inline-flex items-center gap-1.5 font-mono font-medium text-primary underline-offset-4 hover:underline"
    >
      {row.original.name}
      <ChevronRight className="size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
    </Link>
  );
}

function EngineCell({ row, table }) {
  return (
    <span className="text-muted-foreground">
      {table.options.meta.engineName(row.original.engine)}
    </span>
  );
}

function SizeCell({ row }) {
  return (
    <span className="whitespace-nowrap tabular-nums">
      {row.original.size_human ?? "—"}
    </span>
  );
}

/**
 * Zero users is the state worth seeing: nothing can connect to that database,
 * so it is doing no work and nobody would otherwise notice.
 */
function UsersCell({ row }) {
  const t = useTranslations("databases");
  const count = row.original.users_count ?? 0;

  if (count === 0) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("columns.noUsers")}
      </Badge>
    );
  }
  return <span className="tabular-nums">{count}</span>;
}

/**
 * The question the list could not answer: is this database protected?
 *
 * A 2 GB production database nobody has ever dumped looked identical to one
 * exported ten minutes ago. "Never" is the state worth the colour — and when
 * the exports request itself failed, this says nothing rather than lie.
 */
function BackupCell({ row, table }) {
  const t = useTranslations("databases");
  const { lastBackup, backupsUnknown } = table.options.meta;

  if (backupsUnknown) {
    return <span className="text-muted-foreground">—</span>;
  }

  const backup = lastBackup?.[row.original.id];
  if (!backup) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("columns.neverExported")}
      </Badge>
    );
  }

  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {backup.finished_at_human ?? backup.created_at_human ?? "—"}
    </span>
  );
}

function CreatedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human ?? "—"}
    </span>
  );
}

function RowActionsCell({ row, table }) {
  return (
    <RowActions
      database={row.original}
      onDelete={table.options.meta.onDelete}
      canManage={table.options.meta.canManage}
    />
  );
}

/**
 * Delete is the only row action, so it is the button — a menu that opens to
 * reveal one item costs a click and hides the only thing you can do. When a
 * second action arrives (export is the likely one) this goes back to a menu.
 */
function RowActions({ database, onDelete, canManage }) {
  const t = useTranslations("databases");
  const href = `/databases/${database.id}`;

  return (
    <div className="flex items-center justify-end gap-1">
      {/* Spelled out, not an icon. Users, credentials and backups all live on
          the detail page, and nothing on this row said so — people could not
          find them without being told the name was clickable. */}
      <Button asChild variant="outline" size="sm">
        <Link href={href}>
          {t("manage")}
          <ChevronRight className="size-3.5" />
        </Link>
      </Button>

      {/* On the row, not only on the detail page: opening the database is
          what most visits to this list are for, and making it cost a page
          load first is the difference between a shortcut and a detour. */}
      <PhpmyadminButton database={database} canManage={canManage} compact />

      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t("delete.forName", { name: database.name })}
            className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={() => onDelete(database)}
          >
            <Trash2 className="size-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>{t("delete.action")}</TooltipContent>
      </Tooltip>
    </div>
  );
}

export function DatabasesTable(props) {
  // One shared transition for search and paging, so the box spins and the table
  // dims while the server answers.
  return (
    <NavTransitionProvider>
      <DatabasesList {...props} />
    </NavTransitionProvider>
  );
}

function DatabasesList({
  data,
  meta,
  engines = [],
  canManage = false,
  lastBackup = {},
  backupsUnknown = false,
}) {
  const t = useTranslations("databases");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const [createOpen, setCreateOpen] = useState(false);
  const [deleting, setDeleting] = useState(null);

  // Whether to show the Engine column is a question about the SERVER, not
  // about the ten rows on screen. Counted from the page, the column appeared
  // and vanished as you turned it — the same mistake as building filter
  // options out of the current page.
  // `engines` is the server's own list, already a prop here for the create
  // guard below.
  const showEngine = engines.length > 1;

  const columns = [
    { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, cell: NameCell },
    ...(showEngine
      ? [{ accessorKey: "engine", header: () => <SortHeader col="engine">{t("columns.engine")}</SortHeader>, cell: EngineCell }]
      : []),
    {
      accessorKey: "size_bytes",
      header: t("columns.size"),
      cell: SizeCell,
      // NOT sortable: `size_bytes` is absent from the API's sort whitelist, so
      // asking for it is a 422. It used to sort here, and as the DEFAULT — which
      // once the list was paged meant "biggest on this page first" while
      // reading as "biggest first". Backend ask filed; a header that lies is
      // worse than one that does nothing.
    },
    {
      accessorKey: "users_count",
      header: () => <SortHeader col="users_count" descFirst>{t("columns.users")}</SortHeader>,
      cell: UsersCell,
    },
    {
      id: "lastBackup",
      // Sorts on the dump's timestamp, so "Never" (0) sinks to the bottom
      // ascending and rises to the top descending — the sort someone reaches
      // for this column to do.
      accessorFn: (row) => lastBackup?.[row.id]?.at ?? 0,
      header: t("columns.lastExport"),
      cell: BackupCell,
      sortingFn: "basic",
    },
    {
      // The API sorts this one properly. It used to sort by id in the browser,
      // standing in for a date it could not compare: "2 months ago" is a
      // sentence and created_at arrives as DD-MM-YYYY, which sorts
      // alphabetically into nonsense. The server has the real column.
      id: "created",
      header: () => <SortHeader col="created_at" descFirst>{t("columns.created")}</SortHeader>,
      cell: CreatedCell,
    },
    ...(canManage
      ? [
          {
            id: "actions",
            enableSorting: false,
            header: () => <span className="sr-only">{t("actions")}</span>,
            // Same shape as the firewall table: last column, fixed width,
            // right-aligned, so every list in the panel puts its row actions in
            // the same place.
            meta: { className: "w-40 text-right" },
            cell: RowActionsCell,
          },
        ]
      : []),
  ];

  // No running engine means create would fail before it started.
  const usable = engines.filter((engine) => engine.running);
  const createReason = !canManage
    ? t("noPermission")
    : usable.length === 0
      ? t("create.noEngine")
      : null;

  const createButton = (
    <ReasonTooltip reason={createReason}>
      <Button disabled={Boolean(createReason)} onClick={() => setCreateOpen(true)}>
        <Plus className="size-4" />
        {t("create.action")}
      </Button>
    </ReasonTooltip>
  );

  const isFiltered = Boolean(searchParams.get("search"));

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <SearchInput placeholder={t("searchPlaceholder")} />
        <div className="flex flex-wrap items-center gap-2">
          <RefreshButton />
          {createButton}
        </div>
      </div>

      {data.length === 0 && (meta?.current_page ?? 1) > 1 ? (
        <PageOutOfRange lastPage={meta?.last_page ?? 1} />
      ) : data.length === 0 ? (
        isFiltered ? (
          <EmptyState
            icon={SearchX}
            title={t("empty.filteredTitle")}
            description={t("empty.filteredDesc")}
            action={
              <Button variant="outline" onClick={() => setQuery({ search: undefined }, { resetPage: true })}>
                {t("empty.clearSearch")}
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Database}
            title={t("empty.title")}
            description={t("empty.description")}
            action={createButton}
          />
        )
      ) : (
        <DataTable
          columns={columns}
          data={data}
          meta={{
            canManage,
            onDelete: setDeleting,
            engineName: (engine) => t(`engines.${engine}`),
            lastBackup,
            backupsUnknown,
          }}
        />
      )}

      <DataTablePagination meta={meta} />

      {canManage ? (
        <>
          <CreateDatabaseDialog
            engines={engines}
            open={createOpen}
            onOpenChange={setCreateOpen}
          />
          <DeleteDatabaseDialog
            database={deleting}
            open={deleting !== null}
            onOpenChange={(next) => !next && setDeleting(null)}
          />
        </>
      ) : null}
    </div>
  );
}
