"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Activity, Loader2, Plus, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { InstallConfirm } from "@/components/databases/install-confirm";
import { useEngineInstallPolling } from "@/components/databases/use-engine-install-polling";
import {
  findInstallCandidate,
  findPresentSqlEngine,
  isSqlEngine,
} from "@/lib/databases/install-lifecycle";

/**
 * What is running, plus any second engine currently being added.
 *
 * This surface remains mounted whenever at least one engine is reachable, so
 * it owns the complete lifecycle for an additional engine. Closing the install
 * confirmation must not close the only evidence that work was queued.
 */
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
  const addable = findInstallCandidate(list);
  const failureMessage = failed.find(
    (engine) => engine.install_message,
  )?.install_message;
  const hasPresentSql = Boolean(findPresentSqlEngine(list));
  const choosingSql = isSqlEngine(pending) && !hasPresentSql;

  return (
    <div className="flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
        {running.map((engine) => (
          <span
            key={engine.engine}
            className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
          >
            <span className="font-medium">{t(`engines.${engine.engine}`)}</span>
            {engine.version ? (
              // A version is one token. Let the row wrap around it rather than
              // splitting a distro suffix into what looks like a second value.
              <span className="font-mono text-xs whitespace-nowrap text-muted-foreground">
                {engine.version}
              </span>
            ) : null}
            <Badge variant="success" className="font-normal">
              {t("status.running")}
            </Badge>
          </span>
        ))}

        {installing.map((engine) => (
          <span
            key={engine.engine}
            className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
          >
            <span className="font-medium">{t(`engines.${engine.engine}`)}</span>
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("install.installing")}
            </Badge>
          </span>
        ))}

        {failed.map((engine) => (
          <span
            key={engine.engine}
            className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
          >
            <span className="font-medium">{t(`engines.${engine.engine}`)}</span>
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

        {addable ? (
          <ReasonTooltip reason={canManage ? null : t("noPermission")}>
            <Button
              variant="outline"
              size="sm"
              disabled={!canManage}
              onClick={() => setPending(addable)}
            >
              {addable.install_status === "failed" ? (
                <TriangleAlert className="size-4" />
              ) : (
                <Plus className="size-4" />
              )}
              {addable.install_status === "failed"
                ? t("status.tryAgain")
                : t("install.addEngine")}
            </Button>
          </ReasonTooltip>
        ) : null}
      </div>

      {failureMessage ? (
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
        engine={choosingSql ? null : pending ?? null}
        open={pending !== null}
        choosing={choosingSql}
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
