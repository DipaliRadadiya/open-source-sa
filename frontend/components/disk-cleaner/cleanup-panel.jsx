"use client";

import { useMemo, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { Loader2, Trash2, Check, TriangleAlert, FolderTree } from "lucide-react";
import { cn } from "@/lib/utils";
import { cleanDisk } from "@/lib/api/disk-cleaner";
import { apiMessage } from "@/lib/api/error-message";
import { cleanResultSchema } from "@/lib/schemas/disk-cleaner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { InfoHint } from "@/components/ui/info-hint";
import { MeasuredAt } from "@/components/disk-cleaner/measured-at";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

// Nothing is pre-selected: a destructive action must be deliberate.
export function CleanupPanel({ categories, canManage, measuredAt }) {
  const t = useTranslations("diskCleaner");
  const { refreshAndWait } = useRefresh();
  const [selected, setSelected] = useState(() => new Set());
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState(null);
  const [showPaths, setShowPaths] = useState(false);
  const [error, setError] = useState(null);

  // Rows with nothing to reclaim are listed but cannot be selected.
  const cleanable = useMemo(
    () => categories.filter((c) => c.available && c.reclaimable > 0),
    [categories],
  );

  // By measured size, not the API's category grouping.
  const ordered = useMemo(
    () =>
      categories
        // `filter` returns a new array, so the sort below cannot mutate the prop.
        .filter((category) => category.available)
        // Descending by size, so already-clean rows end up last.
        .sort((a, b) => b.reclaimable - a.reclaimable),
    [categories],
  );

  const chosen = cleanable.filter((c) => selected.has(c.key));
  const chosenBytes = chosen.reduce((total, c) => total + c.reclaimable, 0);
  const riskyChosen = chosen.filter((c) => !c.safe);

  function toggle(key) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
    setResult(null);
  }

  const allSelected = cleanable.length > 0 && chosen.length === cleanable.length;

  // One control, both directions.
  function toggleAll() {
    setSelected(allSelected ? new Set() : new Set(cleanable.map((c) => c.key)));
    setResult(null);
  }

  async function clean() {
    setPending(true);
    setError(null);
    try {
      const response = await cleanDisk(chosen.map((c) => c.key));
      const parsed = cleanResultSchema.safeParse(response.data);
      // What the disk actually freed; sizes are re-read first so the result never sits beside stale ones.
      await refreshAndWait();
      if (parsed.success) setResult(parsed.data);
      setConfirming(false);
      setSelected(new Set());
    } catch (err) {
      // Also kept in the dialog, which stays open on failure and needs the support reference.
      const reference = err.response?.data?.reference;
      setError([apiMessage(err, t("clean.failed")), reference].filter(Boolean).join(" · "));
      toast.error(apiMessage(err, t("clean.failed")));
    } finally {
      setPending(false);
    }
  }

  const nothingToClean = cleanable.length === 0;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <>
        {/* pb-0 so the footer band reaches the card edge. */}
        <Card className="overflow-hidden pb-0">
          <CardHeader>
            <CardTitle className="text-base font-semibold">{t("list.title")}</CardTitle>
            <CardDescription>
              {chosen.length > 0
                ? t("action.selectedSummary", {
                    count: chosen.length,
                    size: humanBytes(chosenBytes),
                  })
                : t("list.subtitle")}
            </CardDescription>
            {/* Both about the view, neither an action. */}
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
              {measuredAt ? <MeasuredAt at={measuredAt} /> : <span />}
  
              {!nothingToClean ? (
                <button
                  type="button"
                  className="inline-flex items-center gap-1.5 text-xs text-muted-foreground underline underline-offset-4"
                  onClick={() => setShowPaths((v) => !v)}
                >
                  <FolderTree className="size-3.5" />
                  {showPaths ? t("list.hidePaths") : t("list.showPaths")}
                </button>
              ) : null}
            </div>
          </CardHeader>
  
          <CardContent className="space-y-4 px-0 pb-0">
            {result ? (
              <div className="mx-6 flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/5 p-3 text-sm">
                <Check className="mt-0.5 size-4 shrink-0 text-success" />
                <div className="space-y-1">
                  <p className="font-medium">
                    {t("clean.freed", {
                      size: result.freed_total_human ?? "0 B",
                      percent: result.disk?.percent ?? 0,
                    })}
                  </p>
                  {/* Per category, so a "0 B" on one is visible behind the total. */}
                  <p className="text-xs text-muted-foreground">
                    {result.cleaned
                      .map((c) => `${labelFor(categories, c.key)} ${c.freed_human ?? "0 B"}`)
                      .join(" · ")}
                  </p>
                </div>
              </div>
            ) : null}
  
            {nothingToClean ? (
              <p className="px-6 py-6 text-center text-sm text-muted-foreground">
                {t("list.allClean")}
              </p>
            ) : (
              <ul className="divide-y border-t">
                {ordered.map((category) => {
                        const empty = category.reclaimable <= 0;
                        const checked = selected.has(category.key);
  
                        return (
                          <li
                            key={category.key}
                            className={cn(
                              "relative flex items-start gap-3 px-6 py-3 transition-colors",
                              checked && "bg-primary/5",
                              empty && "opacity-55",
                            )}
                          >
                            <ReasonTooltip
                              reason={
                                !canManage
                                  ? t("noPermission")
                                  : empty
                                    ? t("list.nothingToFree")
                                    : null
                              }
                            >
                              <Checkbox
                                id={`clean-${category.key}`}
                                className="relative mt-0.5"
                                checked={checked}
                                disabled={empty || !canManage}
                                onCheckedChange={() => toggle(category.key)}
                                aria-label={category.label}
                              />
                            </ReasonTooltip>
  
                            {/* The whole block is the label, so the entire row toggles. */}
                            <label
                              htmlFor={`clean-${category.key}`}
                              className={cn(
                                "relative min-w-0 flex-1 space-y-0.5",
                                empty || !canManage ? "cursor-default" : "cursor-pointer",
                              )}
                            >
                              <div className="flex flex-wrap items-center gap-2">
                                {/* Keeps the ⓘ from wrapping onto its own line. */}
                                <span className="inline-flex items-center gap-1.5">
                                  <span className="text-sm font-medium">{category.label}</span>
                                  {category.note ? (
                                    <InfoHint label={t("list.whatIsKept")}>{category.note}</InfoHint>
                                  ) : null}
                                </span>
  
                                {category.group ? (
                                  <Badge variant="outline" className="font-normal text-muted-foreground">
                                    {t.has(`groups.${category.group}`)
                                      ? t(`groups.${category.group}`)
                                      : category.group}
                                  </Badge>
                                ) : null}
  
                                {/* The API decides what is safe to remove unattended. */}
                                {!category.safe && !empty ? (
                                  <Badge variant="warning" className="font-normal">
                                    {t("list.checkFirst")}
                                  </Badge>
                                ) : null}
  
                                {empty ? (
                                  <Badge variant="muted" className="font-normal">
                                    {t("list.alreadyClean")}
                                  </Badge>
                                ) : null}
                              </div>
  
                              {category.description ? (
                                <p className="text-sm text-muted-foreground">
                                  {category.description}
                                </p>
                              ) : null}
  
                              {/* One path per line, monospace. */}
                              {showPaths && category.paths?.length ? (
                                <PathList
                                  paths={category.paths}
                                  moreLabel={(count) =>
                                    count > 0 ? t("list.morePaths", { count }) : t("list.fewerPaths")
                                  }
                                />
                              ) : null}
                            </label>
  
                            <span className="relative shrink-0 text-sm font-medium tabular-nums">
                              {category.reclaimable_human ?? "0 B"}
                            </span>
                          </li>
                  );
                })}
              </ul>
            )}
  
            {!nothingToClean ? (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-6 py-4">
                <button
                  type="button"
                  className="text-xs text-muted-foreground underline-offset-4 hover:underline disabled:no-underline disabled:opacity-50"
                  onClick={toggleAll}
                  disabled={!canManage}
                >
                  {allSelected ? t("list.unselectAll") : t("list.selectAll")}
                </button>
  
                <div className="flex items-center gap-2">
                  {/* No separate Clear: the footer link already unselects everything. */}
                  <ReasonTooltip
                    reason={
                      !canManage
                        ? t("noPermission")
                        : chosen.length === 0
                          ? t("action.pickSomething")
                          : null
                    }
                  >
                    <Button
                      disabled={!canManage || chosen.length === 0}
                      onClick={() => {
                        // Opening from here skips onOpenChange, so clear a stale error explicitly.
                        setError(null);
                        setConfirming(true);
                      }}
                    >
                      {/* The button states the amount it will free. */}
                      {chosen.length > 0
                        ? t("action.cleanSized", { size: humanBytes(chosenBytes) })
                        : t("action.clean")}
                    </Button>
                  </ReasonTooltip>
                </div>
              </div>
            ) : null}
          </CardContent>
        </Card>
  
        <ConfirmDialog
          open={confirming}
          onOpenChange={(open) => {
            if (pending) return;
            if (open) setError(null);
            setConfirming(open);
          }}
          // The base width is a data-attribute variant; a plain sm:max-w-* never wins.
          className="data-[size=default]:max-w-[calc(100vw-2rem)] data-[size=default]:sm:max-w-xl"
          icon={Trash2}
          tone="destructive"
          title={t("confirm.title")}
          description={t("confirm.description")}
          cancelLabel={t("confirm.cancel")}
          confirmLabel={t("confirm.submit")}
          pending={pending}
          onConfirm={clean}
        >
          {/* Review before anything is deleted. */}
          <div className="overflow-hidden rounded-lg border">
            <ul className="max-h-72 divide-y overflow-auto">
              {chosen.map((category) => (
                <li key={category.key} className="space-y-1 px-3 py-2.5">
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="text-sm font-medium">{category.label}</span>
                    <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                      {category.reclaimable_human}
                    </span>
                  </div>
                  {/* One path per line, wrapping: a truncated path misleads. */}
                  {category.paths?.length ? (
                    <ul className="space-y-0.5">
                      {category.paths.map((path) => (
                        <li key={path} className="break-all font-mono text-xs text-muted-foreground">
                          {path}
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </li>
              ))}
            </ul>
  
            {/* The number the button promises, restated where the list ends. */}
            <div className="flex items-baseline justify-between gap-3 border-t bg-muted/40 px-3 py-2.5 text-sm font-medium">
              <span>{t("confirm.total")}</span>
              <span className="tabular-nums">{humanBytes(chosenBytes)}</span>
            </div>
          </div>
  
          {/* Named, not counted. */}
          {riskyChosen.length ? (
            <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 p-3 text-xs text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("confirm.riskyWarning", {
                items: riskyChosen.map((category) => category.label).join(", "),
              })}
            </p>
          ) : null}
  
          {pending ? (
            <p className="flex items-center gap-2 text-xs text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" />
              {t("confirm.working")}
            </p>
          ) : null}
  
          {error ? (
            <p className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-xs text-destructive">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {error}
            </p>
          ) : null}
        </ConfirmDialog>
      </>
    </DisabledReasonProvider>
  );
}

// Paths shown per category before "more"; the rest stay one click away.
const PATH_PREVIEW = 5;

function PathList({ paths, moreLabel }) {
  const [expanded, setExpanded] = useState(false);
  const shown = expanded ? paths : paths.slice(0, PATH_PREVIEW);
  const hidden = paths.length - shown.length;

  return (
    <div className="pt-0.5">
      <ul>
        {shown.map((path) => (
          <li key={path} className="break-all font-mono text-xs leading-5 text-muted-foreground/80">
            {path}
          </li>
        ))}
      </ul>
      {hidden > 0 || expanded ? (
        <button
          type="button"
          className="mt-0.5 text-xs text-muted-foreground underline-offset-4 hover:underline"
          onClick={(e) => {
            // Inside the row's <label>: without this the click also toggles the checkbox.
            e.preventDefault();
            setExpanded((v) => !v);
          }}
        >
          {expanded ? moreLabel(0) : moreLabel(hidden)}
        </button>
      ) : null}
    </div>
  );
}

function labelFor(categories, key) {
  return categories.find((c) => c.key === key)?.label ?? key;
}

// Only for the live selection total; other sizes are API-formatted strings.
function humanBytes(bytes) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / 1024 ** i;
  return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}
