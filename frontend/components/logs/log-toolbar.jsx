import { useState } from "react";
import { useTranslations } from "next-intl";
import {
  ArrowDownNarrowWide,
  ArrowUpNarrowWide,
  RotateCw,
  Download,
  Eraser,
  WrapText,
  Radio,
  Loader2,
  Copy,
  X,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { filterToggleClass } from "@/lib/theme/filter-toggle";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { LINE_OPTIONS, MAX_LINES, MIN_LINES, normalizeLineCount } from "@/lib/schemas/log";
import { SEVERITY_FILTERS } from "@/lib/logs/severity";
import { toast } from "sonner";

// Not a line count, so it can never collide with one.
const CUSTOM_LINES = "custom";

// The line under the title reports the result only when a filter narrowed it or the file is short.
export function LogToolbar({
  label,
  shown,
  loaded,
  wholeFile,
  term,
  onTermChange,
  severity,
  onSeverityChange,
  lines,
  onLinesChange,
  follow,
  onFollowChange,
  wrap,
  onWrapChange,
  newestFirst,
  onNewestFirstChange,
  onReload,
  onCopyVisible,
  downloadUrl,
  // App logs have no download endpoint, so the action is hidden there.
  showDownload = true,
  downloadReason = null,
  followHint = null,
  // Emptying the log; null when unavailable or without `manage` (hidden, not disabled).
  onClear = null,
  clearReason = null,
  clearing = false,
  busy,
  disabled,
  // After a failed read, Reload stays usable while other controls are disabled.
  reloadable = false,
  // Why Reload is off: the log is locked or gone.
  reloadReason = null,
  searchRef,
  tailState = "idle",
  onResume,
}) {
  const t = useTranslations("logs");
  // Swaps the preset selector for a number field, and back once applied.
  const [customLines, setCustomLines] = useState(false);

  return (
    // Two rows: one would squeeze the heading to an ellipsis.
    <div className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3">
      {/* Wraps the tail pill below rather than squeezing the heading: min-w-48, not
          min-w-0 (ru "Paused while filtering" left one word per line at 390). */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div className="min-w-48 flex-1">
          <div className="flex items-center gap-2">
            <h2 className="truncate font-medium">{label}</h2>
            {busy ? (
              <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" />
            ) : null}
          </div>
          {/* No count for an unopened log ("0 lines" would be false), nor when the selector says it. */}
          {!disabled ? (
            <p className="mt-0.5 text-xs tabular-nums text-muted-foreground">
              {/* The clock note stays permanently: timestamps are server time. */}
              {[
                // Severity hides part of the buffer, so both numbers matter; grep runs server-side.
                severity !== "all"
                  ? t("shownOfLoaded", { shown, loaded })
                  : term
                    ? t("matchCount", { count: loaded })
                    : wholeFile
                      ? t("wholeFile", { count: loaded })
                      : null,
                t("serverTime"),
                followHint,
              ]
                .filter(Boolean)
                .join(" · ")}
            </p>
          ) : null}
        </div>

        {/* Fixed height in every state so the pill does not resize when the tail drops. */}
        <div
          className={cn(
            "flex h-8 shrink-0 items-center gap-2 rounded-full px-3",
            tailState === "reconnecting" && "bg-warning/10",
            tailState === "paused" && "bg-destructive/10",
          )}
        >
          <Radio
            className={cn(
              "size-3.5 shrink-0",
              tailState === "reconnecting" && "text-warning",
              tailState === "paused" && "text-destructive",
              tailState === "live" &&
                "animate-pulse text-success motion-reduce:animate-none",
              // Filtering is an expected pause, not a fault: muted, not amber.
              (tailState === "idle" || tailState === "filtering") &&
                "text-muted-foreground",
            )}
          />
          {tailState === "paused" ? (
            <button
              type="button"
              onClick={onResume}
              className="rounded text-sm font-medium text-destructive underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {t("tailResume")}
            </button>
          ) : (
            <>
              <Label htmlFor="log-follow" className="text-sm font-normal">
                {tailState === "reconnecting"
                  ? t("tailReconnecting")
                  : tailState === "filtering"
                    ? t("tailPausedFiltering")
                    : t("live")}
              </Label>
              <Switch
                id="log-follow"
                checked={follow}
                onCheckedChange={onFollowChange}
                disabled={disabled}
              />
            </>
          )}
        </div>
      </div>

      {/* Filter anchored left (takes the slack), view actions pushed right. */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative w-full min-w-40 flex-1 sm:max-w-sm">
          <Input
            ref={searchRef}
            value={term}
            onChange={(e) => onTermChange(e.target.value)}
            // The API's own limit (grep ≤ 200).
            maxLength={200}
            placeholder={t("searchPlaceholder")}
            disabled={disabled}
            className={cn("w-full", term && "pr-8")}
            aria-label={t("searchPlaceholder")}
          />
          {term ? (
            <button
              type="button"
              onClick={() => {
                onTermChange("");
                searchRef.current?.focus();
              }}
              aria-label={t("clearSearch")}
              className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            >
              <X className="size-4" />
            </button>
          ) : null}
        </div>

        <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
          {/* Severity filters the loaded buffer, so it works while tailing (grep cannot). */}
          <div role="group" aria-label={t("severityLabel")} className="flex items-center gap-1">
            {SEVERITY_FILTERS.map((key) => (
              <button
                key={key}
                type="button"
                onClick={() => onSeverityChange(key)}
                disabled={disabled}
                aria-pressed={severity === key}
                className={cn(
                  "flex h-9 items-center rounded-lg border px-3 text-sm transition-colors disabled:pointer-events-none disabled:opacity-50",
                  "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
                  filterToggleClass(severity === key),
                )}
              >
                {t(`severity.${key}`)}
              </button>
            ))}
          </div>

          {/* A non-preset value gets its own item, or the trigger would render empty. */}
          {customLines ? (
            <div className="flex items-center gap-1.5">
              <Input
                type="number"
                inputMode="numeric"
                min={MIN_LINES}
                max={MAX_LINES}
                defaultValue={lines}
                aria-label={t("linesLabel")}
                autoFocus
                disabled={disabled}
                className="w-28"
                onKeyDown={(event) => {
                  if (event.key === "Enter") {
                    event.preventDefault();
                    event.currentTarget.blur();
                  }
                  if (event.key === "Escape") {
                    setCustomLines(false);
                  }
                }}
                // Applied on blur, not per keystroke: each change refetches the log.
                onBlur={(event) => {
                  const next = normalizeLineCount(event.target.value);
                  // Values above the cap are clamped; say so.
                  if (Number.parseInt(event.target.value, 10) > MAX_LINES) {
                    toast.info(t("linesCapped", { max: MAX_LINES }));
                  }
                  if (next !== null && next !== lines) onLinesChange(next);
                  setCustomLines(false);
                }}
              />
              <span className="text-xs text-muted-foreground">{t("linesUnit")}</span>
            </div>
          ) : (
            <Select
              value={String(lines)}
              onValueChange={(v) => {
                if (v === CUSTOM_LINES) {
                  setCustomLines(true);
                  return;
                }
                onLinesChange(Number(v));
              }}
              disabled={disabled}
            >
              <SelectTrigger aria-label={t("linesLabel")} className="w-auto min-w-40">
                <SelectValue />
              </SelectTrigger>
              <SelectContent position="popper">
                {LINE_OPTIONS.map((n) => (
                  <SelectItem key={n} value={String(n)}>
                    {t("linesOption", { count: n })}
                  </SelectItem>
                ))}
                {LINE_OPTIONS.includes(lines) ? null : (
                  <SelectItem value={String(lines)}>
                    {t("linesOption", { count: lines })}
                  </SelectItem>
                )}
                <SelectItem value={CUSTOM_LINES}>{t("linesCustom")}</SelectItem>
              </SelectContent>
            </Select>
          )}

          {/* One segmented group; h-9 matches the controls beside it. */}
          <div className="flex h-9 items-center overflow-hidden rounded-lg border divide-x">
            <IconAction
              icon={newestFirst ? ArrowUpNarrowWide : ArrowDownNarrowWide}
              label={newestFirst ? t("orderOldestFirst") : t("orderNewestFirst")}
              onClick={() => onNewestFirstChange(!newestFirst)}
              active={newestFirst}
              disabled={disabled}
            />
            <IconAction
              icon={WrapText}
              label={wrap ? t("wrapOff") : t("wrapOn")}
              onClick={() => onWrapChange(!wrap)}
              active={wrap}
              disabled={disabled}
            />
            <IconAction
              icon={Copy}
              label={t("copyVisible")}
              onClick={onCopyVisible}
              disabled={disabled}
            />
            <ReasonTooltip reason={disabled && !reloadable ? reloadReason : null} className="inline-flex h-full">
              <IconAction
                icon={RotateCw}
                label={t("reload")}
                onClick={onReload}
                disabled={disabled && !reloadable}
              />
            </ReasonTooltip>
            {showDownload || downloadReason ? (
              <ReasonTooltip reason={downloadReason} className="inline-flex h-full">
                <IconAction
                  icon={Download}
                  label={t("download")}
                  href={downloadUrl}
                  disabled={disabled || Boolean(downloadReason)}
                />
              </ReasonTooltip>
            ) : null}
          </div>

          {/* Last, apart from the view actions: it empties the file for good. */}
          {onClear ? (
            // ml-auto on a wrapper, not a divider (which would strand on a wrapped line): a
            // disabled Button sits inside its reason tooltip's span.
            <div className="ml-auto">
            <Button
              type="button"
              // The destructive variant, not outline + red classes: inside a card the outline
              // variant's tint outranks them and the button rendered blue.
              variant="destructive"
              size="sm"
              className="h-9 rounded-lg border-destructive/40"
              onClick={onClear}
              disabled={disabled || clearing || Boolean(clearReason)}
              disabledReason={clearReason}
            >
              {clearing ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Eraser className="size-4" />
              )}
              {t("clear")}
            </Button>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function IconAction({ icon: Icon, label, onClick, href, active, disabled }) {
  const shared = {
    variant: "ghost",
    size: "icon",
    // The group's border and dividers do the framing, so buttons are square segments.
    className: cn(
      "size-9 h-full rounded-none",
      active && "bg-secondary text-secondary-foreground",
    ),
    disabled,
  };

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {href ? (
          // Disabled it is a <button>, not the link, so it needs its own name.
          <Button {...shared} asChild={!disabled} aria-label={disabled ? label : undefined}>
            {disabled ? (
              <span>
                <Icon className="size-4" />
              </span>
            ) : (
              <a href={href} download aria-label={label}>
                <Icon className="size-4" />
              </a>
            )}
          </Button>
        ) : (
          <Button
            {...shared}
            onClick={onClick}
            aria-label={label}
            aria-pressed={active}
          >
            <Icon className="size-4" />
          </Button>
        )}
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  );
}
