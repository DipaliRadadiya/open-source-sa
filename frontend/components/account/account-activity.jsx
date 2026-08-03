"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { DataTable } from "@/components/ui/data-table";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { humanizeActivity, actionBadgeVariant } from "@/lib/activity/labels";

// Backend timestamps may be ISO or MySQL-style ("YYYY-MM-DD HH:mm:ss"); parse
// both, return null if neither is valid so we skip the tooltip instead of
// rendering "Invalid Date".
function toDate(value) {
  if (!value) return null;
  let d = new Date(value);
  if (!Number.isNaN(d.getTime())) return d;
  d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d;
}

/* Cells at module level: flexRender treats a cell function's identity as the
 * component type, so an inline cell remounts on every render of this table. */

function WhenCell({ row }) {
  const format = useFormatter();
  const { created_at, created_at_human } = row.original;
  const label = (
    <span className="whitespace-nowrap tabular-nums text-muted-foreground">
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
  return row.original.type ? (
    <Badge variant="outline" className="font-normal">
      {humanizeActivity(row.original.type)}
    </Badge>
  ) : (
    <span className="text-muted-foreground">—</span>
  );
}

function EventCell({ row }) {
  return (
    <Badge variant={actionBadgeVariant(row.original.action)} className="font-normal">
      {humanizeActivity(row.original.action)}
    </Badge>
  );
}

function DescriptionCell({ row }) {
  return <span>{row.original.description || "—"}</span>;
}

export function AccountActivity({ data }) {
  const t = useTranslations("account");

  const columns = [
    { accessorKey: "created_at_human", header: t("activity.when"), cell: WhenCell },
    { accessorKey: "type", header: t("activity.type"), cell: TypeCell },
    { accessorKey: "action", header: t("activity.event"), cell: EventCell },
    {
      accessorKey: "description",
      header: t("activity.descriptionHeader"),
      cell: DescriptionCell,
    },
  ];

  return (
    <DataTable columns={columns} data={data} emptyMessage={t("activity.empty")} />
  );
}
