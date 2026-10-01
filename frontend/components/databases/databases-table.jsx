"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ChevronRight, Database, Plus, SearchX } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { useSearchParams } from "next/navigation";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";
import { engineLogo } from "@/lib/databases/engine-logo";
import { EngineLogo } from "@/components/databases/engine-logo";
import { FilterX } from "lucide-react";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { useSetQuery } from "@/hooks/use-set-query";
import { SortHeader } from "@/components/data-table/sort-header";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { CreateDatabaseDialog } from "@/components/databases/create-database-dialog";
import { applicationById } from "@/lib/backups/database-availability";
import { DatabasesCards } from "@/components/databases/databases-cards";
import { DeleteDatabaseDialog } from "@/components/databases/delete-database-dialog";
import { AttachApplicationDialog } from "@/components/databases/attach-application-dialog";
import { DatabaseRowActions } from "@/components/databases/database-row-actions";

/* Cells are module-level components: flexRender treats a cell function's
 * identity as the component type, so inline definitions remount every cell on
 * each keystroke in the search box. */

// The name links to the detail page (users, credentials, connection string).
function NameCell({ row }) {
  return (
    <Link
      href={`/databases/${row.original.id}`}
      // Primary colour plus chevron, so the link does not rely on colour alone.
      className="group inline-flex items-center gap-1.5 font-mono font-medium text-primary underline-offset-4 hover:underline"
    >
      {row.original.name}
      <ChevronRight className="size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
    </Link>
  );
}

function EngineCell({ row, table }) {
  const name = table.options.meta.engineName(row.original.engine);
  return (
    <span className="flex items-center gap-2 text-muted-foreground">
      {/* The name stays beside the logo: the column sorts and searches on it. */}
      <EngineLogo engine={row.original.engine} />
      {/* Mark-only logos (PostgreSQL's elephant) need the printed name. */}
      {engineLogo(row.original.engine)?.wordmark ? (
        <span className="sr-only">{name}</span>
      ) : (
        <span className="text-xs font-medium text-foreground">{name}</span>
      )}
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
 * Which site this database belongs to. "Not linked" is a warning badge, not a
 * blank cell: it means no site backup contains this database.
 */
function ApplicationCell({ database, applications, onAttach }) {
  const t = useTranslations("databases");
  const application = applicationById(applications, database.application_id);

  if (application) {
    // `block truncate`: an inline link never shows the ellipsis. `title`
    // keeps the full name reachable.
    return (
      <Link
        href={`/applications/${application.id}`}
        prefetch={false}
        title={application.name}
        className="block truncate underline-offset-4 hover:underline"
      >
        {application.name}
      </Link>
    );
  }

  // Attached to a site outside this user's reach is not "not linked": an
  // attach there would be refused.
  if (database.application_id !== null && database.application_id !== undefined) {
    return <span className="text-muted-foreground">{t("columns.applicationUnknown")}</span>;
  }

  // The badge is the attach control when the reader can act; otherwise a
  // plain badge rather than a button that would refuse.
  if (!onAttach) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("columns.notLinked")}
      </Badge>
    );
  }

  return (
    <button
      type="button"
      onClick={() => onAttach(database)}
      className="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
      aria-label={`${t("columns.notLinked")}. ${t("columns.attachFor", { name: database.name })}`}
    >
      <Badge
        variant="warning"
        className="cursor-pointer font-normal underline-offset-2 hover:underline"
      >
        {t("columns.notLinked")}
      </Badge>
    </button>
  );
}

