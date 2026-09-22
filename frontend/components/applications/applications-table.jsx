"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
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


/* Every cell is defined at module level — flexRender treats a cell function's
 * identity as the component TYPE, so a cell written inline in the columns array
 * is a brand new type on every render and React unmounts it. This list refreshes
 * itself every 4s while any site is provisioning, so an inline actions cell threw
 * away its own state four seconds after you opened the delete dialog. */

/* `block` before `truncate`: on an inline span the ellipsis never appears,
 * because there is no box to overflow. The title carries the whole value —
 * clipping a username without one hides which account a site runs as. */
/*
 * The version only, because the column says PHP. "PHP 8.4" under a "PHP"
 * header is the label twice.
 *
 * An em-dash for the sites this does not apply to — Node, static, anything the
 * API returns `null` for. The same dash `OwnerCell` uses, so a column with
 * nothing in it looks the same everywhere in this table rather than blank in
 * one place and dashed in another. Tabular numerals so the versions line up
 * down the column instead of drifting with digit width.
 */
function PhpCell({ row }) {
  const value = row.original.php_version;
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

/* A scheduled sweep measures every site every minute, so the number here is
 * current without anybody asking for it — see `applications:measure-sizes` in
 * routes/console.php. This cell therefore shows the figure alone; the manual
 * re-measure that used to live in the row menu was doing by hand what the
 * sweep already does each tick.
 *
 * The one state the sweep cannot cover is a site it has not reached yet: one
 * created seconds ago, a server where `application_size.per_run` bounds the
 * pass, or a scheduler that is not running at all. Those show "Not measured"
 * rather than a zero, and the words are the trigger — clicking them tells the
 * three apart, which an empty column never could. */
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


/*
 * `FacetSelect`, not a hand-assembled Select.
 *
 * This screen built its own pair, and they differed from every other filter in
 * the panel in one way that mattered: the clear-the-filter option was labelled
 * with the COLUMN name — "Status", "Type" — which reads as the heading of the
 * list you are looking at, not as a choice you can make. Backups says "All
 * statuses", Cron Jobs says "All users"; only this one asked you to work out
 * that the first item was the way back.
 *
 * The shared control also carries a guard this copy never had: a `?status=junk`
 * URL matched no item and rendered the trigger blank while the filter was still
 * applied server-side.
 */
function Filters({ statusOptions, typeOptions, t }) {
  return (
    <div className="flex flex-col gap-2 sm:flex-row">
      {/* Server-side and debounced. The list pages at ten, so a browser-side
          filter answered "which of these ten" while the reader was asking
          "which of my sites" — and found nothing for anything on page two. */}
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

function ApplicationsList({
  applications = [],
  meta,
  siteTypes = [],
  canManage = false,
  canMagicLogin = false,
  // Ids of sites whose type needs a database and that have none. Empty when
  // the reader cannot see databases, or when the count could not be read.
  missingDatabase = new Set(),
  // `git_account_id` → provider, resolved on the server. Empty when no row
  // needs it, when the reader cannot see the integrations, or when that fetch
  // failed — every one of which means the rows keep the generic git mark.
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
      //
      // The percentages are what stop a long site name taking the table with
      // it. Every cell here already carried `truncate`, and none of it ever
      // fired: in the browser's default `auto` layout a column is as wide as
      // its longest cell, so a 61-character name simply made the Application
      // column 657px and pushed the table 547px past its container — a
      // sideways scrollbar with Size, Created and the row menu off the end of
      // it. Truncation needs a bound to truncate against, and `fixedLayout`
      // below is what gives the columns one. Measured at the four content
      // widths this table can have (704 / 960 / 1120 / 1216px — the shell is a
      // 16rem sidebar plus `max-w-screen-xl p-8`, so the viewport is not the
      // container); below 1024 the cards render instead.
      //
      // Created hides below xl, and the rest re-base to 100% without it. Seven
      // columns do not fit 704px: sharing it evenly truncated Type to "Next…"
      // and Owner to "akaunti…", which is not a narrower column but a column
      // that has stopped saying anything. Created is the one whose absence
      // costs least — it is not actionable, it never changes, and the detail
      // page carries it.
      { accessorKey: "name", header: () => <SortHeader col="name">{t("columns.name")}</SortHeader>, meta: { className: "w-[32%] xl:w-[29%]", sortKey: "name" }, cell: ({ row }) => <NameCell row={row} missingDatabase={missingDatabase.has(row.original.id)} gitProvider={gitProviderFor(row.original, gitProviders)} /> },
      /*
       * PHP stands where Type stood. The logo carries the type now — it is
       * labelled and hoverable — and a version is much shorter than "Craft
       * CMS", so the slot narrows from 15/11 to 9/8 and the surplus goes to
       * Application, which grew a padlock.
       *
       * Not sortable. Sorting sites by framework was not wanted and sorting
       * them by PHP version is a stranger request still; the API also
       * allow-lists sort columns, so offering one it does not accept is a 500
       * rather than a fallback.
       *
       * Totals, because `fixedLayout` SILENTLY squeezes a column when they are
       * wrong rather than erroring:
       *   lg  32 + 8 + 16 + 23 + 14 + 7          = 100
       *   xl  29 + 7 + 14 + 19 + 11 + 14 + 6     = 100
       *
       * Status and Created keep the width they had. Measured against a build
       * of HEAD, narrowing them to pay for System user clipped the
       * "Provisioning" badge in de/fr/ru and the Created header in pt at
       * widths where they used to fit — a rename is not a reason to break a
       * column it never touched. Application pays instead: it truncates by
       * design and 29% is still 329px against a 261px longest cell.
       */
      { id: "php", header: t("columns.php"), meta: { className: "w-[8%] xl:w-[7%]" }, cell: PhpCell },
      { accessorKey: "status", header: () => <SortHeader col="status">{t("columns.status")}</SortHeader>, meta: { className: "w-[16%] xl:w-[14%]", sortKey: "status" }, cell: StatusCell },
      // Not sortable, and deliberately so on the API's side: the owner lives on
      // a relation, so ordering by it would mean a join, and the list can
      // already be searched by username.
      //
      // 23/19, not the 16/13 that fitted "Owner": the header is "System user"
      // now, and measured across all eight locales the widest — Russian's
      // "Системный пользователь" at 213px — needs 19% of the 1134px table. At
      // 13% it was clipped in six of the eight. The surplus comes from Created
      // and PHP, both of which were carrying 60-80px more than their longest
      // locale asks for.
      //
      // `whitespace-normal` on top, because 19% is only 213px from 1280px up.
      // Below that the table has 960px or 704px to divide and the sum of every
      // column's widest locale is 1064px, so no split exists that keeps this
      // header on one line — measured, not guessed. A label that wraps to two
      // lines still says what it says; `truncate` would turn it into
      // "Системный польз…", and the cell under it is a username nobody can
      // infer. `h-auto min-h-11` because TableHead fixes the height at 44px,
      // which would clip the second line instead.
      { id: "owner", header: t("columns.owner"), meta: { className: "w-[23%] xl:w-[19%] h-auto min-h-11 whitespace-normal" }, cell: OwnerCell },
      // descFirst on both: nobody opens a size column to find their smallest
      // application, or a date column to find the oldest.
      { id: "size", header: () => <SortHeader col="directory_size_bytes" descFirst>{t("columns.size")}</SortHeader>, meta: { className: "w-[14%] xl:w-[11%]", sortKey: "directory_size_bytes" }, cell: SizeCell },
      { id: "created", header: () => <SortHeader col="created_at" descFirst>{t("columns.created")}</SortHeader>, meta: { className: "hidden xl:table-cell xl:w-[14%]", sortKey: "created_at" }, cell: CreatedCell },
      { id: "actions", header: "", meta: { className: "w-[7%] xl:w-[6%]" }, cell: ActionsCell },
    ],
    // `missingDatabase` belongs here: attaching a database refreshes the route,
    // and without it the columns keep the closure from the previous render and
    // the badge stays on a site that now has one.
    [t, missingDatabase],
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
  // No rows AND nothing asked for: this server genuinely has no sites.
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
              {/* "Clear filters", not "Clear search". This button clears all
                  three — and this is the only list with more than a search
                  box, so filtering by Status and being offered "Clear search"
                  names a control the reader never touched. */}
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
      {/* Cards below lg, the table from lg up — same rule as services and
          workers. Six columns cannot fit a phone, and the table quietly hid
          five of them. */}
      <div className="lg:hidden"><ApplicationsCards applications={applications} canManage={canManage} canMagicLogin={canMagicLogin} gitProviders={gitProviders} /></div>
      {/* fixedLayout, so the percentages above are obeyed instead of treated as
          hints the browser is free to ignore — the same fix services-table
          needed, for the same reason. */}
      <div className="hidden lg:block"><DataTable columns={columns} data={applications} meta={{ canManage, canMagicLogin }} fixedLayout /></div>
      <DataTablePagination meta={meta} />
    </div>
  );
}
