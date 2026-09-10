"use client";

import { useState } from "react";
import Link from "next/link";
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
/**
 * Which site this database belongs to.
 *
 * "Not linked" is a warning badge and not an empty cell, because the two look
 * identical and mean opposite things: a blank reads as "nothing to say", while
 * this one means no backup of any site contains this database.
 *
 * Matches the no-users badge beside it — same shape, same severity, same
 * "something here needs doing".
 */
function ApplicationCell({ database, applications, onAttach }) {
  const t = useTranslations("databases");
  const application = applicationById(applications, database.application_id);

  if (application) {
    return (
      <Link
        href={`/applications/${application.id}`}
        prefetch={false}
        className="underline-offset-4 hover:underline"
      >
        {application.name}
      </Link>
    );
  }

  // Attached to a site outside this user's reach: not the same as unattached,
  // and calling it "not linked" would invite an attach that would be refused.
  if (database.application_id !== null && database.application_id !== undefined) {
    return <span className="text-muted-foreground">{t("columns.applicationUnknown")}</span>;
  }

  // The badge IS the control when there is something to do about it: this is
  // where the reader finds out, and sending them to the detail page first to
  // act on it is a detour with no purpose. Plain badge for a reader who cannot
  // change it, rather than a button that would refuse.
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
      aria-label={t("columns.attachFor", { name: database.name })}
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
    <DatabaseRowActions
      database={row.original}
      onDelete={table.options.meta.onDelete}
      canManage={table.options.meta.canManage}
      phpmyadminSites={table.options.meta.phpmyadminSites}
    />
  );
}

/**
 * Delete is the only row action, so it is the button — a menu that opens to
 * reveal one item costs a click and hides the only thing you can do. When a
 * second action arrives (export is the likely one) this goes back to a menu.
 */

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
      // The link that decides what gets backed up, which had no column at all —
      // so a database missing from its site's backups looked exactly like one
      // that was in them.
      id: "application",
      accessorFn: (row) =>
        applicationById(applications, row.application_id)?.name ?? "",
      header: t("columns.application"),
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
      //
      // Hidden under 1600px of viewport. Eight columns need ~1290px and the
      // sidebar takes 320 of whatever the screen has, so on a 1440 laptop the
      // row ran past the edge and put Manage, phpMyAdmin and Delete off-screen
      // behind a scrollbar nothing pointed at. Age is the one column here that
      // no operational decision turns on, and it is still on the database's own
      // page — the actions are not recoverable anywhere else.
      // Hidden below 1536px. Eight columns need ~1290px and the sidebar takes
      // 320 of whatever the screen has, so on a 1440 laptop the row ran past
      // the edge and left Manage, phpMyAdmin and Delete off-screen behind a
      // scrollbar nothing pointed at. Age is the one column here no operational
      // decision turns on, and it is still on the database's own page — the
      // actions are not recoverable anywhere else.
      meta: { className: "hidden 2xl:table-cell" },
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

  // `attached` counts too. It has no control of its own — the banner's "Show
  // them" is the only thing that sets it — so without this an empty result read
  // as "this server has no databases" while a filter was quietly on.
  const onlyUnlinked = searchParams.get("attached") === "0";
  const isFiltered = Boolean(searchParams.get("search")) || onlyUnlinked;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex min-w-48 flex-1 flex-wrap items-center gap-2">
          <SearchInput placeholder={t("searchPlaceholder")} />
          {/* The one filter with no control of its own. Arriving here from the
              banner used to leave a filtered list with nothing saying so and no
              way back except navigating away and returning. */}
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
        /* Cards below lg, the table from lg up — the same rule as applications,
           services, cron jobs and the rest. Databases was the one list that
           never got a narrow-screen view: at 390px its seven columns are
           1115px wide inside a 356px scroller. */
        <>
        {/* 1440, not a named breakpoint, because that is where it measured:
            seven columns need 1118px and the sidebar plus padding take 320 of
            whatever the screen has, so 1440 is the first width the row fits.
            Below it the actions sat off the right edge behind a scrollbar
            nothing pointed at. The cards carry every field including the site,
            so using them wider loses nothing. */}
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
          />
          {/* Reached from the "Not linked" badge, so the fix is where the
              problem is announced. */}
          <AttachApplicationDialog
            database={attaching}
            open={attaching !== null}
            onOpenChange={(next) => !next && setAttaching(null)}
            applications={applications}
            databaseCounts={databaseCounts}
            databasesKnown={databasesKnown}
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
