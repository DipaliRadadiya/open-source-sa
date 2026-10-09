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


/* Cells at module level: flexRender treats an inline cell as a new component type,
 * which remounts on every 4s poll and resets open dialogs. */

/* `block` before `truncate`: an inline span never shows the ellipsis. The title
 * carries the full value. */
/* Version only (the header says PHP); an em dash where it does not apply, the
 * same as OwnerCell. */
// PHP or Node.js with its version, a container, or plain files; the logo first so
// the column scans by shape.
// One plain line, no logo (Krishna, 7 Oct): the Application column already shows the logo.
function RunsOnCell({ row }) {
  const t = useTranslations("applications");
  const tDocker = useTranslations("docker");
  const runtime = runtimeOf(row.original);
  if (!runtime) return <span className="text-muted-foreground">—</span>;
  return (
    <span
      className="block whitespace-normal break-words tabular-nums text-muted-foreground"
      title={runtimeLabel(runtime, t, tDocker, { full: true })}
    >
      {runtimeLabel(runtime, t, tDocker)}
    </span>
  );
}

// From the backup targets list: one line with an icon, as in the prototype. Red only when
// there is something to fix; the detail behind "Failed" / "Manual only" is in the title.
function LastBackupCell({ row, table }) {
  const t = useTranslations("applications");
  const standing = table.options.meta?.backupStanding?.[row.original.id];
  if (!standing || standing.state === "unprotected") {
    // "Not set up" is itself the way out, to the Backups tab and its "Set up backups"
    // button. A second line for the link made every row three or four lines tall.
    return (
      <Link
        href={`/applications/${row.original.id}/backups`}
        prefetch={false}
        title={t("backups.setUp")}
        className="flex min-w-0 items-start gap-1.5 text-sm text-destructive"
      >
        <ArchiveRestore className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span className="min-w-0 whitespace-normal break-words leading-snug underline decoration-dotted decoration-destructive/50 underline-offset-4 hover:decoration-solid">
          {t("backups.notSetUp")}
          <span className="sr-only">: {t("backups.setUp")}</span>
        </span>
      </Link>
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
    <span className={`flex min-w-0 items-start gap-1.5 text-sm ${tone}`} title={title}>
      <ArchiveRestore className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span className="min-w-0 whitespace-normal break-words leading-snug">{text}</span>
    </span>
  );
}

function OwnerCell({ row }) {
  const value = row.original.system_user?.username ?? "—";
  return (
    <span className="block truncate font-mono text-xs text-muted-foreground" title={value}>
      {value}
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

/* Sizes come from a scheduled sweep; unmeasured sites show "Not measured", which re-measures on click. */
function SizeCell({ row }) {
  const t = useTranslations("applications");
  const format = useFormatter();
  const router = useRouter();
  const [measuring, setMeasuring] = useState(false);
  const size = formatBytes(row.original.directory_size_bytes, format);

  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(row.original.id);
      router.refresh();
    } catch (error) {
      // Throttled at 10/min and refused when the site has no directory; pass the
      // API's message on.
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  // Never measured: the words themselves are the trigger.
  if (size === null) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            onClick={measure}
            disabled={measuring}
            className="rounded whitespace-nowrap text-muted-foreground underline decoration-dotted decoration-from-font underline-offset-4 transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:no-underline"
          >
            {measuring ? t("size.measuring") : t("size.notMeasured")}
          </button>
        </TooltipTrigger>
        <TooltipContent>{t("size.measureHint")}</TooltipContent>
      </Tooltip>
    );
  }

  return <span className="whitespace-nowrap tabular-nums">{size}</span>;
}

function ActionsCell({ row, table }) {
  return (
    <ApplicationRowActions
      application={row.original}
      canManage={table.options.meta?.canManage ?? false}
      canMagicLogin={table.options.meta?.canMagicLogin ?? false}
      canRemoveSystemUser={table.options.meta?.canRemoveSystemUser ?? false}
    />
  );
}

function NameCell({ row, missingDatabase = false, gitProvider = null }) {
  const t = useTranslations("applications");
  return <div className="flex min-w-0 items-center gap-3">{/* On its own tile, so marks of every shape line up. */}<span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-card shadow-e1"><SiteTypeLogo name={row.original.site_type} provider={gitProvider} label={row.original.site_type_title ?? row.original.site_type} size="h-5 w-5" /></span><div className="min-w-0"><div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1"><Link href={`/applications/${row.original.id}`} prefetch={false} className="group inline-flex min-w-0 items-center gap-1.5 font-medium text-foreground underline-offset-4 hover:text-primary hover:underline"><span className="truncate" title={row.original.name}>{row.original.name}</span><ChevronRight className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" /></Link>{/* A copy and the site it copies sit next to each other in this list under near-identical names. Marking the copy is the difference between editing the right site and the wrong one. */}{row.original.is_staging ? <Badge variant="warning" className="shrink-0 font-normal">{t("stagingBadge")}</Badge> : null}{/* Only for a site type that needs a database and has none: its backups will not contain one, and nothing else in this list would say so. */}{missingDatabase ? <Badge variant="warning" className="shrink-0 font-normal">{t("noDatabaseBadge")}</Badge> : null}</div><div className="flex min-w-0 items-center gap-1">{/* The padlock goes beside the DOMAIN, not in a column of its own: TLS is a property of the address, which is the convention every browser already taught people, and `url` only ever describes this one domain. It also costs no width in a table that is already at 100%. */}<TlsMark application={row.original} label={isServedOverTls(row.original) ? t("domains.secured") : t("domains.noCertificate")} /><DomainText domain={row.original.domain} className="font-mono text-xs text-muted-foreground" />{row.original.status === "active" && row.original.url ? <VisitSiteLink href={row.original.url} label={t("actions.visitNamed", { domain: row.original.domain })} className="size-5" /> : null}</div></div></div>;
}


function StatusCell({ row }) {
  return (
    <div className="space-y-1">
      <ApplicationStatusBadge application={row.original} />
      <ApplicationStatusNotes application={row.original} />
    </div>
  );
}


/* `FacetSelect`, like every other list filter: "All statuses"-style clear
 * option, and a guard against an unknown `?status=` value. */
function Filters({ statusOptions, typeOptions, t }) {
  return (
    <div className="flex flex-col gap-2 sm:flex-row">
      {/* Server-side and debounced: the list is paged. */}
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

// `siteTypes` comes from `GET /site-types`, not the visible rows, so every type stays filterable.
// Percentages plus `fixedLayout` bound the columns so `truncate` works. Each set totals
// 100 at lg, xl and 2xl. Sized from the widest locale's cell at the NARROWEST table of
// each breakpoint (704px at 1024, 960px at 1280, 1215px at 1536), measured 7 Oct:
// Status 166px (ru "Приостановлено"; de "fehlgeschlagen" in the deploy tag 148px),
// Last backup 132px (pt), Runs on 120px (ru), Size 93px (es "Tamaño" + sort arrow).
// Columns join as the table widens: Size from xl, System user and Created from 2xl.
const COLUMN_WIDTHS = {
  withBackups: {
    name: "w-[33%] xl:w-[38%] 2xl:w-[25%]",
    status: "w-[24%] xl:w-[18%] 2xl:w-[14%]",
    runsOn: "w-[17%] xl:w-[13%] 2xl:w-[10%]",
    lastBackup: "w-[19%] xl:w-[14%] 2xl:w-[11%]",
    owner: "hidden 2xl:table-cell 2xl:w-[13%]",
    size: "hidden xl:table-cell xl:w-[10%] 2xl:w-[8%]",
    created: "hidden 2xl:table-cell 2xl:w-[12%]",
    actions: "w-[7%]",
  },
  withoutBackups: {
    name: "w-[52%] xl:w-[52%] 2xl:w-[36%]",
    status: "w-[24%] xl:w-[18%] 2xl:w-[14%]",
    runsOn: "w-[17%] xl:w-[13%] 2xl:w-[10%]",
    owner: "hidden 2xl:table-cell 2xl:w-[15%]",
    size: "hidden xl:table-cell xl:w-[10%] 2xl:w-[8%]",
    created: "hidden 2xl:table-cell 2xl:w-[10%]",
    actions: "w-[7%]",
  },
};

export function ApplicationsTable(props) {
  // One transition shared by search, filters and pager, so the table shows
  // pending state while the server answers.
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
  canMagicLogin = false,
  canRemoveSystemUser = false,
  // Ids of sites whose type needs a database and that have none. Empty when
  // the reader cannot see databases, or when the count could not be read.
  missingDatabase = new Set(),
  // `git_account_id` → provider, resolved on the server. Empty (generic git mark)
  // when not needed, not permitted, or the fetch failed.
  gitProviders = new Map(),
  // Application id → backup standing; null when the reader cannot see backups or the
  // list could not be read, and then the column is left out.
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
      // `col` must be on the API's sort whitelist (else 422). Status sits right after
      // the name: beside it, PHP's short value read as part of the domain (7 Oct).
      { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, meta: { className: widths.name, sortKey: "name" }, cell: ({ row }) => <NameCell row={row} missingDatabase={missingDatabase.has(row.original.id)} gitProvider={gitProviderFor(row.original, gitProviders)} /> },
      { accessorKey: "status", header: () => <SortHeader col="status">{t("columns.status")}</SortHeader>, meta: { className: widths.status, sortKey: "status" }, cell: StatusCell },
      { id: "runsOn", header: t("columns.runsOn"), meta: { className: `${widths.runsOn} h-auto min-h-10 whitespace-normal` }, cell: RunsOnCell },
      ...(withBackups ? [{ id: "lastBackup", header: t("columns.lastBackup"), meta: { className: `${widths.lastBackup} h-auto min-h-10 whitespace-normal` }, cell: LastBackupCell }] : []),
      // Sized for the widest locale's header (ru). Wraps below xl; `h-auto min-h-11`
      // because TableHead fixes 44px.
      { id: "owner", header: t("columns.owner"), meta: { className: `${widths.owner} h-auto min-h-11 whitespace-normal` }, cell: OwnerCell },
      // descFirst: largest and newest first is what people look for.
      { id: "size", header: () => <SortHeader col="directory_size_bytes" descFirst>{t("columns.size")}</SortHeader>, meta: { className: widths.size, sortKey: "directory_size_bytes" }, cell: SizeCell },
      { id: "created", header: () => <SortHeader col="created_at" descFirst>{t("columns.created")}</SortHeader>, meta: { className: widths.created, sortKey: "created_at" }, cell: CreatedCell },
      { id: "actions", header: "", meta: { className: widths.actions }, cell: ActionsCell },
    ],
    // `missingDatabase` is a dependency: attaching a database refreshes the route,
    // and a stale closure would keep the badge.
    [t, missingDatabase, withBackups, widths],
  );

  const filters = <Filters statusOptions={statusOptions} typeOptions={typeOptions} t={t} />;
  const toolbar = (
    <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      {filters}
      {/* Create sits with Refresh in the table's own toolbar (Krishna, 6 Oct). */}
      <div className="flex flex-wrap items-center gap-2"><RefreshButton /><CreateApplicationButton canManage={canManage} /></div>
    </div>
  );

  // No rows and no filters: the server has no sites.
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
              {/* Clears search and both filters, hence "Clear filters". */}
              {tCommon("clearFilters")}
            </Button>
          }
        />
      </ListCard>
    );
  }

  return (
    // Below lg the rows are cards of their own, so the list drops its frame there.
    <ListCard from="lg" toolbar={toolbar} footer={<DataTablePagination meta={meta} />}>
      {/* Cards below lg, the table from lg up. */}
      <div className="lg:hidden"><ApplicationsCards applications={applications} canManage={canManage} canMagicLogin={canMagicLogin} canRemoveSystemUser={canRemoveSystemUser} gitProviders={gitProviders} /></div>
      {/* fixedLayout so the column percentages are obeyed, not treated as hints. */}
      <div className="hidden lg:block"><DataTable columns={columns} data={applications} meta={{ canManage, canMagicLogin, canRemoveSystemUser, backupStanding }} fixedLayout bare roomy /></div>
    </ListCard>
  );
}
