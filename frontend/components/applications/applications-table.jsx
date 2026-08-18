"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { formatBytes } from "@/lib/format/bytes";
import { ChevronRight, Globe2, Plus, SearchX } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ApplicationEmptyState } from "@/components/applications/application-empty-state";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { ApplicationsCards } from "@/components/applications/applications-cards";
import {
  ApplicationStatusBadge,
  ApplicationStatusNotes,
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
  const size = formatBytes(row.original.directory_size_bytes, format);

  if (size === null) {
    return <span className="whitespace-nowrap text-muted-foreground">{t("size.notMeasured")}</span>;
  }

  return (
    <span className="whitespace-nowrap">
      <span className="tabular-nums">{size}</span>
      {row.original.directory_size_measured_at_human ? (
        <span className="ml-2 text-xs text-muted-foreground">
          {row.original.directory_size_measured_at_human}
        </span>
      ) : null}
    </span>
  );
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
  return <div className="flex min-w-0 items-center gap-3"><span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"><Globe2 className="size-4" /></span><div className="min-w-0"><div className="flex min-w-0 items-center gap-2"><Link href={`/applications/${row.original.id}`} prefetch={false} className="group inline-flex min-w-0 items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline"><span className="truncate">{row.original.name}</span><ChevronRight className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" /></Link>{/* A copy and the site it copies sit next to each other in this list under near-identical names. Marking the copy is the difference between editing the right site and the wrong one. */}{row.original.is_staging ? <Badge variant="warning" className="shrink-0 font-normal">{t("stagingBadge")}</Badge> : null}</div><p className="truncate font-mono text-xs text-muted-foreground">{row.original.domain}</p></div></div>;
}


function StatusCell({ row }) {
  return (
    <div className="space-y-1">
      <ApplicationStatusBadge application={row.original} />
      <ApplicationStatusNotes application={row.original} />
    </div>
  );
}

function createdTimestamp(value) {
  const match = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/.exec(value ?? "");
  return match ? Date.UTC(match[3], Number(match[2]) - 1, match[1], match[4], match[5], match[6]) : 0;
}

function Filters({ query, setQuery, statusFilter, setStatusFilter, typeFilter, setTypeFilter, statusOptions, typeOptions, t }) {
  return <div className="flex flex-col gap-2 sm:flex-row"><LocalSearchInput value={query} onChange={setQuery} placeholder={t("searchPlaceholder")} /><Select value={statusFilter} onValueChange={setStatusFilter}><SelectTrigger className="w-full sm:w-40"><SelectValue placeholder={t("columns.status")} /></SelectTrigger><SelectContent><SelectItem value="all">{t("columns.status")}</SelectItem>{statusOptions.map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent></Select><Select value={typeFilter} onValueChange={setTypeFilter}><SelectTrigger className="w-full sm:w-44"><SelectValue placeholder={t("columns.type")} /></SelectTrigger><SelectContent><SelectItem value="all">{t("columns.type")}</SelectItem>{typeOptions.map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent></Select></div>;
}

export function ApplicationsTable({ applications = [], canManage = false }) {
  const t = useTranslations("applications");
  const router = useRouter();
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [typeFilter, setTypeFilter] = useState("all");
  const statusOptions = useMemo(() => [...new Map(applications.map((application) => [application.status, application.status_title ?? application.status])).entries()], [applications]);
  const typeOptions = useMemo(() => [...new Map(applications.map((application) => [application.site_type, application.site_type_title ?? application.site_type])).entries()], [applications]);
  const hasWorkingApplication = applications.some((application) => application.status === "pending" || application.status === "provisioning");
  useEffect(() => { if (!hasWorkingApplication) return undefined; const timer = window.setInterval(() => router.refresh(), 4000); return () => window.clearInterval(timer); }, [hasWorkingApplication, router]);
  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    return applications.filter((application) => {
      const matchesSearch = !term || [application.name, application.domain, application.site_type_title, application.system_user?.username].filter(Boolean).some((value) => value.toLowerCase().includes(term));
      return matchesSearch && (statusFilter === "all" || application.status === statusFilter) && (typeFilter === "all" || application.site_type === typeFilter);
    });
  }, [applications, query, statusFilter, typeFilter]);
  const createButton = canManage ? <Button asChild><Link href="/applications/create"><Plus className="size-4" />{t("create")}</Link></Button> : null;
  const columns = useMemo(
    () => [
      { accessorKey: "name", header: t("columns.name"), cell: NameCell, sortingFn: "alphanumeric" },
      { accessorKey: "site_type_title", header: t("columns.type"), cell: TypeCell },
      { accessorKey: "status", header: t("columns.status"), cell: StatusCell },
      { id: "owner", accessorFn: (row) => row.system_user?.username ?? "", header: t("columns.owner"), cell: OwnerCell },
      // -1 for never-measured so sorting groups them at one end instead of
      // mixing them in with genuinely empty sites at 0 bytes.
      { id: "size", accessorFn: (row) => row.directory_size_bytes ?? -1, header: t("columns.size"), cell: SizeCell, sortingFn: "basic" },
      { id: "created", accessorFn: (row) => createdTimestamp(row.created_at), header: t("columns.created"), cell: CreatedCell, sortingFn: "basic" },
      { id: "actions", header: "", cell: ActionsCell },
    ],
    [t],
  );
  const filters = <Filters query={query} setQuery={setQuery} statusFilter={statusFilter} setStatusFilter={setStatusFilter} typeFilter={typeFilter} setTypeFilter={setTypeFilter} statusOptions={statusOptions} typeOptions={typeOptions} t={t} />;
  if (applications.length === 0) return <ApplicationEmptyState canManage={canManage} />;
  if (!filtered.length) return <div className="space-y-4"><div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">{filters}<div className="flex flex-wrap items-center gap-2"><RefreshButton />{createButton}</div></div><EmptyState icon={SearchX} title={t("empty.filteredTitle")} description={t("empty.filteredDescription")} action={<Button variant="outline" onClick={() => { setQuery(""); setStatusFilter("all"); setTypeFilter("all"); }}>{t("empty.clearSearch")}</Button>} /></div>;
  return <div className="space-y-4"><div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">{filters}<div className="flex flex-wrap items-center gap-2"><RefreshButton />{createButton}</div></div><div className="flex flex-wrap gap-2">{statusOptions.map(([status, label]) => <Badge key={status} variant={STATUS_VARIANTS[status] ?? "secondary"} className="font-normal">{label} {applications.filter((application) => application.status === status).length}</Badge>)}</div>{/* Cards below lg, the table from lg up — same rule as services and workers. Six columns cannot fit a phone, and the table quietly hid five of them. */}<div className="lg:hidden"><ApplicationsCards applications={filtered} canManage={canManage} /></div><div className="hidden lg:block"><DataTable columns={columns} data={filtered} sortable defaultSorting={[{ id: "created", desc: true }]} meta={{ canManage }} /></div></div>;
}
