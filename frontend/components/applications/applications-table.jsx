"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { formatBytes } from "@/lib/format/bytes";
import { ArchiveRestore, ChevronRight, SearchX, AppWindow } from "lucide-react";
import { TlsMark, isServedOverTls } from "@/components/applications/tls-mark";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { ApplicationOriginBadge } from "@/components/applications/application-origin-badge";
import { Badge } from "@/components/ui/badge";
import { VisitSiteLink } from "@/components/applications/visit-site-link";
import { Button } from "@/components/ui/button";
import { runtimeLabel, runtimeOf } from "@/lib/applications/runtime-of";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { FacetSelect } from "@/components/data-table/facet-select";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { ListCard } from "@/components/data-table/list-card";
import { useSetQuery } from "@/hooks/use-set-query";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { SortHeader } from "@/components/data-table/sort-header";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { measureApplicationSize } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ApplicationEmptyState } from "@/components/applications/application-empty-state";
import { CreateApplicationButton } from "@/components/applications/create-application-button";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { ApplicationsCards } from "@/components/applications/applications-cards";
import { gitProviderFor } from "@/lib/applications/git-provider";
import { DomainText } from "@/components/ui/domain-text";
import {
  ApplicationStatusBadge,
  ApplicationStatusNotes,
  APPLICATION_STATUSES,
} from "@/components/applications/application-status-badge";
import { applicationIconDataUri } from "@/lib/applications/application-icon";
import { HoverHint } from "@/components/ui/hover-hint";
import { shownSizeBytes } from "@/lib/applications/size-measurement";

// Cells live outside the component so polling doesn't remount them and close open dialogs.

// `block` is needed for truncate to show the ellipsis.
function RunsOnCell({ row }) {
  const t = useTranslations("applications");
  const tDocker = useTranslations("docker");
  const runtime = runtimeOf(row.original);
  if (!runtime) return null;
  return (
    <HoverHint content={runtimeLabel(runtime, t, tDocker, { full: true })}><span
      className="block w-fit whitespace-normal break-words text-xs tabular-nums text-muted-foreground"
    >
      {runtimeLabel(runtime, t, tDocker)}
    </span></HoverHint>
  );
}

function LastBackupCell({ row, table }) {
  const t = useTranslations("applications");
  const standing = table.options.meta?.backupStanding?.[row.original.id];
  if (!standing || standing.state === "unprotected") {
    return (
      <HoverHint content={t("backups.setUp")}><Link
        href={`/applications/${row.original.id}/backups`}
        prefetch={false}
        className="flex min-w-0 items-start gap-1.5 text-sm text-destructive"
      >
        <ArchiveRestore className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span className="min-w-0 whitespace-normal break-words leading-snug underline decoration-dotted decoration-destructive/50 underline-offset-4 hover:decoration-solid">
          {t("backups.notSetUp")}
        </span>
      </Link></HoverHint>
    );
  }
  let text, title, tone = "text-muted-foreground";
  if (standing.lastFailed) {
    text = t("status.failed");
    title = standing.when;
    tone = "text-destructive";
  } else if (standing.state === "paused") {
    text = t("backups.state.paused");
    title = standing.when ?? t("backups.never");
  } else {
    text = standing.when ?? t("backups.never");
  }
  return (
    <HoverHint content={title}><span className={`flex min-w-0 items-start gap-1.5 text-sm ${tone}`}>
      <ArchiveRestore className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span className="min-w-0 whitespace-normal break-words leading-snug">{text}</span>
    </span></HoverHint>
  );
}

function OwnerCell({ row }) {
  const value = row.original.system_user?.username ?? "—";
  return (
    <HoverHint content={value}><span className="block truncate text-sm text-muted-foreground">
      {value}
    </span></HoverHint>
  );
}

function CreatedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human ?? "—"}
    </span>
  );
}

function SizeCell({ row }) {
  const t = useTranslations("applications");
  const format = useFormatter();
  const router = useRouter();
  const [measuring, setMeasuring] = useState(false);
  const size = formatBytes(shownSizeBytes(row.original), format);

  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(row.original.id);
      router.refresh();
    } catch (error) {
      // Rate-limited to 10/min by the API, so show its message.
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  if (size === null) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            onClick={measure}
            disabled={measuring}
            className="rounded text-left leading-snug whitespace-normal text-muted-foreground underline decoration-dotted decoration-from-font underline-offset-4 transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:no-underline"
          >
            {measuring ? t("size.measuring") : t("size.notMeasured")}
          </button>
        </TooltipTrigger>
        <TooltipContent>{t("size.measureHint")}</TooltipContent>
      </Tooltip>
    );
  }

  return <span className="whitespace-nowrap tabular-nums text-muted-foreground">{size}</span>;
}

