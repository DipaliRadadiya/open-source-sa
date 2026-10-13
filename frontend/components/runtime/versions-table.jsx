"use client";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { SUBGRID } from "@/lib/runtime/version-rows";

// Shared by PHP and Node. Every installed version is one row (columns: `rowGrid`), so default,
// support and usage compare at a glance; rows stack into small cards below 48rem of card width.
export function VersionsCard({ namespace, columns, gridClass, action, footnote, children }) {
  const t = useTranslations(namespace);

  return (
    <Card className="@container/versions gap-0 overflow-hidden pb-0">
      <CardHeader className="pb-4">
        <CardTitle as="h2">{t("table.title")}</CardTitle>
        <CardDescription className="@max-sm/card-header:col-span-full">{t("table.description")}</CardDescription>
        {/* Below a phone's width the button drops under the description instead of squeezing it. */}
        {action ? (
          <CardAction className="@max-sm/card-header:col-start-1 @max-sm/card-header:row-span-1 @max-sm/card-header:row-start-3 @max-sm/card-header:mt-3 @max-sm/card-header:justify-self-start">
            {action}
          </CardAction>
        ) : null}
      </CardHeader>

      {/* One grid for the header and every row (they are its subgrids). */}
      <div className={cn("@3xl/versions:grid @3xl/versions:gap-x-4", gridClass)}>
        {/* Column names for the wide layout only; each cell also names itself for
            narrow screens and screen readers. */}
        <div
          aria-hidden
          className={cn(
            "hidden border-y bg-muted/40 px-4 py-3 text-sm font-medium text-muted-foreground",
            SUBGRID,
          )}
        >
          {columns.map((column, index) => (
            <span key={column} className={index === columns.length - 1 ? "text-right" : undefined}>
              {column}
            </span>
          ))}
        </div>

        <ul aria-label={t("versions.pickerLabel")} className={cn("divide-y border-t @3xl/versions:border-t-0", SUBGRID)}>
          {children}
        </ul>
      </div>

      {footnote ? (
        <p className="border-t bg-muted/20 px-4 py-3 text-xs text-muted-foreground">{footnote}</p>
      ) : null}
    </Card>
  );
}

// A labelled value: the label shows inline when the row stacks, and is read out (but
// not shown) in the wide layout, where the column header carries it.
export function VersionCell({ label, className, children }) {
  return (
    <div className={cn("flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm", className)}>
      <span className="w-24 shrink-0 text-xs text-muted-foreground @3xl/versions:sr-only">{label}</span>
      {/* Beside the label when stacked; wraps inside itself rather than under it. */}
      <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">{children}</span>
    </div>
  );
}

// The API sends a calendar date ("2027-04-30"); shown in the reader's language. UTC, so no
// timezone can move it to the day before.
function readableDate(value, format) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? "");
  if (!match) return value;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  return format.dateTime(date, { dateStyle: "medium", timeZone: "UTC" });
}

// "Supported" plus the date only when the date is news (ending or ended).
export function SupportCell({ version, namespace, lifecycleAvailable, label, quiet = [] }) {
  const t = useTranslations(namespace);
  const format = useFormatter();
  const lifecycle = version.lifecycle;
  const showDate =
    lifecycleAvailable && lifecycle?.eol_date && !quiet.includes(lifecycle?.status);

  return (
    <VersionCell label={label}>
      <span className="flex min-w-0 flex-col items-start gap-1">
        {lifecycleAvailable && lifecycle?.status ? (
          // Wraps inside its column: "Долгосрочная поддержка (Krypton)" ran under the npm cell.
          <LifecycleBadge
            lifecycle={lifecycle}
            namespace={namespace}
            available={lifecycleAvailable}
            className="h-auto max-w-full text-left whitespace-normal"
          />
        ) : (
          <span className="text-muted-foreground">—</span>
        )}
        {showDate ? (
          <span className="text-xs text-muted-foreground">
            {lifecycle.status === "eol"
              ? t("lifecycle.endedOn", { date: readableDate(lifecycle.eol_date, format) })
              : t("lifecycle.endsOn", { date: readableDate(lifecycle.eol_date, format) })}
          </span>
        ) : null}
      </span>
    </VersionCell>
  );
}

// The count answers "can I remove this?"; the names (in a popover, which opens on touch
// too) answer "what breaks?". The API sends at most five and reports the rest.
export function UsedByCell({ version, namespace, label }) {
  const t = useTranslations(namespace);
  const usedBy = version.in_use_by ?? 0;
  const sites = version.sites ?? [];

  return (
    <VersionCell label={label}>
      {usedBy === 0 ? (
        <span className="text-muted-foreground">{t("table.usedByNone")}</span>
      ) : sites.length === 0 ? (
        <span>{t("table.usedByCount", { count: usedBy })}</span>
      ) : (
        <Popover>
          <PopoverTrigger asChild>
            <button
              type="button"
              className="relative z-10 rounded-sm text-left underline decoration-muted-foreground/50 decoration-dotted underline-offset-4 hover:decoration-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {t("table.usedByCount", { count: usedBy })}
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-64 p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">
              {t("table.usedByTitle", { version: version.version })}
            </p>
            <ul className="flex flex-wrap gap-1.5">
              {sites.map((site) => (
                <li key={site} className="rounded-md bg-muted px-2 py-0.5 text-xs">
                  {site}
                </li>
              ))}
              {version.sites_truncated && usedBy > sites.length ? (
                <li className="px-1 py-0.5 text-xs text-muted-foreground">
                  {t("versions.moreCount", { count: usedBy - sites.length })}
                </li>
              ) : null}
            </ul>
          </PopoverContent>
        </Popover>
      )}
    </VersionCell>
  );
}
