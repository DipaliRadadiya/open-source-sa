"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Activity, Database, Loader2, Plug, TriangleAlert } from "lucide-react";
import { getEngines } from "@/lib/api/databases";
import { enginesResponseSchema } from "@/lib/schemas/database";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { InstallConfirm } from "@/components/databases/install-confirm";
import { ConnectionDialog } from "@/components/databases/connection-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

const POLL_MS = 5000;

/**
 * Retrying these can never change the outcome: the port belongs to the other
 * engine, or root cannot be reached at all. The fix is a decision, and the
 * API's message says what it is — so no button pretends otherwise.
 */
const NOT_RETRYABLE = [
  "port_in_use_by_mysql",
  "port_in_use_by_mariadb",
  "root_unreachable",
];

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
 * install is one row's status plus the server's sentence underneath it.
 */
export function EngineState({ engines = [], connections = [], canManage }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [polled, setPolled] = useState(null);
  const [pending, setPending] = useState(null);
  const [connecting, setConnecting] = useState(null);

  const list = polled ?? engines;
  const inFlight = list.find((engine) => engine.install_status === "installing");

  // The API now says this outright. This used to be inferred from another
  // engine every failure naming the port owner (`port_in_use_by_mariadb`),
  // because mariadb reported running:false version:null while MySQL was being
  // told MariaDB owned the port.
  const present = (engine) => Boolean(engine.installed || engine.running);

  // Present on the server, whether or not the panel can talk to it. A second
  // SQL engine can never join it.
  const sqlPresent = list.find(
    (engine) => engine.driver === "sql" && present(engine),
  );

  useEffect(() => {
    if (!inFlight) return;

    const controller = new AbortController();
    const id = setInterval(async () => {
      try {
        const { data } = await getEngines({ signal: controller.signal });
        const parsed = enginesResponseSchema.safeParse(data);
        if (!parsed.success) return;

        if (parsed.data.engines.some((e) => e.install_status === "installing")) {
          setPolled(parsed.data.engines);
        } else {
          // Finished either way — re-render from the server so a success swaps
          // this whole screen for the databases list.
          setPolled(null);
          router.refresh();
        }
      } catch {
        // A dropped poll isn't worth reporting; the next one runs in 5s.
      }
    }, POLL_MS);

    return () => {
      controller.abort();
      clearInterval(id);
    };
  }, [inFlight, router]);

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          {/* min-w-48 so the heading drops the button to its own row on a phone
              instead of shrinking to a one-word-per-line column. */}
          <div className="flex min-w-48 flex-1 items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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

          {/* Present even with nothing running. A screen you open to find out
              why the database is down must not disappear when it goes down —
              and the page says so plainly when there is nothing to report. */}
          <Button asChild variant="outline" size="sm" className="shrink-0">
            <Link href="/databases/monitor">
              <Activity className="size-4" />
              {t("monitor.link")}
            </Link>
          </Button>
        </div>

        <CardContent className="divide-y px-5 py-0">
          {list.map((engine) => (
            <EngineRow
              key={engine.engine}
              engine={engine}
              canManage={canManage}
              sqlPresent={sqlPresent}
              present={present(engine)}
              busy={Boolean(inFlight)}
              onInstall={() => setPending(engine)}
              onConnection={() => setConnecting(engine)}
            />
          ))}
        </CardContent>
      </Card>

      <InstallConfirm
        engine={pending}
        open={pending !== null}
        choosing={pending?.driver === "sql" && !sqlPresent}
        onOpenChange={(next) => !next && setPending(null)}
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

function EngineRow({
  engine,
  canManage,
  sqlPresent,
  present,
  busy,
  onInstall,
  onConnection,
}) {
  const t = useTranslations("databases");
  const name = t(`engines.${engine.engine}`);
  const failed = engine.install_status === "failed";
  const installing = engine.install_status === "installing";
  const conflicted =
    engine.driver === "sql" && sqlPresent && sqlPresent !== engine;

  // Retrying these can only fail the same way, so the row offers nothing.
  const deadEnd = failed && NOT_RETRYABLE.includes(engine.install_reason);

  /* No button at all when nothing could come of pressing it: an engine the
   * panel can't install, one already here, one whose failure retrying cannot
   * fix, or the other SQL engine. A disabled primary button is still the
   * loudest thing in the row — it draws the eye to the one action that is
   * impossible. The row's own text already says why. */
  const useless =
    !engine.installable || present || deadEnd || conflicted;

  // Reasons that are worth a tooltip on a button that could otherwise work.
  const blocked = !canManage
    ? t("noPermission")
    : busy && !installing
      ? t("install.oneAtATime")
      : null;

  // The explanation under the name: the server's own words for a failure, or
  // ours for a state it never reports.
  const note = failed
    ? engine.install_message
    : !engine.installable
      ? t("install.notInstallable")
      : conflicted
        ? t("install.sqlConflict", {
            other: t(`engines.${sqlPresent.engine}`),
          })
        : present
          ? t("engineList.unreachableHint")
          : null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3.5">
      <div className="min-w-0 space-y-1">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium">{name}</span>
          <EngineBadge engine={engine} present={present} />
        </div>

        {note ? (
          <p className="text-xs leading-relaxed text-muted-foreground">{note}</p>
        ) : null}
      </div>

      {/* An engine that is here but silent has two possible causes, and both
          have somewhere to go: the service isn't running (Services), or the
          panel's own sign-in is wrong (Connection). Without these the page is a
          dead end — rows of bad news and nothing to press. */}
      {!installing && useless && present ? (
        <div className="flex items-center gap-2">
          <Button asChild variant="ghost" size="sm">
            <Link href="/services">{t("status.checkServices")}</Link>
          </Button>
          {canManage ? (
            <Button variant="outline" size="sm" onClick={onConnection}>
              <Plug className="size-4" />
              {t("connection.action")}
            </Button>
          ) : null}
        </div>
      ) : null}

      {installing || useless ? null : (
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
      )}
    </div>
  );
}

function EngineBadge({ engine, present }) {
  const t = useTranslations("databases");

  if (engine.install_status === "installing") {
    return (
      <Badge variant="secondary" className="font-normal">
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
    <Badge variant="secondary" className="font-normal">
      {t("engineList.notInstalled")}
    </Badge>
  );
}
