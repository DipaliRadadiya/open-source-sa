"use client";

import { Braces, ChevronDown, CircleX, TriangleAlert } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";

/**
 * The entry exactly as the API returned it.
 *
 * The row above is a reading of the payload — shortened class name, relative
 * time, the two fields worth a headline. When that reading is not enough, this
 * is the payload itself, with nothing selected out and nothing renamed, so a
 * field the panel has no opinion about is still findable. Collapsed by default:
 * it answers a question most readers are not asking yet.
 */
function RawEntry({ entry }) {
  const t = useTranslations("errorLogs");
  const json = JSON.stringify(entry, null, 2);

  return (
    <Collapsible className="group/raw pt-1">
      <div className="flex items-center gap-1">
        <CollapsibleTrigger asChild>
          <Button variant="ghost" size="sm" className="-ml-2 h-7 gap-1.5 px-2 text-xs">
            <Braces className="size-3.5" />
            {t("rawTitle")}
            <ChevronDown className="size-3.5 transition-transform group-data-[state=open]/raw:rotate-180" />
          </Button>
        </CollapsibleTrigger>
        {/* Outside the trigger: nesting it would make copying toggle the
            panel, and the usual reason to copy this is to paste it to someone
            who needs it. */}
        <CopyButton value={json} label={t("rawCopy")} />
      </div>
      <CollapsibleContent className="pt-1.5">
        {/* No cap of its own. The occurrence list above already scrolls, and a
            scroll area nested inside one is a trap — the wheel fights over
            which box it belongs to. Lines wrap, so there is no sideways
            overflow to catch either. */}
        <pre className="whitespace-pre-wrap break-words rounded-md border bg-zinc-950 p-3 font-mono text-[11px] leading-4 text-zinc-100">
          {json}
        </pre>
      </CollapsibleContent>
    </Collapsible>
  );
}

/**
 * 503 is the panel refusing work because something is already running — a
 * queue, a lock, a busy server. That is a different kind of event from a 500
 * (something genuinely broke) and colouring them alike would hide it.
 */
function statusMeta(status) {
  return status === 503
    ? { Icon: TriangleAlert, tint: "text-warning", chip: "bg-warning/10", pill: "warning" }
    : { Icon: CircleX, tint: "text-destructive", chip: "bg-destructive/10", pill: "destructive" };
}

/**
 * One kind of failure, with every occurrence of it folded inside.
 *
 * `now` is passed down from the server render rather than read from the
 * browser clock: relative times computed at hydration disagree with the ones
 * rendered on the server, and React replaces the whole subtree over it.
 */
