"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Database, Loader2, Plug, TriangleAlert } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { EngineLogo } from "@/components/databases/engine-logo";
import { engineLogo } from "@/lib/databases/engine-logo";
import { InstallConfirm } from "@/components/databases/install-confirm";
import { ConnectionDialog } from "@/components/databases/connection-dialog";
import { DatabaseInstallProgress } from "@/components/databases/database-install-progress";
import { useEngineInstallPolling } from "@/components/databases/use-engine-install-polling";
import {
  engineInstallCanRetry,
  engineIsPresent,
  findPresentSqlEngine,
  isSqlEngine,
} from "@/lib/databases/install-lifecycle";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils";

/**
 * The page when no engine is reachable yet: every engine and where it stands,
 * as a grid of logo cards. A failed install is one card's status plus the
 * server's sentence, not the whole page. No outer Card: cards inside a card
 * would be a box inside a box.
 */
export function EngineState({ engines = [], connections = [], canManage }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [connecting, setConnecting] = useState(null);
  const { engines: list, slow, pollIssue, markStarted } =
    useEngineInstallPolling(engines);
  const inFlight = list.find(
    (engine) => engine.install_status === "installing",
  );

  // Present on the server, whether or not the panel can talk to it. A second
  // SQL engine can never join it.
  const sqlPresent = findPresentSqlEngine(list);

  /*
   * "Recommended" is advice for a first choice only, so it is withdrawn once
   * any engine is on the server.
   */
  const anyPresent = list.some((engine) => engineIsPresent(engine));

  return (
    <>
      <section className="space-y-5">
        {/*
          * No Health link here, unlike the populated bar: this renders only when
          * nothing is running, and the monitor needs a running engine. The
          * cards carry the recovery routes (Services, Connection).
          */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-48 flex-1 items-center gap-2.5">
            <span className="flex shrink-0 items-center justify-center text-muted-foreground">
              <Database className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t("engineList.title")}
              </h2>
              <p className="text-sm text-muted-foreground">
                {t("engineList.description")}
              </p>
            </div>
          </div>

        </div>

        {/* Two columns so each card's sentence stays readable. `items-start`
            stops a growing card (progress, failure) stretching its neighbour. */}
        <div className="grid items-start gap-5 sm:grid-cols-2">
          {list.map((engine) => (
            <EngineCard
              key={engine.engine}
              engine={engine}
              canManage={canManage}
              sqlPresent={sqlPresent}
              present={engineIsPresent(engine)}
              busy={Boolean(inFlight)}
              anyPresent={anyPresent}
              onInstall={() => setPending(engine)}
              onConnection={() => setConnecting(engine)}
              slow={slow && inFlight?.engine === engine.engine}
              pollIssue={pollIssue && inFlight?.engine === engine.engine}
            />
          ))}
        </div>
      </section>

        {/* The clicked card names the engine, so it goes straight to the
          confirmation. */}
      <InstallConfirm
        engine={pending}
        open={pending !== null}
        onOpenChange={(next) => !next && setPending(null)}
        onSuccess={({ engine, queued }) => {
          setPending(null);
          if (queued) markStarted(engine);
          else router.refresh();
        }}
      />

      {connecting ? (
        <ConnectionDialog
          engine={connecting}
          connection={connections.find((c) => c.engine === connecting.engine)}
          open
          onOpenChange={(next) => !next && setConnecting(null)}
        />
      ) : null}
    </>
  );
}

