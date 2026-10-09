"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ChevronRight, Database, Plus, SearchX } from "lucide-react";
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
import { ListCard } from "@/components/data-table/list-card";
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

/* Cells are module-level: inline cell functions remount every cell on each search keystroke. */

// The name links to the detail page (users, credentials, connection string).
// The short-value columns get 12px side padding, not 16: Russian at 1440 was 27px
// too wide for the content box, and these four columns hold a logo or a few characters.
const COMPACT = "px-3";

function NameCell({ row }) {
  return (
    <Link
      href={`/databases/${row.original.id}`}
      title={row.original.name}
      // Primary colour plus chevron, so the link does not rely on colour alone.
      // A usual name shows in full; past 13.5rem it ellipsises, so a long one cannot
      // widen the column over its neighbours (Krishna, 8 Oct). A max-width, not
      // max-w-0 on the cell: that cut every 27-character staging name too.
      className="group flex min-w-0 items-center gap-1.5 font-mono font-medium text-primary underline-offset-4 hover:underline"
    >
      <span className="max-w-54 truncate">{row.original.name}</span>
      <ChevronRight className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
    </Link>
  );
}

function EngineCell({ row, table }) {
  const name = table.options.meta.engineName(row.original.engine);
  return (
    <span className="flex items-center gap-2 text-muted-foreground">
      {/* The name stays beside the logo: the column sorts and searches on it. */}
      {/* 64px, not 80: the widest locale needed the 16px for the Application column. */}
      <EngineLogo engine={row.original.engine} size="h-5 w-auto max-w-16" />
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

// "Not linked" is a warning: no site backup contains this database.
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
        className="break-words underline-offset-4 hover:underline"
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

  // Grey text, not a yellow badge: three yellow badges a row made every row look
  // broken (Krishna, 8 Oct). Still the attach control when the reader can act,
  // dotted like "Not set up" on Applications; plain text otherwise.
  if (!onAttach) {
    return <span className="text-muted-foreground">{t("columns.notLinked")}</span>;
  }

  return (
    <button
      type="button"
      onClick={() => onAttach(database)}
      className="rounded-sm text-left text-muted-foreground underline decoration-dotted underline-offset-4 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
      aria-label={`${t("columns.notLinked")}. ${t("columns.attachFor", { name: database.name })}`}
    >
      {t("columns.notLinked")}
    </button>
  );
}

// Zero in grey: the header already says "Users", and "No users" in German was wide
// enough to squeeze the Application column. The greyed-out phpMyAdmin flags it too.
function UsersCell({ row }) {
  const t = useTranslations("databases");
  const count = row.original.users_count ?? 0;

  if (count === 0) {
    return (
      <span className="tabular-nums text-muted-foreground" title={t("columns.noUsers")}>
        0
      </span>
    );
  }
  return <span className="tabular-nums">{count}</span>;
}

// When the exports request failed, nothing is claimed.
function BackupCell({ row, table }) {
  const t = useTranslations("databases");
  const { lastBackup, backupsUnknown } = table.options.meta;

  if (backupsUnknown) {
    return <span className="text-muted-foreground">—</span>;
  }

  const backup = lastBackup?.[row.original.id];
  if (!backup) {
    return <span className="text-muted-foreground">{t("columns.neverExported")}</span>;
  }

  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {backup.finished_at_human ?? backup.created_at_human ?? "—"}
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
      ? [{ accessorKey: "engine", header: () => <SortHeader col="engine">{t("columns.engine")}</SortHeader>, meta: { sortKey: "engine", className: COMPACT }, cell: EngineCell }]
      : []),
    {
      // The site link decides what gets backed up.
      id: "application",
      accessorFn: (row) =>
        applicationById(applications, row.application_id)?.name ?? "",
      header: t("columns.application"),
      // Wraps rather than truncating: with `max-w-0` the action buttons squeezed this
      // column to 32px in Russian. Wrapped, it never drops below its longest word.
      meta: { className: "min-w-24 whitespace-normal" },
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
      header: () => <SortHeader col="size_bytes" descFirst>{t("columns.size")}</SortHeader>,
      meta: { sortKey: "size_bytes", className: COMPACT },
      cell: SizeCell,
    },
    {
      accessorKey: "users_count",
      header: () => <SortHeader col="users_count" descFirst>{t("columns.users")}</SortHeader>,
      // Long headers wrap ("Пользователи"), so they do not set the column width.
      meta: { sortKey: "users_count", className: `${COMPACT} whitespace-normal` },
      cell: UsersCell,
    },
    {
      id: "lastBackup",
      // Sorts on the dump's timestamp, so "Never" (0) sinks to the bottom
      // ascending and rises to the top descending.
      accessorFn: (row) => lastBackup?.[row.id]?.at ?? 0,
      header: t("columns.lastExport"),
      meta: { className: `${COMPACT} whitespace-normal` },
      cell: BackupCell,
      sortingFn: "basic",
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

  const toolbar = (
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
  );

  return (
    <div className="space-y-4">
      {data.length === 0 ? (
        <ListCard toolbar={toolbar}>
          {isFiltered ? (
            <EmptyState
              icon={SearchX}
              subject={Database}
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
          )}
        </ListCard>
      ) : (
        // Below 1440 the rows are cards of their own, so the list drops its frame there.
        <ListCard from="wide" toolbar={toolbar} footer={<DataTablePagination meta={meta} />}>
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
              bare
              roomy
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
        </ListCard>
      )}

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
