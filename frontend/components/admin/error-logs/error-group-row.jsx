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

// The entry exactly as the API returned it, so any field is still findable.
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
        {/* Outside the trigger, so copying does not toggle the panel. */}
        <CopyButton value={json} label={t("rawCopy")} />
      </div>
      <CollapsibleContent className="pt-1.5">
        {/* No max height: the occurrence list already scrolls, and nested
            scroll areas fight over the wheel. */}
        <pre className="whitespace-pre-wrap break-words rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-4 text-zinc-100">
          {json}
        </pre>
      </CollapsibleContent>
    </Collapsible>
  );
}

// 503 means the panel refused work because something is already running, unlike a 500.
function statusMeta(status) {
  return status === 503
    ? { Icon: TriangleAlert, tint: "text-warning", chip: "bg-warning/10", pill: "warning" }
    : { Icon: CircleX, tint: "text-destructive", chip: "bg-destructive/10", pill: "destructive" };
}

// `now` comes from the server render so relative times match on hydration.
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
            {/* The exit code distinguishes operations whose sentence is otherwise identical. */}
            {isOperation && group.exitCode != null ? (
              <Badge variant={meta.pill} className="font-normal tabular-nums">
                {t("exitCode", { code: group.exitCode })}
              </Badge>
            ) : null}
            {/* The count is why this screen groups: frequent faults must stand out. */}
            {group.count > 1 ? (
              <Badge variant="secondary" className="font-normal tabular-nums">
                {t("occurrences", { count: group.count })}
              </Badge>
            ) : null}
          </div>

          {/* Where it happened: a route pattern for API failures, the feature
              for operations. Backend identifiers, so mono and untranslated. */}
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

          {/* Full class name, demoted: the namespace repeats on nearly every row. */}
          {group.exception && group.exception !== group.exceptionShort ? (
            <p className="break-all font-mono text-xs leading-4 text-muted-foreground/70">
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
          <p className="mb-2 text-xs font-medium text-muted-foreground">
            {t("everyOccurrence")}
          </p>
          {/* Own scroll so one busy group does not push the others off screen. */}
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
                    {/* The log carries only an id, so it is labelled as one. */}
                    {entry.user_id == null
                      ? t("signedOut")
                      : t("userId", { id: entry.user_id })}
                  </span>
                </div>

                {/* The exception's own message; older entries carry a fixed
                    constant, so it is shown when present rather than assumed. */}
                {entry.message ? (
                  <p className="text-sm leading-5">{entry.message}</p>
                ) : null}

                {/* The command line that failed. */}
                {entry.command ? (
                  <pre className="overflow-x-auto rounded-md border bg-zinc-950 p-2 font-mono text-xs leading-4 text-zinc-100">
                    {entry.command}
                  </pre>
                ) : null}

                {/* Redacted, truncated stderr: the only field that says what
                    broke. Pre-wrapped mono to keep command-output alignment. */}
                {entry.error ? (
                  <pre className="max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-md bg-muted/60 p-2 font-mono text-xs leading-4 text-muted-foreground">
                    {entry.error}
                  </pre>
                ) : null}

                {/* Where it threw, then the frames (vendor frames already dropped). */}
                {entry.file ? (
                  <p className="font-mono text-xs break-all text-muted-foreground">
                    {entry.file}
                  </p>
                ) : null}

                {entry.trace?.length ? (
                  <ul className="space-y-0.5 border-s ps-2 font-mono text-xs text-muted-foreground/80">
                    {entry.trace.map((frame, i) => (
                      <li key={`${frame}-${i}`} className="break-all">
                        {frame}
                      </li>
                    ))}
                  </ul>
                ) : null}

                {/* Attempts and duration tell a lock retried for seconds from a single fast failure. */}
                {entry.attempts != null || entry.duration_ms != null ? (
                  <p className="text-xs tabular-nums text-muted-foreground/70">
                    {[
                      entry.attempts != null ? t("attempts", { count: entry.attempts }) : null,
                      entry.duration_ms != null ? t("duration", { ms: entry.duration_ms }) : null,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </p>
                ) : null}

                {/* Operations only: an API exception's reference is never sent
                    to the client, so a lookup cannot succeed. */}
                {isOperation && entry.reference ? (
                  <p className="font-mono text-xs text-muted-foreground/70">
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
