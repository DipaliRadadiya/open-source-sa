import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowRight, Check, CircleAlert, CircleCheck, Clock3, Copy, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { CLONE_IN_FLIGHT } from "@/lib/schemas/clone";
import { fetchClone } from "@/lib/api/clone";
import { apiDuration } from "@/lib/format/api-date";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { CopyButton } from "@/components/ui/copy-button";

/** The endpoint is throttled at 120/min; every 2s sits well inside that. */
const POLL_MS = 2000;

/** Give up after 20 minutes — a job that has not moved by then is stuck. */
const POLL_LIMIT_MS = 20 * 60 * 1000;

/**
 * A clone, while it runs and after it lands.
 *
 * The backend queues the work now and reports named steps, so this shows the
 * real four — creating the site, copying files, cloning the database,
 * starting the application — rather than the indeterminate bar the first cut
 * needed when the whole thing happened inside one long request. Watching
 * "Copying files" actually tick over is the difference between waiting and
 * wondering.
 *
 * Not a modal any more, for the same reason: leaving the page no longer
 * abandons anything, so blocking the screen would be taking something away
 * for nothing.
 */
export function CloneProgress({ clone: initial, sourceApplication, onDone, onAgain }) {
  const t = useTranslations("applications.clone.progress");
  const router = useRouter();
  const [clone, setClone] = useState(initial);
  const [stalled, setStalled] = useState(false);
  const timer = useRef(null);

  const inFlight = CLONE_IN_FLIGHT.includes(clone?.status);
  const id = clone?.id;

  useEffect(() => {
    if (!inFlight || !id) return undefined;

    async function poll() {
      try {
        const response = await fetchClone(id);
        const next = response.data?.clone;
        if (!next) return;
        setClone(next);
        if (!CLONE_IN_FLIGHT.includes(next.status)) {
          // A new application exists now; every list showing sites is stale.
          router.refresh();
          onDone?.(next);
        }
      } catch {
        // A blip is not worth alarming anyone about — the next tick picks it
        // up, and the queue is working regardless of this page.
      }
    }

    timer.current = setInterval(poll, POLL_MS);
    const stop = setTimeout(() => {
      clearInterval(timer.current);
      setStalled(true);
    }, POLL_LIMIT_MS);

    return () => {
      clearInterval(timer.current);
      clearTimeout(stop);
    };
  }, [inFlight, id, router, onDone]);

  if (!clone) return null;

  if (clone.status === "completed") {
    return <Completed clone={clone} sourceApplication={sourceApplication} onAgain={onAgain} />;
  }
  if (clone.status === "failed") return <Failed clone={clone} onAgain={onAgain} />;

  const total = clone.total_steps ?? STEP_KEYS.length;
  const step = clone.step_number ?? 0;
  const percent = total > 0 ? Math.round((step / total) * 100) : 0;

  // Nothing has been picked up off the queue yet: no step is running, so a
  // list of four dim rows with no spinner is indistinguishable from a job that
  // died. Say which it is.
  const queued = clone.status === "pending";

  // The step names live here because every row needs a label, not just the one
  // the API is currently reporting. If the backend ever adds a fifth, that
  // list is wrong — so it is only drawn while the counts agree, and otherwise
  // the bar plus the API's own title for the current step carries it.
  const stepsMatch = total === STEP_KEYS.length;

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex items-start gap-3 border-b px-5 py-4">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10">
          <Loader2 className="size-6 animate-spin text-primary" aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-medium">{stalled ? t("stalled") : queued ? t("queued") : t("running")}</p>
          <p className="text-sm text-muted-foreground">
            {stalled ? t("stalledBody") : t("target", { domain: clone.domain })}
          </p>
          {/* How long this has been going, which is the number someone reaches
              for before deciding whether to worry. */}
          {!stalled && clone.started_at_human ? (
            <p className="text-xs text-muted-foreground">
              {t("started", { when: clone.started_at_human })}
            </p>
          ) : null}
        </div>
      </div>

      <CardContent className="space-y-4 px-5 py-4">
        <Progress value={percent} className="h-1.5" />

        {/* The four stages, with the ones already done kept on screen. A bar
            alone says how far; the list says what is happening and what is
            still to come. */}
        {stepsMatch ? (
        <ol className="space-y-2.5">
          {STEP_KEYS.map((key, index) => {
            const position = index + 1;
            const done = step > position;
            const current = step === position;
            return (
              <li key={key} className="flex items-center gap-3 text-sm">
                <span
                  className={cn(
                    "flex size-5 shrink-0 items-center justify-center rounded-full border",
                    done && "border-success bg-success/10 text-success",
                    current && "border-primary bg-primary/10 text-primary",
                    !done && !current && "border-muted-foreground/30 text-muted-foreground/50",
                  )}
                >
                  {done ? (
                    <Check className="size-3" />
                  ) : current ? (
                    <Loader2 className="size-3 animate-spin" />
                  ) : (
                    <span className="text-[0.625rem] tabular-nums">{position}</span>
                  )}
                </span>
                <span className={cn(current && "font-medium", !done && !current && "text-muted-foreground")}>
                  {t(`steps.${key}`)}
                </span>
              </li>
            );
          })}
        </ol>
        ) : (
          <p className="text-sm">
            {clone.current_step_title ?? t("step", { step, total })}
          </p>
        )}

        {/* True now that the work is queued, and worth saying — it is the
            difference between someone sitting here and getting on with
            something else. */}
        {stalled ? (
          <Button variant="outline" onClick={() => router.refresh()} className="w-full sm:w-auto">
            {t("checkAgain")}
          </Button>
        ) : (
          <p className="border-t pt-3 text-xs text-muted-foreground">{t("canLeave")}</p>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * The stages the backend reports, in order.
 *
 * Mirrors `CloneResource`'s own list so the numbering matches `step_number`.
 * Titles come from our messages rather than the API's `current_step_title`
 * because every step needs a label, not just the one currently running.
 */
const STEP_KEYS = ["provisioning", "copying_files", "cloning_database", "starting_process"];

function Completed({ clone, sourceApplication, onAgain }) {
  const t = useTranslations("applications.clone.progress");
  const duration = apiDuration(clone.started_at, clone.finished_at);
  const sourceName = sourceApplication?.name ?? clone.source_application_name;
  const sourceDomain = sourceApplication?.domain;
  const destinationName = clone.name || clone.domain;
  const sourceHasSeparateDomain =
    sourceDomain && String(sourceName ?? "").toLowerCase() !== sourceDomain.toLowerCase();
  const destinationHasSeparateDomain =
    clone.domain && destinationName.toLowerCase() !== clone.domain.toLowerCase();

  return (
    <Card className="h-full gap-0 overflow-hidden border-success/30 py-0 shadow-sm">
      <CardContent className="flex h-full flex-col p-0">
        <div className="flex items-start gap-3 border-b bg-success/[0.04] px-5 py-5 sm:gap-4 sm:px-6">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-success/10 ring-1 ring-success/15">
            <CircleCheck className="size-6 text-success" aria-hidden />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="font-semibold tracking-tight">{t("doneTitle")}</h2>
              <Badge
                variant="outline"
                className="border-success/30 bg-success/10 font-normal text-success"
              >
                {t("completedBadge")}
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">{t("doneBody")}</p>
          </div>
        </div>

        <div className="flex-1 space-y-4 px-5 py-5 sm:px-6">
          <dl className="grid gap-2 rounded-xl border bg-muted/20 p-3 sm:grid-cols-[minmax(0,1fr)_2.5rem_minmax(0,1.2fr)] sm:items-stretch">
            <div className="min-w-0 rounded-lg border bg-background/80 px-3 py-2.5">
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {t("source")}
              </dt>
              <dd className="mt-1 truncate text-sm font-medium">{sourceName ?? "—"}</dd>
              {sourceHasSeparateDomain ? (
                <dd className="mt-0.5 truncate font-mono text-xs text-muted-foreground">
                  {sourceDomain}
                </dd>
              ) : null}
            </div>

            <span className="hidden items-center gap-1 text-muted-foreground/70 sm:flex" aria-hidden>
              <span className="h-px flex-1 bg-border" />
              <ArrowRight className="size-4 shrink-0" />
            </span>

            <div className="min-w-0 rounded-lg border border-success/20 bg-success/[0.06] px-3 py-2.5">
              <dt className="text-xs font-medium uppercase tracking-wide text-success">
                {t("destination")}
              </dt>
              <dd className="mt-1 flex min-w-0 items-center gap-1.5">
                <span className="truncate text-sm font-semibold">{destinationName}</span>
                {!destinationHasSeparateDomain ? (
                  <CopyButton value={clone.domain} label={t("copyDomain")} />
                ) : null}
              </dd>
              {destinationHasSeparateDomain ? (
                <dd className="mt-0.5 flex min-w-0 items-center gap-1.5">
                  <span className="truncate font-mono text-xs text-muted-foreground">
                    {clone.domain}
                  </span>
                  <CopyButton value={clone.domain} label={t("copyDomain")} />
                </dd>
              ) : null}
            </div>
          </dl>

          {duration || clone.finished_at_human ? (
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
              {duration ? (
                <span className="inline-flex items-center gap-1.5">
                  <Clock3 className="size-3.5" aria-hidden />
                  {t("duration", { duration })}
                </span>
              ) : null}
              {clone.finished_at_human ? (
                <span>{t("finished", { when: clone.finished_at_human })}</span>
              ) : null}
            </div>
          ) : null}
        </div>

        <div className="flex flex-col-reverse gap-2 border-t px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
          <Button variant="outline" onClick={onAgain} className="w-full sm:w-auto">
            <Copy className="size-4" />
            {t("again")}
          </Button>
          {clone.target_application_id ? (
            <Button asChild className="w-full sm:w-auto">
              <Link href={`/applications/${clone.target_application_id}`}>
                {t("open")}
                <ArrowRight className="size-4" />
              </Link>
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

function Failed({ clone, onAgain }) {
  const t = useTranslations("applications.clone.progress");

  return (
    <Card className="gap-0 overflow-hidden border-destructive/30 py-0 shadow-sm">
      <CardContent className="space-y-4 px-5 py-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-destructive/10">
            <CircleAlert className="size-6 text-destructive" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            <p className="font-medium">{t("failedTitle")}</p>
            {/* A translated key naming what went wrong — never raw stderr,
                which the backend deliberately does not send. Carries more
                weight than the reassurance below it: what happened is the
                answer being looked for, not the fact that nothing broke. */}
            <p className="text-sm">{clone.reason_title ?? t("failedUnknown")}</p>
            <p className="text-sm text-muted-foreground">{t("failedSafe")}</p>
            {clone.reference ? (
              <p className="pt-1 font-mono text-xs text-muted-foreground">
                {t("reference", { reference: clone.reference })}
              </p>
            ) : null}
          </div>
        </div>

        <div className="flex flex-col gap-2 border-t pt-4 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={onAgain} className="w-full sm:w-auto">
            {t("tryAgain")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

/** The four things a fresh copy does not inherit, each linked to its screen. */
export function CloneNextSteps({ applicationId, sourceProtected }) {
  const t = useTranslations("applications.clone.result.next");

  const steps = [
    ...(sourceProtected ? [{ key: "password", href: `/applications/${applicationId}/security` }] : []),
    { key: "ssl", href: `/applications/${applicationId}/domains` },
    { key: "backups", href: `/applications/${applicationId}/backups` },
    { key: "deploys", href: `/applications/${applicationId}/deployment` },
  ];

  return (
    <Card className="h-full gap-0 overflow-hidden py-0 shadow-sm">
      <div className="border-b px-5 py-4">
        <h2 className="font-semibold tracking-tight">{t("title")}</h2>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      <CardContent className="min-h-0 flex-1 p-0">
        <ul className="grid h-full auto-rows-fr divide-y">
          {steps.map((step) => (
            <li key={step.key}>
              <Link
                href={step.href}
                className="flex h-full items-center gap-3 px-5 py-3.5 transition-colors hover:bg-muted/40"
              >
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium">{t(`items.${step.key}.title`)}</p>
                  <p className="text-xs text-muted-foreground">{t(`items.${step.key}.body`)}</p>
                </div>
                <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
              </Link>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
