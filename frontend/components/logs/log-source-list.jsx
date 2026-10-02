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
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { LOG_GROUPS } from "@/lib/schemas/log";

/** Known groups first in a fixed order, then anything the API adds later. */
function groupSources(sources) {
  const order = [...LOG_GROUPS, ...new Set(sources.map((s) => s.group))];
  return [...new Set(order)]
    .map((group) => ({ group, items: sources.filter((s) => s.group === group) }))
    .filter((g) => g.items.length > 0);
}

// Unreadable sources stay listed but inert. Below `lg` the rail becomes a select.
export function LogSourceList({ sources, selected, onSelect, now }) {
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
                {/* Same micro-label as the desktop rail. */}
                <SelectLabel className="text-[12px] font-semibold uppercase tracking-wider text-foreground/75">
                  {t.has(`groups.${group}`) ? t(`groups.${group}`) : group}
                </SelectLabel>
                {/* The reason is written on the item so it shows without hover or tap. */}
                {items.map((source) => (
                  <ReasonTooltip
                    key={source.key}
                    reason={source.readable ? null : t("sourceNotReadable")}
                    className="flex"
                  >
                    <SelectItem value={source.key} disabled={!source.readable}>
                      <span className="flex items-center gap-2">
                        {source.label}
                        {!source.readable ? (
                          <>
                            <Lock className="size-3.5" aria-hidden="true" />
                            <span className="text-xs">{t("locked.title")}</span>
                          </>
                        ) : null}
                      </span>
                    </SelectItem>
                  </ReasonTooltip>
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
          // A hairline per group keeps the quiet headings findable.
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
                      active={isRecentlyActive(source.modified, now)}
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

        {/* Summary footer in the otherwise empty bottom of the card. */}
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
        // The file name carries the weight; the group heading stays quiet.
        source.readable
          ? "font-medium text-foreground hover:bg-muted"
          : "cursor-not-allowed text-muted-foreground",
        selected &&
          "bg-primary/10 font-medium text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] shadow-[inset_2px_0_0_0_var(--color-primary)] hover:bg-primary/15 dark:text-primary",
      )}
    >
      {/* Written to in the last few minutes: a dot answers "is this live?". */}
      {active ? (
        <span className="relative flex size-1.5 shrink-0" aria-hidden="true">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-75 motion-reduce:hidden" />
          <span className="relative inline-flex size-1.5 rounded-full bg-success" />
        </span>
      ) : null}
      <span className="min-w-0 flex-1 truncate">{source.label}</span>
      {/* Size shows for locked sources too; it is useful without access. */}
      {size ? (
        <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{size}</span>
      ) : null}
      {/* The lock slot is always reserved so sizes stay aligned as a column. */}
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

  // A disabled control swallows pointer events, so the tooltip hangs off a focusable wrapper.
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
      {/* Above the item, short and narrow so it stays close to its row. */}
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
