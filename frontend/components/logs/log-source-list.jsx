"use client";

import { useFormatter, useTranslations } from "next-intl";
import { Lock } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { formatBytes } from "@/lib/format/bytes";
import { isRecentlyActive, parseModified } from "@/lib/logs/recent";
import { GROUP_META, FALLBACK_GROUP } from "@/lib/logs/groups";
import { LOG_GROUPS } from "@/lib/schemas/log";

/** Known groups first in a fixed order, then anything the API adds later. */
function groupSources(sources) {
  const order = [...LOG_GROUPS, ...new Set(sources.map((s) => s.group))];
  return [...new Set(order)]
    .map((group) => ({ group, items: sources.filter((s) => s.group === group) }))
    .filter((g) => g.items.length > 0);
}

/**
 * Source picker. Unreadable sources stay listed — knowing a log exists on the
 * box is useful even when the panel can't open it yet — but they're clearly
 * inert, and each says why rather than just refusing to react.
 *
 * Below `lg` the rail becomes a single select: stacked above the console it
 * would push the actual log a full screen down on a phone.
 */
export function LogSourceList({ sources, selected, onSelect }) {
  const t = useTranslations("logs");
  const format = useFormatter();
  const groups = groupSources(sources);
  const lockedCount = sources.filter((s) => !s.readable).length;

  return (
    <>
      <div className="lg:hidden">
        <Select value={selected ?? undefined} onValueChange={onSelect}>
          <SelectTrigger
            aria-label={t("sourcesLabel")}
            className="w-full"
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent position="popper">
            {groups.map(({ group, items }) => (
              <SelectGroup key={group}>
                {/* Same micro-label as the desktop rail — one list, one style. */}
                <SelectLabel className="text-[12px] font-semibold uppercase tracking-wider text-foreground/75">
                  {t.has(`groups.${group}`) ? t(`groups.${group}`) : group}
                </SelectLabel>
                {items.map((source) => (
                  <SelectItem key={source.key} value={source.key} disabled={!source.readable}>
                    <span className="flex items-center gap-2">
                      {source.label}
                      {!source.readable ? <Lock className="size-3.5" /> : null}
                    </span>
                  </SelectItem>
                ))}
              </SelectGroup>
            ))}
          </SelectContent>
        </Select>
      </div>

      <nav
        aria-label={t("sourcesLabel")}
        className="hidden h-full flex-col rounded-xl border bg-card shadow-sm lg:flex"
      >
        <div className="min-h-0 flex-1 divide-y divide-border/50 overflow-y-auto p-3">
        {groups.map(({ group, items }) => {
          const meta = GROUP_META[group] ?? FALLBACK_GROUP;
          const Icon = meta.icon;
          // A hairline per group (divide-y on the parent). With the headings
          // deliberately quiet, the separator is what makes the groups
          // findable — otherwise the rail reads as one undifferentiated column.
          return (
            <section key={group} className="space-y-1 py-3 first:pt-0 last:pb-0">
              <h3 className="flex items-center gap-2 px-2.5 text-[12px] font-semibold uppercase tracking-wider text-foreground/75">
                <Icon className="size-3 shrink-0" />
                {t.has(`groups.${group}`) ? t(`groups.${group}`) : group}
              </h3>
              <ul>
                {items.map((source) => (
                  <li key={source.key}>
                    <SourceButton
                      source={source}
                      selected={source.key === selected}
                      onSelect={onSelect}
                      size={formatBytes(source.size, format)}
                      modified={parseModified(source.modified)}
                      active={isRecentlyActive(source.modified)}
                      format={format}
                      t={t}
                    />
                  </li>
                ))}
              </ul>
            </section>
          );
        })}
        </div>

        {/* The empty half of a full-height card is dead space; this is the one
            fact the page opens with, kept where the eye lands last. */}
        <div className="border-t px-4 py-2.5 text-xs text-muted-foreground">
          {lockedCount > 0
            ? t("railSummaryLocked", { total: sources.length, locked: lockedCount })
            : t("railSummary", { total: sources.length })}
        </div>
      </nav>
    </>
  );
}

function SourceButton({ source, selected, onSelect, size, modified, active, format, t }) {
  const writtenAt = modified
    ? format.dateTime(modified, { dateStyle: "medium", timeStyle: "short" })
    : null;

  const button = (
    <button
      type="button"
      disabled={!source.readable}
      aria-current={selected ? "true" : undefined}
      onClick={() => onSelect(source.key)}
      className={cn(
        "flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] transition-colors",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        // The file name is what you're scanning for, so it carries the weight —
        // the group heading above it stays quiet.
        source.readable
          ? "font-medium text-foreground hover:bg-muted"
          : "cursor-not-allowed text-muted-foreground",
        selected &&
          "bg-primary/10 font-medium text-primary shadow-[inset_2px_0_0_0_var(--color-primary)] hover:bg-primary/15",
      )}
    >
      {/* Written to in the last few minutes. A dot answers "is this log live?"
          at a glance, where a relative timestamp would eat a quarter of the
          row to answer it worse. */}
      {active ? (
        <span className="relative flex size-1.5 shrink-0" aria-hidden="true">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-75 motion-reduce:hidden" />
          <span className="relative inline-flex size-1.5 rounded-full bg-success" />
        </span>
      ) : null}
      <span className="min-w-0 flex-1 truncate">{source.label}</span>
      {/* Size shows for locked sources too: "syslog is 10 MB" is useful even
          when you can't open it, and dropping it made those rows look like
          they held less information rather than less access. */}
      {size ? (
        <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{size}</span>
      ) : null}
      {/* The slot is always there, empty when the log is readable: sized in
          tabular-nums to read as a column, the numbers can't be allowed to
          shift by a lock's width from one row to the next. */}
      <span className="flex size-3.5 shrink-0 items-center justify-center">
        {!source.readable ? <Lock className="size-3.5 text-muted-foreground/70" /> : null}
      </span>
    </button>
  );

  if (source.readable) {
    if (!writtenAt) return button;
    return (
      <Tooltip>
        <TooltipTrigger asChild>{button}</TooltipTrigger>
        <TooltipContent>{t("lastWritten", { time: writtenAt })}</TooltipContent>
      </Tooltip>
    );
  }

  // A disabled control swallows pointer events, so the tooltip needs a wrapper
  // to hang off — otherwise the one item that most needs an explanation is the
  // one that can't show it.
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span
          tabIndex={0}
          className="block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          {button}
        </span>
      </TooltipTrigger>
      {/* Above the item, over the light rail rather than the dark console —
          kept short and narrow so the box stays close to its row. */}
      <TooltipContent
        side="top"
        sideOffset={6}
        align="start"
        collisionPadding={12}
        className="max-w-56 flex-col items-start gap-0.5 py-2"
      >
        <span className="flex items-center gap-1.5 font-medium">
          <Lock className="size-3.5" />
          {t("lockedShort")}
        </span>
        <span className="text-xs leading-relaxed opacity-90">{t("locked.body")}</span>
      </TooltipContent>
    </Tooltip>
  );
}
