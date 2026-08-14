"use client";

import { ChevronDown, CircleX, TriangleAlert } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";

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
              of the answer. */}
          <ul className="max-h-72 space-y-1.5 overflow-y-auto pr-1">
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
                {isOperation && entry.reference ? (
                  <p className="font-mono text-[11px] text-muted-foreground/70">
                    {t("reference", { reference: entry.reference })}
                  </p>
                ) : null}
              </li>
            ))}
          </ul>
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}
