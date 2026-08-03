"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
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

// The API's three groups, in the order they matter to someone freeing space:
// packages are the biggest and safest win, temp files the smallest.
const GROUP_ORDER = ["package", "logs", "temp"];

/**
 * Pick what to clean, see what it will do, then do it.
 *
 * Nothing is selected on arrival. A cleaner that arrives pre-ticked turns a
 * deliberate action into a dare, and the one thing every panel's bug tracker
 * agrees on is that people click the big button without reading.
 */
export function CleanupPanel({ categories, canManage, measuredAt }) {
  const t = useTranslations("diskCleaner");
  const router = useRouter();
  const [selected, setSelected] = useState(() => new Set());
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState(null);
  const [showPaths, setShowPaths] = useState(false);
  const [error, setError] = useState(null);

  // Something with nothing to reclaim can still be listed — it just can't be
  // selected, because ticking it would promise space that isn't there.
  const cleanable = useMemo(
    () => categories.filter((c) => c.available && c.reclaimable > 0),
    [categories],
  );

  const ordered = useMemo(
    () =>
      categories
        .filter((category) => category.available)
        // Descending by size, with the already-clean rows last: they can't be
        // acted on, so they belong out of the way rather than interleaved.
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

  // One control, both directions — a "select all" with no way back makes you
  // untick six rows by hand.
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
      // Report what the disk actually gave back, not what we predicted — the
      // gap between the two is the complaint every other panel collects.
      if (parsed.success) setResult(parsed.data);
      setConfirming(false);
      setSelected(new Set());
      router.refresh();
    } catch (err) {
      // Kept in the dialog rather than only in a toast: the dialog stays open
      // on failure so the selection isn't lost, and a toast that has already
      // faded leaves an open dialog with no explanation for why nothing
      // happened. The reference id goes with it — that is what support needs.
      const reference = err.response?.data?.reference;
      setError([apiMessage(err, t("clean.failed")), reference].filter(Boolean).join(" · "));
      toast.error(apiMessage(err, t("clean.failed")));
    } finally {
      setPending(false);
    }
  }

  const nothingToClean = cleanable.length === 0;

  return (
    <>
      {/* pb-0 so the footer band reaches the card edge — Card's own bottom
          padding was showing as a white strip under the tinted band. */}
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
          {/* Freshness and the folder toggle share one row: both are about how
              you are viewing the list, neither is an action on it. Sitting
              opposite the title they squeezed "What can be cleaned" onto two
              lines on a phone. */}
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
                {/* Per category, because "freed 0 B" on one of five is the thing
                    someone needs to see to understand the total. */}
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

                          {/* The whole block is the label, so the name, the
                              description and the row's empty space all toggle
                              the row — not just the 16px box. */}
                          <label
                            htmlFor={`clean-${category.key}`}
                            className={cn(
                              "relative min-w-0 flex-1 space-y-0.5",
                              empty || !canManage ? "cursor-default" : "cursor-pointer",
                            )}
                          >
                            <div className="flex flex-wrap items-center gap-2">
                              {/* Name and its ⓘ stay welded together — left as
                                  separate flex items the icon wrapped onto a
                                  line of its own, pointing at nothing. */}
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

                              {/* The API decides what is safe to remove
                                  unattended; anything else gets said out loud
                                  rather than left to the size column. */}
                              {!category.safe && !empty ? (
                                <Badge variant="warning" className="font-normal">
                                  {t("list.checkFirst")}
                                </Badge>
                              ) : null}

                              {empty ? (
                                <Badge variant="secondary" className="font-normal">
                                  {t("list.alreadyClean")}
                                </Badge>
                              ) : null}
                            </div>

                            {category.description ? (
                              <p className="text-sm text-muted-foreground">
                                {category.description}
                              </p>
                            ) : null}

                            {/* Plain monospace, one path per line. Joined on a
                                single line they read as a row of links, and the
                                last one always got cut off. */}
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
                {/* No separate Clear: the footer link already unselects
                    everything, and two controls for one job is one too many. */}
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
                      // The dialog's own onOpenChange clears this, but opening
                      // from here never calls it — so a failure from a previous
                      // attempt sat there describing a request nobody made.
                      setError(null);
                      setConfirming(true);
                    }}
                  >
                    {/* The button states the outcome, so the amount is on the
                        control you press rather than only in the summary line. */}
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
        // The base width lives on a data-attribute variant, so a plain
        // sm:max-w-* never wins — it has to be overridden in the same form.
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
        {/* The review step: exactly what was picked, what it frees, and the real
            folders it touches — seen before anything is deleted, not after. */}
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
                {/* One path per line, wrapping. A truncated path is worse than
                    no path — it reads as if that were the whole folder. */}
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

        {/* Named, not counted: "1 item needs care" makes you go back and hunt
            for which one. */}
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
  );
}

// Plain monospace, one path per line. Capped at five because one category here
// has fifteen, which pushed every other row off the screen — but the rest are a
// click away rather than cut off, since a hidden path is what "show exact
// folders" was turned on to avoid.
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
            // Inside the row's <label>, so without this the click also toggles
            // the checkbox.
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

// Only used for the live selection total; every other size on this page is the
// string the API already formatted.
function humanBytes(bytes) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / 1024 ** i;
  return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}
