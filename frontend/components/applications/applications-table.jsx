"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { formatBytes } from "@/lib/format/bytes";
import { ChevronRight, Plus, SearchX } from "lucide-react";
import { TlsMark, isServedOverTls } from "@/components/applications/tls-mark";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { Badge } from "@/components/ui/badge";
import { VisitSiteLink } from "@/components/applications/visit-site-link";
import { Button } from "@/components/ui/button";
import { phpVersionShown } from "@/lib/applications/php-version-shown";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { FacetSelect } from "@/components/data-table/facet-select";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { useSetQuery } from "@/hooks/use-set-query";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { SortHeader } from "@/components/data-table/sort-header";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { measureApplicationSize } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ApplicationEmptyState } from "@/components/applications/application-empty-state";
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
function PhpCell({ row }) {
  const value = phpVersionShown(row.original);
  return (
    <span className="block truncate tabular-nums text-muted-foreground" title={value ?? undefined}>
      {value ?? "—"}
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
    />
  );
}

function NameCell({ row, missingDatabase = false, gitProvider = null }) {
  const t = useTranslations("applications");
  return <div className="flex min-w-0 items-center gap-3"><SiteTypeLogo name={row.original.site_type} provider={gitProvider} label={row.original.site_type_title ?? row.original.site_type} /><div className="min-w-0"><div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1"><Link href={`/applications/${row.original.id}`} prefetch={false} className="group inline-flex min-w-0 items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline"><span className="truncate" title={row.original.name}>{row.original.name}</span><ChevronRight className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" /></Link>{/* A copy and the site it copies sit next to each other in this list under near-identical names. Marking the copy is the difference between editing the right site and the wrong one. */}{row.original.is_staging ? <Badge variant="warning" className="shrink-0 font-normal">{t("stagingBadge")}</Badge> : null}{/* Only for a site type that needs a database and has none: its backups will not contain one, and nothing else in this list would say so. */}{missingDatabase ? <Badge variant="warning" className="shrink-0 font-normal">{t("noDatabaseBadge")}</Badge> : null}</div><div className="flex min-w-0 items-center gap-1">{/* The padlock goes beside the DOMAIN, not in a column of its own: TLS is a property of the address, which is the convention every browser already taught people, and `url` only ever describes this one domain. It also costs no width in a table that is already at 100%. */}<TlsMark application={row.original} label={isServedOverTls(row.original) ? t("domains.secured") : t("domains.noCertificate")} /><DomainText domain={row.original.domain} className="font-mono text-xs text-muted-foreground" />{row.original.status === "active" && row.original.url ? <VisitSiteLink href={row.original.url} label={t("actions.visitNamed", { domain: row.original.domain })} className="size-5" /> : null}</div></div></div>;
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
        options={statusOptions.map(([value, label]) => ({ value, label }))}
        className="w-full sm:w-40"
      />
      <FacetSelect
        paramKey="site_type"
        allLabel={t("filters.allTypes")}
        options={typeOptions.map(([value, label]) => ({ value, label }))}
        className="w-full sm:w-44"
      />
    </div>
  );
}

// `siteTypes` comes from `GET /site-types`, not the visible rows, so every type stays filterable.
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
  // Ids of sites whose type needs a database and that have none. Empty when
  // the reader cannot see databases, or when the count could not be read.
  missingDatabase = new Set(),
  // `git_account_id` → provider, resolved on the server. Empty (generic git mark)
  // when not needed, not permitted, or the fetch failed.
  gitProviders = new Map(),
}) {
  const t = useTranslations("applications");
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

  // Shown disabled to viewers, with the reason, like other pages' primary action.
  const createButton = canManage ? (
    <Button asChild><Link href="/applications/create"><Plus className="size-4" />{t("create")}</Link></Button>
  ) : (
    <ReasonTooltip reason={t("noPermission")}>
      <Button disabled><Plus className="size-4" />{t("create")}</Button>
    </ReasonTooltip>
  );
  const columns = useMemo(
    () => [
      // `col` must be on the API's sort whitelist (else 422). Percentages plus
      // `fixedLayout` bound the columns so `truncate` works.
      { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, meta: { className: "w-[32%] xl:w-[29%]", sortKey: "name" }, cell: ({ row }) => <NameCell row={row} missingDatabase={missingDatabase.has(row.original.id)} gitProvider={gitProviderFor(row.original, gitProviders)} /> },
      // Widths must total 100 at lg (32+8+16+23+14+7) and xl (29+7+14+19+11+14+6),
      // or `fixedLayout` squeezes a column.
      { id: "php", header: t("columns.php"), meta: { className: "w-[8%] xl:w-[7%]" }, cell: PhpCell },
      { accessorKey: "status", header: () => <SortHeader col="status">{t("columns.status")}</SortHeader>, meta: { className: "w-[16%] xl:w-[14%]", sortKey: "status" }, cell: StatusCell },
      // Sized for the widest locale's header (ru). Wraps below xl; `h-auto min-h-11`
      // because TableHead fixes 44px.
      { id: "owner", header: t("columns.owner"), meta: { className: "w-[23%] xl:w-[19%] h-auto min-h-11 whitespace-normal" }, cell: OwnerCell },
      // descFirst: largest and newest first is what people look for.
      { id: "size", header: () => <SortHeader col="directory_size_bytes" descFirst>{t("columns.size")}</SortHeader>, meta: { className: "w-[14%] xl:w-[11%]", sortKey: "directory_size_bytes" }, cell: SizeCell },
      { id: "created", header: () => <SortHeader col="created_at" descFirst>{t("columns.created")}</SortHeader>, meta: { className: "hidden xl:table-cell xl:w-[14%]", sortKey: "created_at" }, cell: CreatedCell },
      { id: "actions", header: "", meta: { className: "w-[7%] xl:w-[6%]" }, cell: ActionsCell },
    ],
    // `missingDatabase` is a dependency: attaching a database refreshes the route,
    // and a stale closure would keep the badge.
    [t, missingDatabase],
  );

  const filters = <Filters statusOptions={statusOptions} typeOptions={typeOptions} t={t} />;
  const toolbar = (
    <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      {filters}
      <div className="flex flex-wrap items-center gap-2"><RefreshButton />{createButton}</div>
    </div>
  );

  // No rows and no filters: the server has no sites.
  if (!applications.length && !filtering) return <ApplicationEmptyState canManage={canManage} />;

  if (!applications.length) {
    return (
      <div className="space-y-4">
        {toolbar}
        <EmptyState
          icon={SearchX}
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
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {toolbar}
      {/* Cards below lg, the table from lg up. */}
      <div className="lg:hidden"><ApplicationsCards applications={applications} canManage={canManage} canMagicLogin={canMagicLogin} gitProviders={gitProviders} /></div>
      {/* fixedLayout so the column percentages are obeyed, not treated as hints. */}
      <div className="hidden lg:block"><DataTable columns={columns} data={applications} meta={{ canManage, canMagicLogin }} fixedLayout /></div>
      <DataTablePagination meta={meta} />
    </div>
  );
}
