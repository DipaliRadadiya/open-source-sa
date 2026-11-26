"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ChevronRight, Database, Plus, SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
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
import { attachedApplication } from "@/lib/backups/database-availability";
import { DatabasesCards } from "@/components/databases/databases-cards";
import { DeleteDatabaseDialog } from "@/components/databases/delete-database-dialog";
import { AttachApplicationDialog } from "@/components/databases/attach-application-dialog";
import { DatabaseRowActions } from "@/components/databases/database-row-actions";
import { HoverHint } from "@/components/ui/hover-hint";
import { SizeText } from "@/components/ui/size-text";

// Cells at module level: inline cells remount on every keystroke.

const COMPACT = "px-3";

function NameCell({ row }) {
  return (
    <HoverHint content={row.original.name}><Link
      href={`/databases/${row.original.id}`}
      className="group flex min-w-0 items-center gap-1.5 font-mono font-medium text-foreground underline-offset-4 hover:text-primary hover:underline"
    >
      <span className="max-w-54 truncate">{row.original.name}</span>
      <ChevronRight className="size-3.5 shrink-0 opacity-60 transition-opacity group-hover:opacity-100" />
    </Link></HoverHint>
  );
}

function EngineCell({ row, table }) {
  const name = table.options.meta.engineName(row.original.engine);
  return (
    <span className="flex items-center gap-2 text-muted-foreground">
      <EngineLogo engine={row.original.engine} size="h-5 w-auto max-w-16" />
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
      <SizeText value={row.original.size_human} />
    </span>
  );
}

function ApplicationCell({ database, applications, canOpenApplications, onAttach }) {
  const t = useTranslations("databases");
  const application = attachedApplication(database, applications);

  if (application?.name) {
    return canOpenApplications ? (
      <HoverHint content={application.name}><Link
        href={`/applications/${application.id}`}
        prefetch={false}
        className="break-words underline-offset-4 hover:underline"
      >
        {application.name}
      </Link></HoverHint>
    ) : (
      <span className="break-words">{application.name}</span>
    );
  }

  if (application) {
    return <span className="text-muted-foreground">{t("columns.applicationUnknown")}</span>;
  }

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

function UsersCell({ row }) {
  const t = useTranslations("databases");
  const count = row.original.users_count ?? 0;

  if (count === 0) {
    return (
      <HoverHint content={t("columns.noUsers")}><span className="tabular-nums text-muted-foreground">
        0
      </span></HoverHint>
    );
  }
  return <span className="tabular-nums">{count}</span>;
}

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
      adminerSites={table.options.meta.adminerSites}
      showManage={false}
      menu
      className="flex justify-end"
    />
  );
}

export function DatabasesTable(props) {
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
  phpmyadminSites = null,
  adminerSites = null,
  siteTypes = [],
  applications = [],
  canOpenApplications = false,
  databaseCounts = null,
  databasesKnown = false,
}) {
  const t = useTranslations("databases");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const [createOpen, setCreateOpen] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [attaching, setAttaching] = useState(null);

  const showEngine = engines.length > 1;

  const columns = [
    { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, meta: { sortKey: "name" }, cell: NameCell },
    ...(showEngine
      ? [{ accessorKey: "engine", header: () => <SortHeader col="engine">{t("columns.engine")}</SortHeader>, meta: { sortKey: "engine", className: COMPACT }, cell: EngineCell }]
      : []),
    {
      id: "application",
      accessorFn: (row) => attachedApplication(row, applications)?.name ?? "",
      header: t("columns.application"),
      meta: { className: "min-w-24 whitespace-normal" },
      cell: ({ row }) => (
        <ApplicationCell
          database={row.original}
          applications={applications}
          canOpenApplications={canOpenApplications}
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
      meta: { sortKey: "users_count", className: `${COMPACT} whitespace-normal` },
      cell: UsersCell,
    },
    {
      id: "lastBackup",
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
            meta: { className: "w-12 text-right" },
            cell: RowActionsCell,
          },
        ]
      : []),
  ];

  const usable = engines.filter((engine) => engine.running);
  const createReason = !canManage
    ? DENIED
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

  // attached has no control of its own, so count it as a filter.
  const onlyUnlinked = searchParams.get("attached") === "0";
  const isFiltered = Boolean(searchParams.get("search")) || onlyUnlinked;

  const toolbar = (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex min-w-48 flex-1 flex-wrap items-center gap-2">
        <SearchInput placeholder={t("searchPlaceholder")} />
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
        <ListCard from="c900" toolbar={toolbar} footer={<DataTablePagination meta={meta} />}>
          <div className="@min-[900px]/page:hidden">
            <DatabasesCards
              databases={data}
              canManage={canManage}
              onDelete={setDeleting}
              phpmyadminSites={phpmyadminSites}
              adminerSites={adminerSites}
              showEngine={showEngine}
              engineName={(engine) => t(`engines.${engine}`)}
              lastBackup={lastBackup}
              backupsUnknown={backupsUnknown}
              applications={applications}
              canOpenApplications={canOpenApplications}
              onAttach={canManage ? setAttaching : null}
            />
          </div>
          <div className="hidden @min-[900px]/page:block">
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
                adminerSites,
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
            application={attachedApplication(deleting, applications)?.name ? attachedApplication(deleting, applications) : null}
            open={deleting !== null}
            onOpenChange={(next) => !next && setDeleting(null)}
          />
        </>
      ) : null}
    </div>
  );
}