// Zero users is highlighted: nothing can connect to that database.
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
 * Whether this database has ever been exported. "Never" is highlighted; when
 * the exports request failed, nothing is claimed.
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
    <DatabaseRowActions
      database={row.original}
      onDelete={table.options.meta.onDelete}
      canManage={table.options.meta.canManage}
      phpmyadminSites={table.options.meta.phpmyadminSites}
    />
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
  // null when the lookup failed — see getPhpmyadminSite.
  phpmyadminSites = null,
  siteTypes = [],
  // For the create dialog's site picker.
  applications = [],
  databaseCounts = null,
  databasesKnown = false,
}) {
  const t = useTranslations("databases");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const [createOpen, setCreateOpen] = useState(false);
  const [deleting, setDeleting] = useState(null);
  // The database whose site is being chosen, or null.
  const [attaching, setAttaching] = useState(null);

  // Based on the server's engine list, not the current page, so the column
  // does not appear and vanish while paging.
  const showEngine = engines.length > 1;

  const columns = [
    { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, meta: { sortKey: "name" }, cell: NameCell },
    ...(showEngine
      ? [{ accessorKey: "engine", header: () => <SortHeader col="engine">{t("columns.engine")}</SortHeader>, meta: { sortKey: "engine" }, cell: EngineCell }]
      : []),
    {
      // The site link decides what gets backed up.
      id: "application",
      accessorFn: (row) =>
        applicationById(applications, row.application_id)?.name ?? "",
      header: t("columns.application"),
      // Auto-layout table: `max-w-0` lets the link ellipsise, and the width %
      // stops the column collapsing to ~70px.
      meta: { className: "w-[22%] max-w-0" },
      cell: ({ row }) => (
        <ApplicationCell
          database={row.original}
          applications={applications}
          onAttach={canManage ? setAttaching : null}
        />
      ),
      sortingFn: "text",
    },
    {
      accessorKey: "size_bytes",
      header: t("columns.size"),
      cell: SizeCell,
      // NOT sortable: `size_bytes` is not in the API's sort whitelist (422).
    },
    {
      accessorKey: "users_count",
      header: () => <SortHeader col="users_count" descFirst>{t("columns.users")}</SortHeader>,
      meta: { sortKey: "users_count" },
      cell: UsersCell,
    },
    {
      id: "lastBackup",
      // Sorts on the dump's timestamp, so "Never" (0) sinks to the bottom
      // ascending and rises to the top descending.
      accessorFn: (row) => lastBackup?.[row.id]?.at ?? 0,
      header: t("columns.lastExport"),
      cell: BackupCell,
      sortingFn: "basic",
    },
    {
      // Sorted by the API: created_at arrives as DD-MM-YYYY, which cannot be
      // sorted client-side. Hidden below 1536px so the row actions stay on
      // screen; the age is still on the database's own page.
      meta: { className: "hidden 2xl:table-cell", sortKey: "created_at" },
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
      // Same placement as other lists: last column, fixed width, right-aligned.
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

  // `attached` has no control of its own (only the banner sets it), so it
  // must count as a filter or an empty result reads as "no databases".
  const onlyUnlinked = searchParams.get("attached") === "0";
  const isFiltered = Boolean(searchParams.get("search")) || onlyUnlinked;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex min-w-48 flex-1 flex-wrap items-center gap-2">
          <SearchInput placeholder={t("searchPlaceholder")} />
          {/* The one filter with no control of its own, so it is shown with a
              way to clear it. */}
          {onlyUnlinked ? (
            <span className="flex items-center gap-1.5 rounded-full border border-warning/40 bg-warning/10 px-3 py-1 text-xs">
              <FilterX className="size-3.5 shrink-0 text-warning" />
              {t("unlinked.filtered")}
              <ClearFiltersButton
                keys={["attached"]}
                label={t("unlinked.showAll")}
                variant="link"
                size="sm"
                className="h-auto p-0 text-xs font-medium underline-offset-2"
              />
            </span>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <RefreshButton />
          {createButton}
        </div>
      </div>

      {data.length === 0 ? (
        isFiltered ? (
          <EmptyState
            icon={SearchX}
            title={t("empty.filteredTitle")}
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
        /* Cards below the breakpoint, the table above it. */
        <>
        {/* 1440 is measured: seven columns plus the sidebar first fit there.
            The cards carry every field, so using them wider loses nothing. */}
        <div className="min-[1440px]:hidden">
          <DatabasesCards
            databases={data}
            canManage={canManage}
            onDelete={setDeleting}
            phpmyadminSites={phpmyadminSites}
            showEngine={showEngine}
            engineName={(engine) => t(`engines.${engine}`)}
            lastBackup={lastBackup}
            backupsUnknown={backupsUnknown}
            applications={applications}
            onAttach={canManage ? setAttaching : null}
          />
        </div>
        <div className="hidden min-[1440px]:block">
        <DataTable
          columns={columns}
          data={data}
          meta={{
            canManage,
            onDelete: setDeleting,
            engineName: (engine) => t(`engines.${engine}`),
            lastBackup,
            backupsUnknown,
            phpmyadminSites,
          }}
        />
        </div>
        </>
      )}

      <DataTablePagination meta={meta} />

      {canManage ? (
        <>
          <CreateDatabaseDialog
            engines={engines}
            open={createOpen}
            onOpenChange={setCreateOpen}
            applications={applications}
            databaseCounts={databaseCounts}
            databasesKnown={databasesKnown}
            siteTypes={siteTypes}
          />
          <AttachApplicationDialog
            database={attaching}
            open={attaching !== null}
            onOpenChange={(next) => !next && setAttaching(null)}
            applications={applications}
            databaseCounts={databaseCounts}
            databasesKnown={databasesKnown}
            siteTypes={siteTypes}
          />
          <DeleteDatabaseDialog
            database={deleting}
            application={applicationById(applications, deleting?.application_id)}
            open={deleting !== null}
            onOpenChange={(next) => !next && setDeleting(null)}
          />
        </>
      ) : null}
    </div>
  );
}
