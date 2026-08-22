"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { formatBytes } from "@/lib/format/bytes";
import { ChevronRight, Globe2, Plus, SearchX } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { VisitSiteLink } from "@/components/applications/visit-site-link";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { PageOutOfRange } from "@/components/data-table/page-out-of-range";
import { useSetQuery } from "@/hooks/use-set-query";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { SortHeader } from "@/components/data-table/sort-header";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { measureApplicationSize } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ApplicationEmptyState } from "@/components/applications/application-empty-state";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { ApplicationsCards } from "@/components/applications/applications-cards";
import {
  ApplicationStatusBadge,
  ApplicationStatusNotes,
  APPLICATION_STATUSES,
  STATUS_VARIANTS,
} from "@/components/applications/application-status-badge";


/* Every cell is defined at module level — flexRender treats a cell function's
 * identity as the component TYPE, so a cell written inline in the columns array
 * is a brand new type on every render and React unmounts it. This list refreshes
 * itself every 4s while any site is provisioning, so an inline actions cell threw
 * away its own state four seconds after you opened the delete dialog. */

function TypeCell({ row }) {
  return (
    <span className="text-muted-foreground">
      {row.original.site_type_title ?? row.original.site_type}
    </span>
  );
}

function OwnerCell({ row }) {
  return (
    <span className="font-mono text-xs text-muted-foreground">
      {row.original.system_user?.username ?? "—"}
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

/* Nothing measures a site's size on a timer — `du` walks every inode, so it is
 * counted after a deploy and when somebody asks, never on a schedule. A number
 * with no date therefore reads as current when it may be weeks old, which is
 * why the API sends the measurement time alongside it and this cell shows it.
 * A site nobody has measured says so rather than showing a zero. */
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
      // Throttled at 10/min, and it refuses outright when the site has no
      // directory — both are real answers worth passing on verbatim.
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  // Never measured: the words themselves are the trigger.
  //
  // A button here was a button on every row — ten of them down one column,
  // shouting over the numbers the column exists to show. And the re-measure
  // action already has a home: the row's ⋯ menu, where every other per-row
  // action in this table lives. So the cell adds no chrome at all; the reader
  // clicks the only thing in it that is already about the missing number.
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

  // The number alone. When it was measured used to sit beside it, and on a
  // freshly measured site that read "10 seconds ago" against every row — a
  // second value competing with the one the column is named for, and the least
  // interesting reading of it at exactly the moment it appears.
  return <span className="whitespace-nowrap tabular-nums">{size}</span>;
}

function ActionsCell({ row, table }) {
  return (
    <ApplicationRowActions
      application={row.original}
      canManage={table.options.meta?.canManage ?? false}
    />
  );
}

function NameCell({ row }) {
  const t = useTranslations("applications");
  return <div className="flex min-w-0 items-center gap-3"><span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"><Globe2 className="size-4" /></span><div className="min-w-0"><div className="flex min-w-0 items-center gap-2"><Link href={`/applications/${row.original.id}`} prefetch={false} className="group inline-flex min-w-0 items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline"><span className="truncate">{row.original.name}</span><ChevronRight className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" /></Link>{/* A copy and the site it copies sit next to each other in this list under near-identical names. Marking the copy is the difference between editing the right site and the wrong one. */}{row.original.is_staging ? <Badge variant="warning" className="shrink-0 font-normal">{t("stagingBadge")}</Badge> : null}</div><div className="flex min-w-0 items-center gap-1"><p className="truncate font-mono text-xs text-muted-foreground">{row.original.domain}</p>{row.original.status === "active" && row.original.url ? <VisitSiteLink href={row.original.url} label={t("actions.visitNamed", { domain: row.original.domain })} className="size-5" /> : null}</div></div></div>;
}


function StatusCell({ row }) {
  return (
    <div className="space-y-1">
      <ApplicationStatusBadge application={row.original} />
      <ApplicationStatusNotes application={row.original} />
    </div>
  );
}


function Filters({ statusOptions, typeOptions, t }) {
  const setQuery = useSetQuery();
  const searchParams = useSearchParams();
  const status = searchParams.get("status") ?? "all";
  const siteType = searchParams.get("site_type") ?? "all";

  return (
    <div className="flex flex-col gap-2 sm:flex-row">
      {/* Server-side and debounced. The list pages at ten, so a browser-side
          filter answered "which of these ten" while the reader was asking
          "which of my sites" — and found nothing for anything on page two. */}
      <SearchInput placeholder={t("searchPlaceholder")} />
      <Select
        value={status}
        onValueChange={(v) => setQuery({ status: v === "all" ? undefined : v }, { resetPage: true })}
      >
        <SelectTrigger className="w-full sm:w-40">
          <SelectValue placeholder={t("columns.status")} />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{t("columns.status")}</SelectItem>
          {statusOptions.map(([value, label]) => (
            <SelectItem key={value} value={value}>{label}</SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Select
        value={siteType}
        onValueChange={(v) => setQuery({ site_type: v === "all" ? undefined : v }, { resetPage: true })}
      >
        <SelectTrigger className="w-full sm:w-44">
          <SelectValue placeholder={t("columns.type")} />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{t("columns.type")}</SelectItem>
          {typeOptions.map(([value, label]) => (
            <SelectItem key={value} value={value}>{label}</SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

/**
 * The applications list.
 *
 * Search, filters, sort and paging all live in the URL and are answered by the
 * API. They used to be React state over the whole table, which worked only
 * while the whole table arrived in one response — once the backend began paging
 * at ten, every one of them silently operated on the first page alone.
 *
 * `siteTypes` comes from `GET /site-types` rather than from the rows on screen:
 * options derived from the current page can only offer the types that happen to
 * be on it, so filtering to a type would become impossible as soon as its sites
 * fell off page one.
 */
export function ApplicationsTable(props) {
  // One transition shared by search, both filters and the pager — that shared
  // signal is what puts a spinner in the search box and dims the table while
  // the server answers, instead of the page appearing frozen.
  return (
    <NavTransitionProvider>
      <ApplicationsList {...props} />
    </NavTransitionProvider>
  );
}

function ApplicationsList({ applications = [], meta, siteTypes = [], canManage = false }) {
  const t = useTranslations("applications");
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

  const createButton = canManage ? <Button asChild><Link href="/applications/create"><Plus className="size-4" />{t("create")}</Link></Button> : null;
  const columns = useMemo(
    () => [
      // `col` is the API's own sort key, from the whitelist on
      // IndexApplicationsRequest. Anything outside it is a 422 rather than an
      // ignored parameter — which is the right call on their side: a sort that
      // silently does nothing looks exactly like one that works.
      //
      // The columns used to carry TanStack's `sortingFn` and accessors that
      // existed only to sort — including one returning -1 for never-measured
      // sites. All of it was dead once the list began paging: DataTable is only
      // handed one page, so sorting here reordered ten rows and presented that
      // as the order of the list. The server has the whole set, and pins
      // never-measured to the small end itself.
      { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, cell: NameCell },
      { accessorKey: "site_type_title", header: () => <SortHeader col="site_type">{t("columns.type")}</SortHeader>, cell: TypeCell },
      { accessorKey: "status", header: () => <SortHeader col="status">{t("columns.status")}</SortHeader>, cell: StatusCell },
      // Not sortable, and deliberately so on the API's side: the owner lives on
      // a relation, so ordering by it would mean a join, and the list can
      // already be searched by username.
      { id: "owner", header: t("columns.owner"), cell: OwnerCell },
      // descFirst on both: nobody opens a size column to find their smallest
      // site, or a date column to find the oldest.
      { id: "size", header: () => <SortHeader col="directory_size_bytes" descFirst>{t("columns.size")}</SortHeader>, cell: SizeCell },
      { id: "created", header: () => <SortHeader col="created_at" descFirst>{t("columns.created")}</SortHeader>, cell: CreatedCell },
      { id: "actions", header: "", cell: ActionsCell },
    ],
    [t],
  );

  const filters = <Filters statusOptions={statusOptions} typeOptions={typeOptions} t={t} />;
  const toolbar = (
    <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      {filters}
      <div className="flex flex-wrap items-center gap-2"><RefreshButton />{createButton}</div>
    </div>
  );

  // Past the last page. Distinct from both other empties: the server has sites,
  // this page just is not one of them — and the pager only renders when there
  // are rows, so without this the screen that says nothing is here also removes
  // the control that would take you back.
  if (!applications.length && (meta?.current_page ?? 1) > 1) {
    return (
      <div className="space-y-4">
        {toolbar}
        <PageOutOfRange lastPage={meta?.last_page ?? 1} />
      </div>
    );
  }

  // No rows AND nothing asked for: this server genuinely has no sites.
  if (!applications.length && !filtering) return <ApplicationEmptyState canManage={canManage} />;

  if (!applications.length) {
    return (
      <div className="space-y-4">
        {toolbar}
        <EmptyState
          icon={SearchX}
          title={t("empty.filteredTitle")}
          description={t("empty.filteredDescription")}
          action={
            <Button
              variant="outline"
              onClick={() => setQuery({ search: undefined, status: undefined, site_type: undefined }, { resetPage: true })}
            >
              {t("empty.clearSearch")}
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {toolbar}
      {/* Cards below lg, the table from lg up — same rule as services and
          workers. Six columns cannot fit a phone, and the table quietly hid
          five of them. */}
      <div className="lg:hidden"><ApplicationsCards applications={applications} canManage={canManage} /></div>
      <div className="hidden lg:block"><DataTable columns={columns} data={applications} meta={{ canManage }} /></div>
      <DataTablePagination meta={meta} />
    </div>
  );
}
