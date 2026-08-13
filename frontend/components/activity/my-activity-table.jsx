"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { DataTable } from "@/components/ui/data-table";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";
import { humanizeActivity, actionBadgeVariant, typeBadgeClass } from "@/lib/activity/labels";

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
  const { type } = row.original;
  if (!type) return <span className="text-muted-foreground">—</span>;
  // Colour by family (people / security / runtimes / sites / housekeeping) so a
  // long page can be scanned without reading every word.
  return (
    <Badge variant="outline" className={cn("font-normal whitespace-nowrap", typeBadgeClass(type))}>
      {humanizeActivity(type)}
    </Badge>
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

/**
 * The caller's own activity for one scope, fixed by the page.
 *
 * There is no "who" column: every row is you. That is the whole difference from
 * the admin log, and the reason the page says so in its subtitle rather than
 * leaving people to infer it from a missing column.
 *
 * No scope column either — each page fixes its own scope, so a column repeating
 * "Server" on every row would be a constant.
 */
export function MyActivityTable({ data, emptyMessage }) {
  const t = useTranslations("activity");

  // Type and Event are shorthand for the description — "Php" + "Install Started"
  // against "Started installing PHP 8.5". A phone has room for one of the two,
  // and the sentence is the one worth keeping; without this it is the column
  // that scrolls out of sight.
  const columns = [
    { accessorKey: "created_at_human", header: t("table.when"), cell: WhenCell },
    {
      accessorKey: "type",
      header: t("table.type"),
      cell: TypeCell,
      meta: { className: "hidden md:table-cell" },
    },
    {
      accessorKey: "action",
      header: t("table.event"),
      cell: EventCell,
      meta: { className: "hidden md:table-cell" },
    },
    {
      accessorKey: "description",
      header: t("table.description"),
      cell: DescriptionCell,
      // TableCell is whitespace-nowrap for everything, which suits short values
      // and ruins this one: at 320 the sentence ran straight off the card with
      // no scrollbar to suggest it. A log line is the one cell that should wrap.
      meta: { className: "whitespace-normal" },
    },
  ];

  return <DataTable columns={columns} data={data} emptyMessage={emptyMessage} />;
}
