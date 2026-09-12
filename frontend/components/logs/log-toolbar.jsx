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
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
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

// Not a line count, so it can never collide with one.
const CUSTOM_LINES = "custom";

/**
 * Viewer controls. The source's name lives here as the pane heading.
 *
 * The window size ("last 200 lines") is stated by the selector and nowhere
 * else; the line under the title reports the *result* — and only when it says
 * something the selector doesn't, i.e. when a filter narrowed the buffer or the
 * whole file turned out to be smaller than the window.
 */
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
  // Emptying the log. Null when the viewer has no such action or the reader
  // lacks `manage` — hidden rather than disabled, because an always-disabled
  // destructive control invites "why not" and the answer is "you may not".
  onClear = null,
  clearing = false,
  busy,
  disabled,
  searchRef,
  tailState = "idle",
  onResume,
}) {
  const t = useTranslations("logs");
  // Swaps the preset selector for a number field, and back once applied.
  const [customLines, setCustomLines] = useState(false);

  return (
    // Tinted like a window title bar, so the card reads as one terminal —
    // chrome above, screen below — rather than two stacked panels.
    //
    // Two rows, not one. Above: identity — the source's name, what we're
    // holding of it, and whether it's still being tailed. Below: every control,
    // filter left and actions right. Crammed onto one line the controls win
    // every pixel and the heading collapses to an ellipsis in a 60px column.
    <div className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3">
      <div className="flex items-center gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h2 className="truncate font-medium">{label}</h2>
            {busy ? (
              <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" />
            ) : null}
          </div>
          {/* No count for a log we couldn't open — "0 lines" would state
            something we don't know. The count appears only when it isn't
            already legible from the line selector. */}
          {!disabled ? (
            <p className="mt-0.5 text-xs tabular-nums text-muted-foreground">
              {/* The clock note lives here permanently: timestamps are about to
                appear below it, and "whose clock?" is the question that costs
                an hour at 3am. */}
              {[
                // Severity hides part of what we hold, so both numbers matter.
                // Grep runs server-side, so what we hold *is* the match set —
                // "200 of 200" would be a strange way to say "200 matches".
                severity !== "all"
                  ? t("shownOfLoaded", { shown, loaded })
                  : term
                    ? t("matchCount", { count: loaded })
                    : wholeFile
                      ? t("wholeFile", { count: loaded })
                      : null,
                t("serverTime"),
              ]
                .filter(Boolean)
                .join(" · ")}
            </p>
          ) : null}
        </div>

        {/* Live is a mode, not an action — a switch, with the indicator telling
            the truth about the tail: pulsing while polling, amber while
            retrying, and an explicit Resume once it has given up. A tail that
            quietly stopped looks exactly like a log that went quiet.
            It sits with the heading rather than with the controls below: it
            describes the source's state, it doesn't act on the view.
            No border — a boxed control would read as another input. */}
        <div
          className={cn(
            "flex shrink-0 items-center gap-2 rounded-lg px-2",
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
              // Filtering is an expected pause, not a fault — muted, not amber.
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

      {/* Every control on one band: the filter anchored left (it's the only one
          that can be long, so it takes the slack), everything that acts on the
          view pushed right. Row one keeps nothing but identity and the tail
          mode, which is what gives the heading room to breathe. */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative w-full min-w-40 flex-1 sm:max-w-sm">
          <Input
            ref={searchRef}
            value={term}
            onChange={(e) => onTermChange(e.target.value)}
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
          {/* Severity is a display filter over the loaded buffer, which is why it
              can run while tailing — grep can't (it re-reads the whole file).
              Segmented rather than a dropdown: three options, and "show me the
              errors" is the one-click reason people open this page. */}
          <div
            role="group"
            aria-label={t("severityLabel")}
            className="flex h-9 items-center overflow-hidden rounded-lg border divide-x"
          >
            {SEVERITY_FILTERS.map((key) => (
              <button
                key={key}
                type="button"
                onClick={() => onSeverityChange(key)}
                disabled={disabled}
                aria-pressed={severity === key}
                className={cn(
                  "flex h-full items-center px-3.5 text-sm transition-colors disabled:pointer-events-none disabled:opacity-50",
                  "focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring",
                  severity === key
                    ? "bg-secondary font-medium text-secondary-foreground"
                    : "text-muted-foreground hover:bg-muted",
                )}
              >
                {t(`severity.${key}`)}
              </button>
            ))}
          </div>

          {/*
            * Five presets cover the usual windows, and the sixth option hands
            * the field over: someone chasing one incident wants the last 40
            * lines, not the last 100, and someone reading a whole morning
            * wants 2500. The API takes any integer up to its own cap, so the
            * only reason the number was a fixed list is that nobody offered
            * the box.
            *
            * A value that is not a preset — arrived at by typing, or restored
            * from a previous visit — is shown as its own item, or the trigger
            * would render empty and the selector would look broken.
            */}
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
                // Applied on leaving the field, not on every keystroke: each
                // change refetches the log, and typing "500" would fetch 5,
                // then 50, then 500.
                onBlur={(event) => {
                  const next = normalizeLineCount(event.target.value);
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
              <SelectTrigger aria-label={t("linesLabel")} className="w-40">
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

          {/* Deliberately outside the segmented group below. Those are view
              actions — they change what you see and nothing else. This one
              destroys the thing being viewed, and putting it a pixel away from
              Reload is how a misclick becomes an unrecoverable one. */}
          {onClear ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onClear}
              disabled={disabled || clearing}
            >
              {clearing ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Eraser className="size-4" />
              )}
              {t("clear")}
            </Button>
          ) : null}

          {/* One segmented group, not four floating squares: these are view
              actions on the same object, so they read as a single control. */}
          <div className="flex items-center overflow-hidden rounded-lg border divide-x">
            {/* Which end the newest line is at. A view action on the same
                object as wrap, so it belongs in the same group rather than as
                a seventh floating control. */}
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
            <IconAction
              icon={RotateCw}
              label={t("reload")}
              onClick={onReload}
              disabled={disabled}
            />
            {showDownload ? (
              <IconAction
                icon={Download}
                label={t("download")}
                href={downloadUrl}
                disabled={disabled}
              />
            ) : null}
          </div>
        </div>
      </div>
    </div>
  );
}

function IconAction({ icon: Icon, label, onClick, href, active, disabled }) {
  const shared = {
    variant: "ghost",
    size: "icon",
    // Square, borderless, square-cornered: the group's border and dividers do
    // the framing so the buttons read as segments of one control.
    className: cn(
      "size-9 rounded-none",
      active && "bg-secondary text-secondary-foreground",
    ),
    disabled,
  };

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {href ? (
          <Button {...shared} asChild={!disabled}>
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
