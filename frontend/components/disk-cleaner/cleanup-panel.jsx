"use client";

import { useMemo, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { formatBytes, localizeSizeText } from "@/lib/format/bytes";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { Loader2, Trash2, Check, TriangleAlert, FolderTree, Sparkles } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
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
import { KeepSelect, useKeepLabel } from "@/components/disk-cleaner/keep-select";
import { hasKeepChoice, keepOptions, sizeFor } from "@/lib/disk-cleaner/keep-days";
import { confirmNotes, keepApplicable } from "@/lib/disk-cleaner/confirm-notes";
import { useKeepChoice } from "@/components/disk-cleaner/keep-context";
import { Caution } from "@/components/ui/caution";
import { Label } from "@/components/ui/label";
import { RefreshButton } from "@/components/data-table/refresh-button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

export function CleanupPanel({ categories, keepDays, canManage, measuredAt }) {
  const format = useFormatter();
  const t = useTranslations("diskCleaner");
  const keepLabel = useKeepLabel();
  const { choices, recommended } = keepOptions(keepDays);
  const keepSupported = hasKeepChoice(keepDays);
  const [keep, setKeep] = useKeepChoice();
  const { refreshAndWait } = useRefresh();
  const [selected, setSelected] = useState(() => new Set());
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState(null);
  const [showPaths, setShowPaths] = useState(false);
  const [error, setError] = useState(null);

  const priced = useMemo(
    () =>
      categories
        .filter((c) => c.available)
        .map((c) => {
          const size = sizeFor(c, keep);
          return { ...c, bytes: size.bytes, human: size.human };
        }),
    [categories, keep],
  );

  const cleanable = useMemo(() => priced.filter((c) => c.bytes > 0), [priced]);

  const cleanableRows = useMemo(() => [...cleanable].sort((a, b) => b.bytes - a.bytes), [cleanable]);
  const cleanRows = useMemo(() => priced.filter((c) => !(c.bytes > 0)), [priced]);

  const chosen = cleanable.filter((c) => selected.has(c.key));
  const chosenBytes = chosen.reduce((total, c) => total + c.bytes, 0);
  const riskyChosen = chosen.filter((c) => !c.safe);
  const notes = confirmNotes(chosen);
  const keptChosen = keepApplicable(chosen);

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

  function toggleAll() {
    setSelected(allSelected ? new Set() : new Set(cleanable.map((c) => c.key)));
    setResult(null);
  }

  async function clean() {
    setPending(true);
    setError(null);
    try {
      const response = await cleanDisk(chosen.map((c) => c.key), keep);
      const parsed = cleanResultSchema.safeParse(response.data);
      await refreshAndWait();
      if (parsed.success) {
        setResult(parsed.data);
        toast.success(
          t("clean.freed", {
            size: localizeSizeText(parsed.data.freed_total_human ?? "0 B", format),
            percent: parsed.data.disk?.percent ?? 0,
          }),
        );
      }
      setConfirming(false);
      setSelected(new Set());
    } catch (err) {
      const reference = err.response?.data?.reference;
      setError([apiMessage(err, t("clean.failed")), reference].filter(Boolean).join(" · "));
      toast.error(apiMessage(err, t("clean.failed")));
    } finally {
      setPending(false);
    }
  }

  const nothingToClean = cleanable.length === 0;

  return (
    <DisabledReasonProvider denied={!canManage}>
      <>
        <Card className="gap-0 overflow-hidden pb-0">
          <CardHeader className="pb-4">
            <CardTitle icon={Sparkles}>{t("list.title")}</CardTitle>
            {measuredAt ? (
              <CardDescription className="@max-sm/card-header:col-span-full">
                <MeasuredAt at={measuredAt} />
              </CardDescription>
            ) : null}
            <CardAction className="flex items-center gap-2 @max-sm/card-header:col-start-1 @max-sm/card-header:row-span-1 @max-sm/card-header:row-start-3 @max-sm/card-header:mt-3 @max-sm/card-header:justify-self-start">
              {!nothingToClean ? (
                <Button
                  type="button"
                  variant="outline"
                  className="h-9"
                  aria-pressed={showPaths}
                  onClick={() => setShowPaths((v) => !v)}
                >
                  <FolderTree className="size-4" />
                  {showPaths ? t("list.hidePaths") : t("list.showPaths")}
                </Button>
              ) : null}
              <RefreshButton />
            </CardAction>
          </CardHeader>

          <CardContent className="px-0 pb-0">
            {keepSupported ? (
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t px-4 py-3">
              <Label htmlFor="clean-keep" className="text-sm font-medium">
                {t("keep.label")}
              </Label>
              <KeepSelect
                id="clean-keep"
                value={keep}
                onChange={(next) => {
                  setKeep(next);
                  setResult(null);
                }}
                choices={choices}
                recommended={recommended}
                className="h-9 w-auto min-w-52"
              />
              <span className="min-w-48 flex-1 text-xs text-muted-foreground">{t(keep === 0 ? "keep.hintDeleteAll" : "keep.hint")}</span>
            </div>
            ) : null}

            {result ? (
              <div className="m-4 flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/5 p-3 text-sm">
                <Check className="mt-0.5 size-4 shrink-0 text-success" />
                <div className="space-y-1">
                  <p className="font-medium">
                    {t("clean.freed", {
                      size: localizeSizeText(result.freed_total_human ?? "0 B", format),
                      percent: result.disk?.percent ?? 0,
                    })}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {result.cleaned
                      .map((c) => `${labelFor(categories, c.key)} ${localizeSizeText(c.freed_human ?? "0 B", format)}`)
                      .join(" · ")}
                  </p>
                </div>
              </div>
            ) : null}

            {nothingToClean ? (
              <div className="border-t">
                <EmptyState
                  compact
                  icon={Sparkles}
                  badge={null}
                  title={keep ? t("keep.nothingAtChoice") : t("list.allClean")}
                />
              </div>
            ) : (
              <div className="border-t">
                {canManage ? (
                <div className="flex items-center gap-3 border-b bg-muted/40 px-7 py-2.5">
                  <Checkbox
                    id="clean-all"
                    checked={allSelected ? true : chosen.length > 0 ? "indeterminate" : false}
                    onCheckedChange={toggleAll}
                  />
                  <label htmlFor="clean-all" className="cursor-pointer text-xs font-medium text-muted-foreground">
                    {t("list.selectAll")}
                  </label>
                </div>
                ) : null}

                <div className="@container/items px-4 py-4">
                  <ul className="grid grid-cols-1 gap-3 @2xl/items:grid-cols-2">
                    {cleanableRows.map((category) => {
                      const checked = selected.has(category.key);

                      return (
                        <li key={category.key} className="min-w-0">
                          <label
                            htmlFor={`clean-${category.key}`}
                            className={cn(
                              "flex h-full items-start gap-3 rounded-xl border p-3 transition-colors",
                              checked ? "border-primary/40 bg-primary/5" : "bg-card",
                              canManage ? "cursor-pointer hover:border-primary/30" : "cursor-default",
                            )}
                          >
                            {canManage ? (
                              <Checkbox
                                id={`clean-${category.key}`}
                                className="mt-0.5"
                                checked={checked}
                                onCheckedChange={() => toggle(category.key)}
                                aria-label={category.label}
                              />
                            ) : null}

                            <div className="min-w-0 flex-1 space-y-0.5">
                              <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                  <span className="inline-flex items-center gap-1.5">
                                    <span className="text-sm font-medium">{category.label}</span>
                                    {category.note ? (
                                      <InfoHint label={t("list.whatIsKept")}>{category.note}</InfoHint>
                                    ) : null}
                                  </span>

                                  {!category.safe ? (
                                    <Badge variant="warning" className="font-normal">
                                      {t("list.checkFirst")}
                                    </Badge>
                                  ) : null}
                                </div>

                                <span className="flex shrink-0 flex-col items-end">
                                  <span className="text-sm font-semibold tabular-nums">
                                    {localizeSizeText(category.human, format) ?? humanBytes(category.bytes, format)}
                                  </span>
                                  {category.keeps_days === false && keep !== 0 ? (
                                    <span className="text-xs text-muted-foreground">{t("keep.alwaysAll")}</span>
                                  ) : null}
                                </span>
                              </div>

                              {category.description ? (
                                <p className="text-sm text-muted-foreground">{category.description}</p>
                              ) : null}

                              {showPaths && category.paths?.length ? (
                                <PathList
                                  paths={category.paths}
                                  moreLabel={(count) =>
                                    count > 0 ? t("list.morePaths", { count }) : t("list.fewerPaths")
                                  }
                                />
                              ) : null}
                            </div>
                          </label>
                        </li>
                      );
                    })}
                  </ul>

                  {cleanRows.length ? (
                    <p className="mt-3 flex items-start gap-2 text-sm text-muted-foreground">
                      <Check className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
                      <span>
                        {t("list.alreadyCleanList", {
                          items: cleanRows.map((category) => category.label).join(", "),
                        })}
                      </span>
                    </p>
                  ) : null}
                </div>
              </div>
            )}

            {!nothingToClean && canManage ? (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-4 py-4">
                <p className="min-w-0 text-sm text-muted-foreground tabular-nums">
                  {chosen.length > 0
                    ? t("action.selectedSummary", {
                        count: chosen.length,
                        size: humanBytes(chosenBytes, format),
                      })
                    : t("action.pickSomething")}
                </p>

                <ReasonTooltip reason={chosen.length === 0 ? t("action.pickSomething") : null}>
                  <Button
                    disabled={chosen.length === 0}
                    onClick={() => {
                      // Opening from here skips onOpenChange, so clear the old error.
                      setError(null);
                      setConfirming(true);
                    }}
                  >
                    {chosen.length > 0
                      ? t("action.cleanSized", { size: humanBytes(chosenBytes, format) })
                      : t("action.clean")}
                  </Button>
                </ReasonTooltip>
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
          // The base width is a data-attribute variant, so a plain sm:max-w-* never wins.
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
          <div className="overflow-hidden rounded-lg border">
            <ul className="max-h-72 divide-y overflow-auto">
              {chosen.map((category) => (
                <li key={category.key} className="space-y-1 px-3 py-2.5">
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="text-sm font-medium">{category.label}</span>
                    <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                      {localizeSizeText(category.human, format) ?? humanBytes(category.bytes, format)}
                    </span>
                  </div>
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
  
            <div className="flex items-baseline justify-between gap-3 border-t bg-muted/40 px-3 py-2.5 text-sm font-medium">
              <span>{t("confirm.total")}</span>
              <span className="tabular-nums">{humanBytes(chosenBytes, format)}</span>
            </div>
          </div>

          {notes.truncates ? <p className="text-sm">{t("confirm.truncateNote")}</p> : null}

          {keep != null && notes.keepApplies ? (
            <p className="text-sm">
              {t("keep.confirmLine", { choice: keepLabel(keep, recommended) })}
            </p>
          ) : null}

          {keep === 0 && notes.keepApplies ? (
            <Caution tone="destructive" size="md">
              <p>
                {t("keep.deleteAllItems", { items: keptChosen.map((c) => c.label).join(", ") })}
                {keptChosen.some((c) => c.key === "site_logs") ? ` ${t("keep.deleteAllSiteLogs")}` : null}
              </p>
            </Caution>
          ) : null}
  
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

function humanBytes(bytes, format) {
  return formatBytes(bytes || 0, format);
}
