"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Activity, Loader2, Plus, TriangleAlert, KeyRound } from "lucide-react";
import Link from "@/components/ui/app-link";
import { Badge } from "@/components/ui/badge";
import { EngineLogo } from "@/components/databases/engine-logo";
import { engineLogo } from "@/lib/databases/engine-logo";
import { shortVersion } from "@/lib/databases/short-version";
import { Button } from "@/components/ui/button";
import { RootPasswordDialog } from "@/components/databases/root-password-dialog";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
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
import { HoverHint } from "@/components/ui/hover-hint";
import { RemoteAccessNotes } from "@/components/databases/remote-access-note";

function EngineMark({ engine, status, t }) {
  const name = t(`engines.${engine}`);
  const wordmark = engineLogo(engine)?.wordmark;
  return (
    <>
      <EngineLogo engine={engine} className="!h-4 w-auto max-w-16" />
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

export function EngineBar({ engines = [], connections = [], remoteAccess = [], canManage, summary }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [rootFor, setRootFor] = useState(null);
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
  const stopped = list.filter(
    (engine) =>
      engine.installed &&
      !engine.running &&
      !["installing", "failed"].includes(engine.install_status),
  );

  const rootEngines = running.filter((engine) => engine.engine === "mysql" || engine.engine === "mariadb");

  const addable = findInstallCandidates(list);
  const only = addable.length === 1 ? addable[0] : null;
  const progressEngine =
    installing.find((engine) => engine.install_progress) ??
    failed.find((engine) => engine.install_progress) ??
    null;
  const failureMessage = progressEngine
    ? null
    : failed.find((engine) => engine.install_message)?.install_message;

  return (
    <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-e1 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex flex-wrap items-center gap-2">
        {running.map((engine) => (
          <HoverHint key={engine.engine} content={engine.version ?? undefined}><span
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
          </span></HoverHint>
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

        {stopped.map((engine) => (
          <span
            key={engine.engine}
            className="flex items-center gap-2 rounded-lg border border-warning/30 bg-warning/5 px-2.5 py-1.5"
          >
            <EngineMark engine={engine.engine} t={t} />
            <Badge variant="warning" className="font-normal">
              <TriangleAlert className="size-3" />
              {t("engineList.unreachable")}
            </Badge>
            <Link
              href="/services"
              className="text-xs font-medium whitespace-nowrap underline underline-offset-2"
            >
              {t("status.checkServices")}
            </Link>
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

        <Button asChild variant="outline" size="sm">
          <Link href="/databases/monitor">
            <Activity className="size-4" />
            {t("monitor.link")}
          </Link>
        </Button>

        {rootEngines.length === 1 ? (
          <ReasonTooltip reason={canManage ? null : DENIED}>
            <Button variant="outline" size="sm" disabled={!canManage} onClick={() => setRootFor(rootEngines[0].engine)}>
              <KeyRound className="size-4" />
              {t("rootPassword.action")}
            </Button>
          </ReasonTooltip>
        ) : rootEngines.length > 1 ? (
          <DropdownMenu>
            <ReasonTooltip reason={canManage ? null : DENIED}>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" disabled={!canManage}>
                  <KeyRound className="size-4" />
                  {t("rootPassword.action")}
                </Button>
              </DropdownMenuTrigger>
            </ReasonTooltip>
            <DropdownMenuContent align="end">
              {rootEngines.map((engine) => (
                <DropdownMenuItem key={engine.engine} onSelect={() => setRootFor(engine.engine)}>
                  {t(`engines.${engine.engine}`)}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null}

        {only ? (
          <ReasonTooltip reason={canManage ? null : DENIED}>
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
            <ReasonTooltip reason={canManage ? null : DENIED}>
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

      <RemoteAccessNotes states={remoteAccess} canManage={canManage} />

      <RootPasswordDialog
        engine={rootFor}
        connection={connections.find((c) => c.engine === rootFor)}
        open={rootFor !== null}
        onOpenChange={(next) => !next && setRootFor(null)}
      />

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
