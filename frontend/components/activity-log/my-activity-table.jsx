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
import { HoverHint } from "@/components/ui/hover-hint";

function toDate(value) {
  if (!value) return null;
  let d = new Date(value);
  if (!Number.isNaN(d.getTime())) return d;
  d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d;
}

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
    <HoverHint content={`@${u.username}`}><span className="block truncate">@{u.username}</span></HoverHint>
  ) : (
    <span className="text-muted-foreground">{t("system")}</span>
  );
}

const wrap = (label) => function WrappingHeader() {
  return <span className="whitespace-normal">{label}</span>;
};

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

export function MyActivityTable({ data, emptyMessage, hasFilters = false, showUser = false }) {
  const t = useTranslations("activity");

  const columns = [
    { accessorKey: "created_at_human", header: wrap(t("table.when")), cell: WhenCell, meta: { className: "hidden w-44 sm:table-cell" } },
    ...(showUser ? [{ id: "user", header: t("columns.user"), cell: UserCell, meta: { className: "hidden w-44 sm:table-cell" } }] : []),
    {
      accessorKey: "description",
      header: wrap(t("table.description")),
      cell: DescriptionCell,
      // TableCell is nowrap by default.
      meta: { className: "whitespace-normal", showUser },
    },
    {
      accessorKey: "type",
      header: t("table.type"),
      cell: TypeCell,
      meta: { className: "hidden w-68 xl:table-cell" },
    },
  ];

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

  return <DataTable bare columns={columns} data={data} emptyMessage={emptyMessage} fixedLayout />;
}