function EngineCard({
  engine,
  canManage,
  sqlPresent,
  present,
  busy,
  anyPresent,
  onInstall,
  onConnection,
  slow,
  pollIssue,
}) {
  const t = useTranslations("databases");
  const name = t(`engines.${engine.engine}`);
  const failed = engine.install_status === "failed";
  const installing = engine.install_status === "installing";
  const conflicted =
    isSqlEngine(engine) && sqlPresent && sqlPresent !== engine;

  // The nested progress object is authoritative on current APIs. The helper
  // retains the old reason-based fallback for servers not upgraded yet.
  const deadEnd = failed && !engineInstallCanRetry(engine);

  /* No button when pressing could achieve nothing: not installable, already
   * here, not retryable, or the other SQL engine. The card text says why. */
  const useless =
    !engine.installable || present || deadEnd || conflicted;

  // Reasons that are worth a tooltip on a button that could otherwise work.
  const blocked = !canManage
    ? t("noPermission")
    : busy && !installing
      ? t("install.oneAtATime")
      : null;

  /*
   * The whole card is clickable only when installing is its one action.
   * Otherwise it stays a div with real controls, so a click is never silently
   * swallowed. Matches the site-type picker.
   */
  const clickable = !installing && !useless && !blocked;

  // Only for an actionable first choice: not once an engine is present,
  // installing or failed. Requires `installable`.
  const recommended =
    !anyPresent &&
    engine.engine === "mysql" &&
    engine.installable &&
    !failed &&
    !installing;

  // Whether the note explains a state that BLOCKS installing, rather than
  // commenting on one in progress. Only these get the notice treatment.
  const blockedState = !installing && !failed && !present && !engine.installable;

  // The note under the name: the server's wording for a failure, or ours for
  // states it never reports.
  const note = engine.install_progress
    ? null
    : failed
      ? engine.install_message
      : installing && pollIssue
        ? t("install.pollIssue")
        : installing && slow
          ? t("install.takingLonger")
          : !engine.installable
            ? /* The API's reason (e.g. no vendor build for this Ubuntu release)
                 is more accurate than the generic fallback. */
              (engine.unavailable?.reason ?? t("install.notInstallable"))
            : conflicted
              ? t("install.sqlConflict", {
                  other: t(`engines.${sqlPresent.engine}`),
                })
              : present
                ? t("engineList.unreachableHint")
                : null;

  const actions = (
    <>
      {/* A present but silent engine: the service is stopped (Services) or the
          panel's sign-in is wrong (Connection). */}
      {!installing && useless && present ? (
        <>
          <Button asChild variant="ghost" size="sm">
            <Link href="/services">{t("status.checkServices")}</Link>
          </Button>
          {canManage ? (
            <Button variant="outline" size="sm" onClick={onConnection}>
              <Plug className="size-4" />
              {t("connection.action")}
            </Button>
          ) : null}
        </>
      ) : null}

      {/* A span, not a Button: the card itself is the button, and nesting one
          inside another is invalid. */}
      {clickable ? (
        <span
          aria-hidden
          className={cn(
            /*
             * Filled on every installable card: these are equal choices. The
             * recommendation is carried by the badge.
             */
            buttonVariants({ variant: failed ? "outline" : "default", size: "sm" }),
            "pointer-events-none",
          )}
        >
          {failed ? t("status.tryAgain") : t("install.submit")}
        </span>
      ) : null}

      {/* Not clickable but still worth offering: the reason lives on the
          tooltip, so this stays a real disabled button. */}
      {!clickable && !installing && !useless ? (
        <ReasonTooltip reason={blocked}>
          <Button
            variant={failed ? "outline" : "default"}
            size="sm"
            disabled={Boolean(blocked)}
            onClick={onInstall}
          >
            {failed ? t("status.tryAgain") : t("install.submit")}
          </Button>
        </ReasonTooltip>
      ) : null}
    </>
  );

  const Root = clickable ? "button" : "div";

  return (
    <Root
      type={clickable ? "button" : undefined}
      onClick={clickable ? onInstall : undefined}
      className={cn(
        "flex flex-col gap-3.5 rounded-xl border bg-card p-5 text-left shadow-e1 transition-all duration-200",
        // No mt-auto: it would pad every card with empty space.
        clickable &&
          "hover:-translate-y-px hover:shadow-e2 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
        // Faded means "nothing to do here", never "broken"; a retryable failure
        // stays full-strength.
        !clickable && useless && "bg-muted/40",
      )}
    >
      <div className="flex items-start justify-between gap-3">
        {/* Wordmark logos already spell the name; only mark-only logos
            (PostgreSQL) print it. */}
        <span className="flex min-h-8 items-center gap-2">
          <EngineLogo engine={engine.engine} />
          {engineLogo(engine.engine)?.wordmark ? (
            <span className="sr-only">{name}</span>
          ) : (
            <span className="text-sm font-medium">{name}</span>
          )}
        </span>

        <div className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
          {recommended ? (
            <Badge variant="outline" className="border-primary/30 bg-primary/10 font-normal text-primary">
              {t("engineList.recommended")}
            </Badge>
          ) : null}
          <EngineBadge engine={engine} present={present} />
        </div>
      </div>

      {/* What it is for, in one line, sharing its row with the action. */}
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="min-w-48 flex-1 text-sm leading-relaxed text-muted-foreground">
          {t(`engineList.purpose.${engine.engine}`)}
        </p>
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          {actions}
        </div>
      </div>

      {note ? (
        <p
          className={cn(
            "text-xs leading-relaxed",
            failed
              ? "text-destructive"
              : blockedState
                ? /*
                   * A blocked state, not commentary: warning tone and alert
                   * glyph, matching a blocked site-type card, so it is not
                   * skimmed as description.
                   */
                  "flex items-start gap-1.5 rounded-lg border border-warning/30 bg-warning/5 p-2.5 text-warning"
                : "text-muted-foreground",
          )}
        >
          {blockedState ? <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden /> : null}
          {note}
        </p>
      ) : null}

      {engine.install_progress ? (
        <DatabaseInstallProgress
          progress={engine.install_progress}
          label={name}
          slow={slow}
          pollIssue={pollIssue}
          // Withholding the handler tells the failure block a retry cannot help.
          onRetry={deadEnd || conflicted || busy ? undefined : onInstall}
        />
      ) : null}

    </Root>
  );
}

function EngineBadge({ engine, present }) {
  const t = useTranslations("databases");

  if (engine.install_status === "installing") {
    return (
      <Badge variant="muted" className="font-normal">
        <Loader2 className="size-3 animate-spin" />
        {t("engineList.installing", { name: t(`engines.${engine.engine}`) })}
      </Badge>
    );
  }
  if (engine.install_status === "failed") {
    return (
      <Badge variant="destructive" className="font-normal">
        <TriangleAlert className="size-3" />
        {t("engineList.failed")}
      </Badge>
    );
  }
  if (engine.running) {
    return (
      <Badge variant="success" className="font-normal">
        {t("status.running")}
      </Badge>
    );
  }
  // On the server but unreachable: a different problem from "absent", and not
  // fixed by reinstalling.
  if (present) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("engineList.unreachable")}
      </Badge>
    );
  }
  return (
    <Badge variant="muted" className="font-normal">
      {t("engineList.notInstalled")}
    </Badge>
  );
}
