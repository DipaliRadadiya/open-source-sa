"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Activity, Loader2, Plus, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { EngineLogo } from "@/components/databases/engine-logo";
import { engineLogo } from "@/lib/databases/engine-logo";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { InstallConfirm } from "@/components/databases/install-confirm";
import { DatabaseInstallProgress } from "@/components/databases/database-install-progress";
import { useEngineInstallPolling } from "@/components/databases/use-engine-install-polling";
import { findInstallCandidates } from "@/lib/databases/install-lifecycle";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * What is running, plus any second engine currently being added.
 *
 * This surface remains mounted whenever at least one engine is reachable, so
 * it owns the complete lifecycle for an additional engine. Closing the install
 * confirmation must not close the only evidence that work was queued.
 */
/**
 * The version, without the packaging.
 *
 * Every engine buries the number in a different kind of noise:
 *   PostgreSQL  "16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)"  — the number twice
 *   MariaDB     "10.11.14-MariaDB-0ubuntu0.24.04.1"      — no space at all
 *   MongoDB     "8.0.31"                                  — already clean
 *
 * Splitting on a space fixed only PostgreSQL, which is what shipping the first
 * attempt showed: MariaDB stayed three times wider than the tile it sat in.
 * So this takes the leading dotted number, which is the answer to "which
 * version", and leaves the full string on the tile's title.
 */
function shortVersion(version) {
  if (typeof version !== "string") return null;
  return version.match(/^\d+(?:\.\d+)*/)?.[0] ?? version.split(" ")[0] ?? null;
}

/**
 * A logo, plus the name when the logo does not contain one.
 *
 * PostgreSQL's mark is the elephant alone, so a tile holding only the logo and
 * a version number never says which engine it is. Its entry also carries its
 * own `size` — it is square where the others are wide — which at tile scale
 * rendered a 32px elephant beside 14px wordmarks, so the height is forced here.
 */
function EngineMark({ engine, status, t }) {
  const name = t(`engines.${engine}`);
  const wordmark = engineLogo(engine)?.wordmark;
  return (
    <>
      <EngineLogo engine={engine} className="!h-4 w-auto max-w-16" />
      {/*
        The accessible name is assembled here, once.
        
        The logo images are `aria-hidden`, so a wordmark tile has no name at all
        without the sr-only text — but on PostgreSQL, where the name is also
        printed, having both said "PostgreSQL PostgreSQL". Caught by reading the
        rendered text content, not by looking at it; the duplicate is invisible
        on screen and only a screen reader would ever have met it.
      */}
      {wordmark ? (
        <span className="sr-only">{status ? `${name} · ${status}` : name}</span>
      ) : (
        <>
          <span className="text-xs font-medium">{name}</span>
          {status ? <span className="sr-only">{status}</span> : null}
        </>
      )}
    </>
  );
}

