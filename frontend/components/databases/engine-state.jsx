"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import Link from "next/link";
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
 * The page when no engine is reachable yet: every engine and where it stands.
 *
 * The previous version made a failed install the entire page, in red, with a
 * primary button inside the alert — which read as a disaster when the actual
 * situation was "you already have MariaDB, keep using it". Worse, MariaDB
 * itself was not on screen at all, because the old bar only listed engines the
 * API admitted to knowing about.
 *
 * So the subject is the engines, not the last thing that went wrong. A failed
 * install is one card's status plus the server's sentence underneath it.
 *
 * Laid out as a grid of logo cards rather than the list of four near-identical
 * rows it used to be. This is the one screen whose whole job is choosing
 * between four brands, and it was the one screen rendering them as plain text
 * while the databases table beside it used the real marks. Four identical
 * primary buttons is also four CTAs, which is none — the empty-state rule
 * (product-design-foundations-research.md [419–423]) asks for one thing to do,
 * and Coolify's New Resource list, the closest modern analogue, is a grid of
 * database cards for exactly this decision.
 *
 * No outer Card around the grid. Cards inside a card is a box inside a box,
 * and the four engines ARE the content of this state.
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
   * The recommendation is advice for a first choice, so it is withdrawn the
   * moment there is anything to choose between: once an engine is on the
   * server, "Recommended" beside a different one is telling someone to install
   * a second database they do not need.
   */
  const anyPresent = list.some((engine) => engineIsPresent(engine));

  return (
    <>
      <section className="space-y-5">
        {/*
          * No Health link here, though the populated bar has one.
          *
          * This component renders only when nothing is running — the page picks
          * between it and EngineBar on exactly that condition — and the monitor
          * page needs a running engine to report anything, so it answers "No
          * database engine is running. Start or connect one first." Offering it
          * here is a button whose only possible destination repeats the screen
          * you pressed it from.
          *
          * It used to be here on the argument that a screen you open to find out
          * why the database is down must not vanish when it goes down. True in
          * spirit, false here: the monitor cannot diagnose a stopped engine
          * either. The cards below carry the real recovery routes — Services and
          * Connection — on the engine that needs them.
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

        {/* Two columns, not four: the cards carry a sentence each, and four
            across turns that sentence into a column of single words.
            `items-start` because one card growing — a progress bar, a failure
            message — otherwise stretches its neighbour to match and pads it
            with the empty space this layout was meant to remove. */}
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

      {/* The card that was clicked names the engine, so it goes straight to the
          confirmation. It used to discard that and ask "which SQL engine?" —
          a question the click had already answered. */}
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

  /* No button at all when nothing could come of pressing it: an engine the
   * panel can't install, one already here, one whose failure retrying cannot
   * fix, or the other SQL engine. A disabled primary button is still the
   * loudest thing in the card — it draws the eye to the one action that is
   * impossible. The card's own text already says why. */
  const useless =
    !engine.installable || present || deadEnd || conflicted;

  // Reasons that are worth a tooltip on a button that could otherwise work.
  const blocked = !canManage
    ? t("noPermission")
    : busy && !installing
      ? t("install.oneAtATime")
      : null;

  /*
   * The whole card installs, and only when installing is the ONE thing this
   * card can do. Anything else — a disabled button that needs its reason, an
   * unreachable engine offering Services and Connection — keeps a plain div
   * and its real controls, because a card that silently swallows a click on a
   * disabled action is worse than a list.
   *
   * Same rule as the site-type picker, which the reader has usually just come
   * from: there, clickability means selectable; here it means installable.
   */
  const clickable = !installing && !useless && !blocked;

  // Advice only while it is still advice, and only for something you could act
  // on: not once an engine is on the server, not on one already being put
  // there, not on one that just failed. `installable` matters because MongoDB
  // was not installable until the backend gained repository provisioning.
  const recommended =
    !anyPresent &&
    engine.engine === "mysql" &&
    engine.installable &&
    !failed &&
    !installing;

  // Whether the note explains a state that BLOCKS installing, rather than
  // commenting on one in progress. Only these get the notice treatment.
  const blockedState = !installing && !failed && !present && !engine.installable;

  // The explanation under the name: the server's own words for a failure, or
  // ours for a state it never reports.
  const note = engine.install_progress
    ? null
    : failed
      ? engine.install_message
      : installing && pollIssue
        ? t("install.pollIssue")
        : installing && slow
          ? t("install.takingLonger")
          : !engine.installable
            ? /* The API's own sentence when it has one. It knows WHY — the
                 vendor has published nothing for this Ubuntu release — where
                 ours only knows that apt came back empty, and says "install it
                 manually", which for this case is advice that cannot be
                 followed. */
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
      {/* An engine that is here but silent has two possible causes, and both
          have somewhere to go: the service isn't running (Services), or the
          panel's own sign-in is wrong (Connection). Without these the card is
          a dead end — bad news and nothing to press. */}
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
          inside another is invalid. It carries the affordance only — every
          click on it is a click on the card. */}
      {clickable ? (
        <span
          aria-hidden
          className={cn(
            /*
             * Filled on every card that can install. Reading the empty-state
             * "one CTA" rule as "only the recommended engine gets a real
             * button" was wrong: that rule is about a screen with one path, and
             * this is four equally valid choices. Outlining three of them made
             * MariaDB, MongoDB and PostgreSQL look like afterthoughts — which
             * is how it was reported. The recommendation is carried by the
             * badge, which is where a recommendation belongs.
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
        // No mt-auto pushing the action to the floor of a stretched card: it
        // bought equal heights with a band of empty space in every one.
        clickable &&
          "hover:-translate-y-px hover:shadow-e2 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
        // Faded means "nothing to do here", never "broken" — a failed install
        // that can still be retried stays a full-strength card.
        !clickable && useless && "bg-muted/40",
      )}
    >
      <div className="flex items-start justify-between gap-3">
        {/* Three of the four logos are wordmarks that already spell the name,
            so printing it beside them reads "MySQL MySQL". PostgreSQL's mark is
            the elephant alone — it got a card with no name on it until this was
            driven and looked at. */}
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

      {/* What it is for, in one line, sharing its row with the action. Four
          names alone answer "which exist" and never "which should I pick",
          which is the actual question someone opening this page first has. */}
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
                   * A blocked state, not commentary.
                   *
                   * As muted text this sat directly under the engine's tagline
                   * in the same size and colour, so it read as a second line of
                   * description and got skimmed — which is the one thing it
                   * cannot afford, because it is the answer to "why is there no
                   * Install button".
                   *
                   * Same treatment a blocked site-type card uses: warning tone
                   * and the alert glyph. The backend shapes both payloads the
                   * same way so that the two can look the same.
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
          // `deadEnd` is this card's own reading of whether a retry can help;
          // withholding the handler is how the failure block learns it.
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
  // On the server but the panel can't reach it — a different problem from
  // "absent", and the one the user would otherwise waste time re-installing.
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