export function ErrorGroupRow({ group, now }) {
  const t = useTranslations("errorLogs");
  const format = useFormatter();
  const isOperation = group.kind === "operation";
  const meta = statusMeta(group.status);
  const { Icon } = meta;

  const when = (date) => (date ? format.relativeTime(date, now) : t("unknownTime"));
  const exact = (date) =>
    date ? format.dateTime(date, { dateStyle: "medium", timeStyle: "medium" }) : null;

  return (
    <Collapsible className="rounded-2xl border bg-card shadow-sm">
      <CollapsibleTrigger className="group flex w-full items-start gap-4 p-4 text-left">
        <span
          className={cn(
            "flex size-10 shrink-0 items-center justify-center rounded-xl",
            meta.chip,
          )}
        >
          <Icon className={cn("size-5", meta.tint)} aria-hidden />
        </span>

        <div className="min-w-0 flex-1 space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-medium leading-tight">
              {isOperation
                ? t("operationTitle", { operation: group.operation ?? t("unknownOperation") })
                : group.exceptionShort ?? t("unknownException")}
            </p>
            {group.status ? (
              <Badge variant={meta.pill} className="font-normal">
                {group.status}
              </Badge>
            ) : null}
            {/* An exit code is the operation's equivalent of a status: it is
                what distinguishes "apt could not get the lock" from "the
                package does not exist", and both otherwise render as the same
                sentence. */}
            {isOperation && group.exitCode != null ? (
              <Badge variant={meta.pill} className="font-normal tabular-nums">
                {t("exitCode", { code: group.exitCode })}
              </Badge>
            ) : null}
            {/* The count is the reason this screen groups at all — one fault hit
                200 times and one hit once need to look different at a glance. */}
            {group.count > 1 ? (
              <Badge variant="secondary" className="font-normal tabular-nums">
                {t("occurrences", { count: group.count })}
              </Badge>
            ) : null}
          </div>

          {/* Where it happened. For an API failure that is a route pattern; for
              an operation it is the feature that ran the command. Both are the
              backend's own identifiers, so both are mono and neither is
              translated — `feature`/`op` are free-form strings with hundreds of
              combinations, and a lookup table for them would be wrong the day
              a new one is added. */}
          <p className="flex min-w-0 flex-wrap items-center gap-x-2 font-mono text-xs text-muted-foreground">
            {isOperation ? (
              <span className="break-all">
                {group.feature ?? t("unknownFeature")}
                {group.operation ? ` · ${group.operation}` : null}
              </span>
            ) : (
              <>
                {group.method ? (
                  <span className="font-semibold text-foreground/70">{group.method}</span>
                ) : null}
                <span className="break-all">{group.route ?? t("unknownRoute")}</span>
              </>
            )}
          </p>

          {/* Full class name, kept but demoted — the namespace is the same on
              nearly every row and would crowd out the part that differs. */}
          {group.exception && group.exception !== group.exceptionShort ? (
            <p className="break-all font-mono text-[11px] leading-4 text-muted-foreground/70">
              {group.exception}
            </p>
          ) : null}

          <p className="text-xs text-muted-foreground">
            {group.count > 1
              ? t("firstAndLast", { first: when(group.first), last: when(group.last) })
              : when(group.last)}
          </p>
        </div>

        <ChevronDown
          className="mt-1 size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
          aria-hidden
        />
      </CollapsibleTrigger>

      <CollapsibleContent>
        <div className="border-t px-4 py-3">
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {t("everyOccurrence")}
          </p>
          {/* Capped with its own scroll. One fault can account for every entry
              on the page — expanding it unbounded pushed the four other groups
              a screen and a half down, so opening the busiest one hid the rest
              of the answer.

              30rem, not the original 18: one occurrence with its raw payload
              open measures 369px, so the old cap showed the response through a
              288px letterbox and hid the last 81px of it. This fits that, plus
              room for the fields the backend does not send yet, and is still
              under half a laptop viewport — the reason for the cap survives. */}
          <ul className="max-h-[30rem] space-y-1.5 overflow-y-auto pr-1">
            {group.occurrences.map((entry, index) => (
              <li
                key={`${entry.reference ?? "entry"}-${index}`}
                className="space-y-1 border-b pb-1.5 last:border-0 last:pb-0"
              >
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 text-sm">
                  <span className="tabular-nums">
                    {exact(entry.at) ?? t("unknownTime")}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {/* A bare id is all the log carries — no name, no username —
                        so it is labelled as an id rather than dressed up as a
                        person we cannot actually identify. */}
                    {entry.user_id == null
                      ? t("signedOut")
                      : t("userId", { id: entry.user_id })}
                  </span>
                </div>

                {/* The exception's own message. Was a fixed string until
                    2026-08-31, which is why this screen used to lead with the
                    class name; older entries still carry the constant, so it
                    is shown when present rather than assumed. */}
                {entry.message ? (
                  <p className="text-sm leading-5">{entry.message}</p>
                ) : null}

                {/* The command line that failed. For an operation this is the
                    answer — "log / exists / exit 1" says nothing on its own. */}
                {entry.command ? (
                  <pre className="overflow-x-auto rounded-md border bg-zinc-950 p-2 font-mono text-[11px] leading-4 text-zinc-100">
                    {entry.command}
                  </pre>
                ) : null}

                {/* The command's own stderr, already redacted and truncated to
                    1000 characters by the backend. This is the only field that
                    says what broke — everything else says where. Kept as
                    pre-wrapped mono because it is command output, and
                    reflowing it destroys the alignment that makes it
                    readable. */}
                {entry.error ? (
                  <pre className="max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-md bg-muted/60 p-2 font-mono text-[11px] leading-4 text-muted-foreground">
                    {entry.error}
                  </pre>
                ) : null}

                {/* Shown only for operations. On an API exception the
                    reference is a uuid minted at log time that never reached
                    anyone, so offering it for a lookup would be inviting a
                    search that cannot succeed. */}
                {/* Where it threw, then the frames. Vendor frames are already
                    dropped by the backend, so every line here is our code. */}
                {entry.file ? (
                  <p className="font-mono text-[11px] break-all text-muted-foreground">
                    {entry.file}
                  </p>
                ) : null}

                {entry.trace?.length ? (
                  <ul className="space-y-0.5 border-s ps-2 font-mono text-[11px] text-muted-foreground/80">
                    {entry.trace.map((frame, i) => (
                      <li key={`${frame}-${i}`} className="break-all">
                        {frame}
                      </li>
                    ))}
                  </ul>
                ) : null}

                {/* Three attempts over twelve seconds is a lock; one failure in
                    40ms is not. The timestamps cannot tell those apart. */}
                {entry.attempts != null || entry.duration_ms != null ? (
                  <p className="text-[11px] tabular-nums text-muted-foreground/70">
                    {[
                      entry.attempts != null ? t("attempts", { count: entry.attempts }) : null,
                      entry.duration_ms != null ? t("duration", { ms: entry.duration_ms }) : null,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </p>
                ) : null}

                {isOperation && entry.reference ? (
                  <p className="font-mono text-[11px] text-muted-foreground/70">
                    {t("reference", { reference: entry.reference })}
                  </p>
                ) : null}

                <RawEntry entry={entry.raw ?? entry} />
              </li>
            ))}
          </ul>
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}