export function EngineBar({ engines = [], canManage, summary }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const {
    engines: list,
    installingEngine,
    slow,
    pollIssue,
    markStarted,
  } = useEngineInstallPolling(engines);

  const running = list.filter((engine) => engine.running);
  const installing = list.filter(
    (engine) => !engine.running && engine.install_status === "installing",
  );
  const failed = list.filter(
    (engine) => !engine.running && engine.install_status === "failed",
  );

  // Recovery wins over a fresh choice. Previously failed engines were excluded
  // by `!engine.install_status`, so Retry vanished permanently whenever another
  // engine kept this populated page visible.
  const addable = findInstallCandidates(list);
  const only = addable.length === 1 ? addable[0] : null;
  // Only one apt install can run. Prefer its live lifecycle; otherwise retain
  // the newest failed lifecycle so diagnostics do not disappear beside a
  // healthy engine.
  const progressEngine =
    installing.find((engine) => engine.install_progress) ??
    failed.find((engine) => engine.install_progress) ??
    null;
  const failureMessage = progressEngine
    ? null
    : failed.find((engine) => engine.install_message)?.install_message;

  return (
    <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-e1 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      {/*
        One tile per engine, with the logo, rather than a run-on line of names.
        
        This was three names, three version strings and three green "Running"
        badges on a single wrapping line — every part the same weight, nothing
        grouped, and no logos at all on the page whose empty state had just been
        given them. Reported as needing work, and it did.
        
        A green dot rather than a badge for the normal case: three identical
        "Running" badges say the same thing three times and leave nothing louder
        for the states that matter. Installing and failed keep their words.
      */}
      <div className="flex flex-wrap items-center gap-2">
        {running.map((engine) => (
          <span
            key={engine.engine}
            title={engine.version ?? undefined}
            className="flex items-center gap-2 rounded-lg border bg-muted/30 px-2.5 py-1.5"
          >
            <span
              aria-hidden
              className="size-1.5 shrink-0 rounded-full bg-success"
            />
            <EngineMark engine={engine.engine} status={t("status.running")} t={t} />
            {shortVersion(engine.version) ? (
              <span className="font-mono text-xs whitespace-nowrap text-muted-foreground">
                {shortVersion(engine.version)}
              </span>
            ) : null}
          </span>
        ))}

        {installing.map((engine) => (
          <span
            key={engine.engine}
            className="flex items-center gap-2 rounded-lg border border-warning/30 bg-warning/5 px-2.5 py-1.5"
          >
            <EngineMark engine={engine.engine} t={t} />
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("install.installing")}
            </Badge>
          </span>
        ))}

        {failed.map((engine) => (
          <span
            key={engine.engine}
            className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-2.5 py-1.5"
          >
            <EngineMark engine={engine.engine} t={t} />
            <Badge variant="destructive" className="font-normal">
              <TriangleAlert className="size-3" />
              {t("engineList.failed")}
            </Badge>
          </span>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        {summary ? (
          <p className="text-sm text-muted-foreground">{summary}</p>
        ) : null}

        {/* Plain `outline`, which is now a filled button everywhere — the fix
            went into the variant rather than into this one call site. */}
        <Button asChild variant="outline" size="sm">
          <Link href="/databases/monitor">
            <Activity className="size-4" />
            {t("monitor.link")}
          </Link>
        </Button>

        {/* Named, always. One candidate gets its own button ("Install
            MongoDB"); several get a menu of names. Neither opens a dialog that
            asks which — the click is the answer. */}
        {only ? (
          <ReasonTooltip reason={canManage ? null : t("noPermission")}>
            <Button
              variant="outline"
              size="sm"
              disabled={!canManage}
              onClick={() => setPending(only)}
            >
              {only.install_status === "failed" ? (
                <TriangleAlert className="size-4" />
              ) : (
                <Plus className="size-4" />
              )}
              {only.install_status === "failed"
                ? t("status.tryAgain")
                : t("install.addNamed", { name: t(`engines.${only.engine}`) })}
            </Button>
          </ReasonTooltip>
        ) : addable.length > 1 ? (
          <DropdownMenu>
            <ReasonTooltip reason={canManage ? null : t("noPermission")}>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" disabled={!canManage}>
                  <Plus className="size-4" />
                  {t("install.addEngine")}
                </Button>
              </DropdownMenuTrigger>
            </ReasonTooltip>
            <DropdownMenuContent align="end">
              {addable.map((engine) => (
                <DropdownMenuItem
                  key={engine.engine}
                  onSelect={() => setPending(engine)}
                >
                  {engine.install_status === "failed" ? (
                    <TriangleAlert className="size-4" />
                  ) : null}
                  {t(`engines.${engine.engine}`)}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null}
      </div>

      {progressEngine ? (
        <DatabaseInstallProgress
          progress={progressEngine.install_progress}
          label={t(`engines.${progressEngine.engine}`)}
          slow={slow && installingEngine === progressEngine.engine}
          pollIssue={pollIssue && installingEngine === progressEngine.engine}
          // Straight through the same confirmation the Install button uses:
          // it names the engine and states what an install costs, and a retry
          // is the same operation.
          onRetry={canManage ? () => setPending(progressEngine) : undefined}
          className="basis-full"
        />
      ) : failureMessage ? (
        <p className="basis-full text-xs leading-relaxed text-destructive">
          {failureMessage}
        </p>
      ) : pollIssue ? (
        <p className="basis-full text-xs text-warning">
          {t("install.pollIssue")}
        </p>
      ) : slow ? (
        <p className="basis-full text-xs text-muted-foreground">
          {t("install.takingLonger")}
        </p>
      ) : null}

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
    </div>
  );
}