function ActionsCell({ row, table }) {
  return (
    <ApplicationRowActions
      application={row.original}
      canManage={table.options.meta?.canManage ?? false}
      canMagicLogin={table.options.meta?.canMagicLogin ?? false}
      canRemoveSystemUser={table.options.meta?.canRemoveSystemUser ?? false}
      canManageDocker={table.options.meta?.canManageDocker ?? false}
      canOpen={table.options.meta?.canOpen ?? true}
    />
  );
}

function NameCell({ row, missingDatabase = false, gitProvider = null, iconDataUri, canOpen = true }) {
  const t = useTranslations("applications");
  return <div className="flex min-w-0 items-center gap-3"><span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-card shadow-e1"><SiteTypeLogo name={row.original.site_type} provider={gitProvider} label={row.original.site_type_title ?? row.original.site_type} iconDataUri={iconDataUri} size="h-5 w-5" /></span><div className="min-w-0"><div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">{canOpen ? <Link href={`/applications/${row.original.id}`} prefetch={false} className="group inline-flex min-w-0 items-center gap-1.5 font-medium text-foreground underline-offset-4 hover:text-primary hover:underline"><HoverHint content={row.original.name}><span className="truncate">{row.original.name}</span></HoverHint><ChevronRight className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" /></Link> : <HoverHint content={row.original.name}><span className="min-w-0 truncate font-medium text-foreground">{row.original.name}</span></HoverHint>}{row.original.is_staging ? <Badge variant="warning" className="shrink-0 font-normal">{t("stagingBadge")}</Badge> : null}<ApplicationOriginBadge origin={row.original.origin} compact />{missingDatabase ? <Badge variant="warning" className="shrink-0 font-normal">{t("noDatabaseBadge")}</Badge> : null}</div><div className="flex min-w-0 items-center gap-1"><TlsMark application={row.original} label={isServedOverTls(row.original) ? t("domains.secured") : t("domains.noCertificate")} /><DomainText domain={row.original.domain} className="font-mono text-xs text-muted-foreground" />{row.original.status === "active" && row.original.url ? <VisitSiteLink href={row.original.url} label={t("actions.visitNamed", { domain: row.original.domain })} className="size-5" /> : null}</div></div></div>;
}

function StatusCell({ row }) {
  return (
    <div className="space-y-1">
      <ApplicationStatusBadge application={row.original} />
      <RunsOnCell row={row} />
      <ApplicationStatusNotes application={row.original} />
    </div>
  );
}

function Filters({ statusOptions, typeOptions, t }) {
  return (
    <div className="flex flex-col gap-2 sm:flex-row">
      <SearchInput placeholder={t("searchPlaceholder")} />
      <FacetSelect
        paramKey="status"
        allLabel={t("filters.allStatuses")}
        label={t("filters.statusLabel")}
        options={statusOptions.map(([value, label]) => ({ value, label }))}
        className="w-full sm:w-40"
      />
      <FacetSelect
        paramKey="site_type"
        allLabel={t("filters.allTypes")}
        label={t("filters.typeLabel")}
        options={typeOptions.map(([value, label]) => ({ value, label }))}
        className="w-full sm:w-44"
      />
    </div>
  );
}

// Column widths in %, per breakpoint. Each set must add up to 100.
const COLUMN_WIDTHS = {
  withBackups: {
    name: "w-[50%] xl:w-[35%] 2xl:w-[35%]",
    status: "w-[24%] xl:w-[17%] 2xl:w-[14%]",
    lastBackup: "w-[19%] xl:w-[19%] 2xl:w-[15%]",
    owner: "hidden xl:table-cell xl:w-[14%] 2xl:w-[11%]",
    size: "hidden xl:table-cell xl:w-[10%] 2xl:w-[8%]",
    created: "hidden 2xl:table-cell 2xl:w-[12%]",
    actions: "w-[7%] xl:w-[5%]",
  },
  withoutBackups: {
    name: "w-[69%] xl:w-[54%] 2xl:w-[48%]",
    status: "w-[24%] xl:w-[17%] 2xl:w-[14%]",
    owner: "hidden xl:table-cell xl:w-[14%] 2xl:w-[15%]",
    size: "hidden xl:table-cell xl:w-[10%] 2xl:w-[8%]",
    created: "hidden 2xl:table-cell 2xl:w-[10%]",
    actions: "w-[7%] xl:w-[5%]",
  },
};

export function ApplicationsTable(props) {
  return (
    <NavTransitionProvider>
      <ApplicationsList {...props} />
    </NavTransitionProvider>
  );
}

function ApplicationsList({
  applications = [],
  meta,
  siteTypes = [],
  canManage = false,
  canOpen = true,
  canMagicLogin = false,
  canRemoveSystemUser = false,
  canManageDocker = false,
  missingDatabase = new Set(),
  gitProviders = new Map(),
  // null hides the Backups column.
  backupStanding = null,
}) {
  const t = useTranslations("applications");
  const withBackups = backupStanding !== null;
  const widths = COLUMN_WIDTHS[withBackups ? "withBackups" : "withoutBackups"];
  const tCommon = useTranslations("common");
  const router = useRouter();
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();

  const filtering = Boolean(
    searchParams.get("search") || searchParams.get("status") || searchParams.get("site_type"),
  );

  const statusOptions = useMemo(
    () => APPLICATION_STATUSES.map((value) => [value, t(`status.${value}`)]),
    [t],
  );
  const typeOptions = useMemo(
    () => siteTypes.map((type) => [type.name, type.title ?? type.name]),
    [siteTypes],
  );

  const hasWorkingApplication = applications.some((application) => application.status === "pending" || application.status === "provisioning");
  useEffect(() => { if (!hasWorkingApplication) return undefined; const timer = window.setInterval(() => router.refresh(), 4000); return () => window.clearInterval(timer); }, [hasWorkingApplication, router]);

  const columns = useMemo(
    () => [
      // `col` must be in the API's sort whitelist, otherwise it returns 422.
      { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, meta: { className: widths.name, sortKey: "name" }, cell: ({ row }) => <NameCell row={row} missingDatabase={missingDatabase.has(row.original.id)} gitProvider={gitProviderFor(row.original, gitProviders)} iconDataUri={applicationIconDataUri(row.original, siteTypes)} canOpen={canOpen} /> },
      { accessorKey: "status", header: () => <SortHeader col="status">{t("columns.status")}</SortHeader>, meta: { className: widths.status, sortKey: "status" }, cell: StatusCell },
      ...(withBackups ? [{ id: "lastBackup", header: t("columns.lastBackup"), meta: { className: `${widths.lastBackup} h-auto min-h-10 whitespace-normal` }, cell: LastBackupCell }] : []),
      // h-auto because TableHead has a fixed 44px height and this header can wrap.
      { id: "owner", header: t("columns.owner"), meta: { className: `${widths.owner} h-auto min-h-11 whitespace-normal` }, cell: OwnerCell },
      { id: "size", header: () => <SortHeader col="directory_size_bytes" descFirst>{t("columns.size")}</SortHeader>, meta: { className: widths.size, sortKey: "directory_size_bytes" }, cell: SizeCell },
      { id: "created", header: () => <SortHeader col="created_at" descFirst>{t("columns.created")}</SortHeader>, meta: { className: widths.created, sortKey: "created_at" }, cell: CreatedCell },
      { id: "actions", header: "", meta: { className: widths.actions }, cell: ActionsCell },
    ],
    // Keep missingDatabase here, or the badge stays after a database is attached.
    [t, missingDatabase, withBackups, widths, gitProviders, siteTypes, canOpen],
  );

  const filters = <Filters statusOptions={statusOptions} typeOptions={typeOptions} t={t} />;
  const toolbar = (
    <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      {filters}
      <div className="flex flex-wrap items-center gap-2"><RefreshButton /><CreateApplicationButton canManage={canManage} /></div>
    </div>
  );

  if (!applications.length && !filtering) return <ApplicationEmptyState canManage={canManage} />;

  if (!applications.length) {
    return (
      <ListCard toolbar={toolbar}>
        <EmptyState
          icon={SearchX}
          subject={AppWindow}
          title={t("empty.filteredTitle")}
          action={
            <Button
              variant="outline"
              onClick={() => setQuery({ search: undefined, status: undefined, site_type: undefined }, { resetPage: true })}
            >
              {tCommon("clearFilters")}
            </Button>
          }
        />
      </ListCard>
    );
  }

  return (
    <ListCard from="c900" toolbar={toolbar} footer={<DataTablePagination meta={meta} />}>
      <div className="@min-[900px]/page:hidden"><ApplicationsCards applications={applications} canManage={canManage} canOpen={canOpen} canMagicLogin={canMagicLogin} canRemoveSystemUser={canRemoveSystemUser} canManageDocker={canManageDocker} gitProviders={gitProviders} siteTypes={siteTypes} /></div>
      <div className="hidden @min-[900px]/page:block"><DataTable columns={columns} data={applications} meta={{ canManage, canOpen, canMagicLogin, canRemoveSystemUser, canManageDocker, backupStanding }} fixedLayout bare roomy /></div>
    </ListCard>
  );
}
