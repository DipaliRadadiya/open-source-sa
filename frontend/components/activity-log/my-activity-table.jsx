"use client";

import { useTranslations, useFormatter } from "next-intl";
import { SearchX, History } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";
import { Badge } from "@/components/ui/badge";
import { DataTable } from "@/components/ui/data-table";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";
import { typeBadgeClass, typeLabel } from "@/lib/activity-log/labels";

// API timestamps may be ISO or MySQL-style ("YYYY-MM-DD HH:mm:ss"); null when
// invalid so the tooltip is skipped instead of showing "Invalid Date".
function toDate(value) {
  if (!value) return null;
  let d = new Date(value);
  if (!Number.isNaN(d.getTime())) return d;
  d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d;
}

/* Cells at module level: flexRender treats a cell function's identity as the
 * component type, so an inline cell remounts on every render. */

function WhenCell({ row }) {
  const format = useFormatter();
  const { created_at, created_at_human } = row.original;
  const label = (
    <span className="tabular-nums whitespace-nowrap text-muted-foreground">
      {created_at_human}
    </span>
  );
  const date = toDate(created_at);
  if (!date) return label;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="cursor-default">{label}</span>
      </TooltipTrigger>
      <TooltipContent>
        {format.dateTime(date, { dateStyle: "medium", timeStyle: "short" })}
      </TooltipContent>
    </Tooltip>
  );
}

function TypeCell({ row }) {
  const t = useTranslations("activity");
  const { type } = row.original;
  if (!type) return <span className="text-muted-foreground">—</span>;
  // Coloured by family so a long page can be scanned.
  return (
    <Badge variant="outline" className={cn("font-normal whitespace-nowrap", typeBadgeClass(type))}>
      {typeLabel(t, type)}
    </Badge>
  );
}

function UserCell({ row }) {
  const t = useTranslations("activity");
  const u = row.original.user;
  return u ? (
    <span className="whitespace-nowrap">@{u.username}</span>
  ) : (
    <span className="text-muted-foreground">{t("system")}</span>
  );
}

const wrap = (label) => function WrappingHeader() {
  return <span className="whitespace-normal">{label}</span>;
};

// Below sm the When (and User) columns are hidden and ride under the description:
// three columns at 390px wrapped "14 hours ago" onto three lines.
function DescriptionCell({ row, column }) {
  const t = useTranslations("activity");
  const { description, user, created_at_human } = row.original;
  const who = column.columnDef.meta?.showUser ? (user ? `@${user.username}` : t("system")) : null;
  return (
    <>
      <span>{description || "—"}</span>
      <span className="mt-0.5 block text-xs text-muted-foreground sm:hidden">
        {who ? `${who} · ` : null}
        {created_at_human}
      </span>
    </>
  );
}

// `showUser` adds the "who" column for the server log, which spans all users.
export function MyActivityTable({ data, emptyMessage, hasFilters = false, showUser = false }) {
  const t = useTranslations("activity");

  // Type is hidden below md: it is shorthand for the description, which is the
  // column worth keeping on a phone.
  const columns = [
    // Headers may wrap so long locales fit on a phone.
    { accessorKey: "created_at_human", header: wrap(t("table.when")), cell: WhenCell, meta: { className: "hidden sm:table-cell" } },
    ...(showUser ? [{ id: "user", header: t("columns.user"), cell: UserCell, meta: { className: "hidden sm:table-cell" } }] : []),
    {
      accessorKey: "type",
      header: t("table.type"),
      cell: TypeCell,
      meta: { className: "hidden md:table-cell" },
    },
    {
      accessorKey: "description",
      header: wrap(t("table.description")),
      cell: DescriptionCell,
      // TableCell is nowrap by default; the description must wrap.
      meta: { className: "whitespace-normal", showUser },
    },
  ];

  // A filtered-empty table offers a way to clear the filters (same strings as
  // the admin table).
  if (data.length === 0 && hasFilters) {
    return (
      <EmptyState
        icon={SearchX}
        subject={History}
        title={t("empty.filteredTitle")}
        description={t("empty.filteredDesc")}
        action={<ClearFiltersButton keys={["search", "type", "action"]} label={t("empty.clear")} />}
      />
    );
  }

  return <DataTable bare columns={columns} data={data} emptyMessage={emptyMessage} />;
}
