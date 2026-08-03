"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations, useFormatter } from "next-intl";
import { Activity, Loader2, Square } from "lucide-react";
import { getProcesses, killProcess } from "@/lib/api/databases";
import { dbProcessesResponseSchema } from "@/lib/schemas/database";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

const POLL_MS = 5000;

/**
 * A long-running query is what "the site is slow" usually turns out to be, so
 * anything past this is marked rather than left for the reader to spot in a
 * column of numbers.
 */
const SLOW_SECONDS = 10;

/**
 * What the engine is doing right now, refreshed every five seconds.
 *
 * Idle connections are the majority on any real server and say nothing, so they
 * are counted rather than listed — a table of forty `Sleep` rows buries the one
 * query that is actually stuck.
 */
export function ProcessList({ engine, processes: initial = [], canManage }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  const router = useRouter();
  const [polled, setPolled] = useState(null);
  const [killing, setKilling] = useState(null);
  const [pending, setPending] = useState(false);

  const all = polled ?? initial;
  const idle = all.filter((p) => (p.command ?? "").toLowerCase() === "sleep");
  const active = all.filter((p) => (p.command ?? "").toLowerCase() !== "sleep");

  useEffect(() => {
    const controller = new AbortController();
    const id = setInterval(async () => {
      try {
        const { data } = await getProcesses(engine, { signal: controller.signal });
        const parsed = dbProcessesResponseSchema.safeParse(data);
        if (parsed.success) setPolled(parsed.data.processes);
      } catch {
        // A dropped poll isn't worth reporting; the next one runs in 5s.
      }
    }, POLL_MS);

    return () => {
      controller.abort();
      clearInterval(id);
    };
  }, [engine]);

  async function kill() {
    setPending(true);
    try {
      await killProcess(killing.id, engine);
      toast.success(t("killed"));
      setKilling(null);
      // Drop back to the server's list so the row disappears from the same
      // place everything else on this page comes from.
      setPolled(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("killFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="flex items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
              <Activity className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t("processes")}
              </h2>
              <p className="text-sm text-muted-foreground">
                {t("processesDescription")}
              </p>
            </div>
          </div>

          {/* Idle connections are noise in a list and information in a count. */}
          {idle.length > 0 ? (
            <span className="text-sm text-muted-foreground">
              {t("idleCount", { count: idle.length })}
            </span>
          ) : null}
        </div>

        <CardContent className="px-5 py-0">
          {active.length === 0 ? (
            <div className="py-8 text-center">
              <p className="text-sm text-muted-foreground">{t("noProcesses")}</p>
            </div>
          ) : (
            <div className="divide-y">
              {active.map((process) => {
                const slow = (process.time ?? 0) >= SLOW_SECONDS;
                return (
                  <div
                    key={process.id}
                    className="flex items-start justify-between gap-3 py-3"
                  >
                    <div className="min-w-0 space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge
                          variant={slow ? "warning" : "secondary"}
                          className="font-normal tabular-nums"
                        >
                          {t("seconds", {
                            seconds: format.number(process.time ?? 0),
                          })}
                        </Badge>
                        <span className="text-sm">{process.command}</span>
                        {process.db ? (
                          <span className="font-mono text-xs text-muted-foreground">
                            {process.db}
                          </span>
                        ) : null}
                        <span className="text-xs text-muted-foreground">
                          {process.user}
                          {process.host ? ` · ${process.host}` : ""}
                        </span>
                      </div>

                      {/* The actual statement, which is the whole reason to
                          look at this list. Wrapped, not truncated to one
                          line — a query cut at 80 characters tells you
                          nothing about what it was doing. */}
                      {process.query ? (
                        <p className="font-mono text-xs break-all text-muted-foreground">
                          {process.query}
                        </p>
                      ) : process.state ? (
                        <p className="text-xs text-muted-foreground">
                          {process.state}
                        </p>
                      ) : null}
                    </div>

                    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={!canManage}
                        className="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => setKilling(process)}
                      >
                        <Square className="size-4" />
                        {t("kill")}
                      </Button>
                    </ReasonTooltip>
                  </div>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={killing !== null}
        onOpenChange={(next) => !next && setKilling(null)}
        icon={Square}
        tone="destructive"
        title={t("killTitle")}
        description={t("killDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={
          pending ? (
            <>
              <Loader2 className="size-4 animate-spin" />
              {t("killing")}
            </>
          ) : (
            t("kill")
          )
        }
        pending={pending}
        onConfirm={kill}
      />
    </>
  );
}
