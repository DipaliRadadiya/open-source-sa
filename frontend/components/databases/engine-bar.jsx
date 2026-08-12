"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Activity, Plus } from "lucide-react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { InstallConfirm } from "@/components/databases/install-confirm";

/**
 * What is running, above the list it explains.
 *
 * Only rendered when at least one engine is reachable — installing, failed and
 * never-installed are the whole page rather than a line in this bar, and live
 * in `engine-state.jsx`.
 */
export function EngineBar({ engines = [], canManage, summary }) {
  const t = useTranslations("databases");
  const [pending, setPending] = useState(null);

  const running = engines.filter((engine) => engine.running);

  // A second SQL engine can never join one that is already here, so offering
  // "Add engine" on a MariaDB server with only MySQL left to install would open
  // a dialog whose every option is blocked.
  const hasSql = running.some((engine) => engine.driver === "sql");
  // At most one candidate in practice: a second SQL engine can never join one
  // already here, so this is Mongo alongside MariaDB.
  const addable = engines.find(
    (engine) =>
      engine.installable &&
      !engine.running &&
      !engine.install_status &&
      !(engine.driver === "sql" && hasSql),
  );

  return (
    <div className="flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
        {running.map((engine) => (
          <span key={engine.engine} className="flex items-center gap-2 text-sm">
            <span className="font-medium">{t(`engines.${engine.engine}`)}</span>
            {engine.version ? (
              <span className="font-mono text-xs text-muted-foreground">
                {engine.version}
              </span>
            ) : null}
            <Badge variant="success" className="font-normal">
              {t("status.running")}
            </Badge>
          </span>
        ))}
      </div>

      <div className="flex items-center gap-3">
        {/* A caption beside the engine, not a stat tile — nobody makes a
            decision from "4 databases". */}
        {summary ? (
          <p className="text-sm text-muted-foreground">{summary}</p>
        ) : null}

        {/* The health screen has no sidebar entry of its own — this is the
            way in, next to the engine it reports on. */}
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
              <Plus className="size-4" />
              {t("install.addEngine")}
            </Button>
          </ReasonTooltip>
        ) : null}
      </div>

      <InstallConfirm
        engine={pending}
        open={pending !== null}
        onOpenChange={(next) => !next && setPending(null)}
      />
    </div>
  );
}
