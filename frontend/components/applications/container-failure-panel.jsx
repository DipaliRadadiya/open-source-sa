"use client";

import { useState } from "react";
import { useFormatter, useNow, useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleAlert, FileText, Loader2, Plug, RotateCw, SlidersHorizontal } from "lucide-react";
import Link from "@/components/ui/app-link";
import { cn } from "@/lib/utils";
import { pullContainerImage, updateContainerSettings } from "@/lib/api/docker";
import { retryProvisioning } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { portFixFor } from "@/lib/applications/container-state";
import { useImageInspection } from "@/lib/docker/use-image-inspection";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { FormModal } from "@/components/ui/form-modal";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

function validPort(value) {
  const port = Number(value);
  return Number.isInteger(port) && port >= 1 && port <= 65535 ? port : null;
}

/**
 * Why a container site is down and the fixes for it, from DS-03's
 * `last_failure`. The reason sentence itself is in the page header.
 *
 * A failed first deploy (`status: failed`) only saves the port — the site has
 * no running container to recreate — so the fix is a save then a Retry, which
 * the provisioning card below follows. A running site's save recreates the
 * container and waits for it to answer, so it answers in the same request.
 */
export function ContainerFailurePanel({
  application,
  canManageContainer = false,
  canRetry = false,
  canSeeLogs = false,
  canEditEnv = false,
  className,
}) {
  const t = useTranslations("applications.containerFailure");
  const format = useFormatter();
  const now = useNow({ updateInterval: 30000 });
  const { refreshAndWait } = useRefresh();
  const [busy, setBusy] = useState(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [portValue, setPortValue] = useState("");
  const [portError, setPortError] = useState(null);

  const failure = application.last_failure ?? {};
  const firstDeploy = application.status === "failed";
  const inspected = useImageInspection(application.image, application.registry_id);
  const detectedPort =
    inspected?.found && inspected.port_confidence !== "none" ? (inspected.suggested_port ?? null) : null;
  const fixPort = portFixFor(application, detectedPort);
  const canRedeploy = firstDeploy ? canRetry : canManageContainer;
  const log = (failure.log ?? "").trim();
  const checkedAt = failure.at ? Date.parse(failure.at) || null : null;

  async function redeploy() {
    if (firstDeploy) {
      await retryProvisioning(application.id);
      await refreshAndWait();
      toast.success(t("queued"));
      return;
    }
    try {
      await pullContainerImage(application.id);
      await refreshAndWait();
      toast.success(t("fixed"));
    } catch (error) {
      // The new reason is on the site now; show it, not the old one.
      await refreshAndWait();
      throw error;
    }
  }

  async function applyPort(port) {
    try {
      await updateContainerSettings(application.id, { container_port: port });
    } catch (error) {
      // `saved: true` — the port is stored, only the restart on it failed.
      if (error.response?.data?.saved) await refreshAndWait();
      throw error;
    }
    if (firstDeploy) {
      await retryProvisioning(application.id);
      await refreshAndWait();
      toast.success(t("queuedPort", { port }));
      return;
    }
    await refreshAndWait();
    toast.success(t("fixed"));
  }

  async function run(kind, action) {
    setBusy(kind);
    try {
      await action();
      return true;
    } catch (error) {
      toast.error(apiMessage(error, t("stillFailing")));
      return false;
    } finally {
      setBusy(null);
    }
  }

  function openPortDialog() {
    setPortValue(String(detectedPort ?? application.container_port ?? ""));
    setPortError(null);
    setDialogOpen(true);
  }

  async function submitPort(event) {
    event.preventDefault();
    const port = validPort(portValue);
    if (!port) {
      setPortError(t("portDialog.invalid"));
      return;
    }
    if (await run("dialog", () => applyPort(port))) setDialogOpen(false);
  }

  const working = busy !== null;
  const title = application.container_status === "restarting" ? t("titleRestarting") : t("title");

  return (
    <Card className={cn("gap-0 overflow-hidden border-destructive/30 py-0 shadow-sm", className)}>
      <div className="flex items-start gap-3 border-b px-5 py-4">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-destructive/10">
          <CircleAlert className="size-6 text-destructive" aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-medium">{title}</p>
          <p className="text-sm text-muted-foreground">{t("lead")}</p>
          {checkedAt ? (
            // Server and browser clocks render "N seconds ago" differently.
            <p className="text-xs text-muted-foreground" suppressHydrationWarning>
              {t("checkedAt", { time: format.relativeTime(Math.min(checkedAt, now.getTime()), now) })}
            </p>
          ) : null}
        </div>
      </div>

      <CardContent className="space-y-4 px-5 py-4">
        {canSeeLogs ? (
          <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm font-medium">{t("logTitle")}</p>
              {log ? <CopyButton value={log} label={t("copyLog")} /> : null}
            </div>
            {log ? (
              <pre
                tabIndex={0}
                aria-label={t("logTitle")}
                className="max-h-72 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap break-all"
              >
                {log}
              </pre>
            ) : (
              <p className="text-sm text-muted-foreground">{t("logEmpty")}</p>
            )}
          </div>
        ) : null}

        <div className="flex flex-col gap-2 border-t pt-4 sm:flex-row sm:flex-wrap">
          {fixPort && canManageContainer ? (
            <Button onClick={() => run("usePort", () => applyPort(fixPort))} disabled={working}>
              {busy === "usePort" ? <Loader2 className="size-4 animate-spin" /> : <Plug className="size-4" />}
              {busy === "usePort" ? t("applying", { port: fixPort }) : t("usePort", { port: fixPort })}
            </Button>
          ) : null}
          {canManageContainer ? (
            <Button variant="outline" onClick={openPortDialog} disabled={working}>
              <Plug className="size-4" />
              {t("changePort")}
            </Button>
          ) : null}
          {canEditEnv ? (
            <Button asChild variant="outline">
              <Link href={`/applications/${application.id}/environment`}>
                <SlidersHorizontal className="size-4" />
                {t("editEnv")}
              </Link>
            </Button>
          ) : null}
          {canSeeLogs ? (
            <Button asChild variant="outline">
              <Link href={`/applications/${application.id}/logs`}>
                <FileText className="size-4" />
                {t("viewLogs")}
              </Link>
            </Button>
          ) : null}
          {canRedeploy ? (
            <Button
              variant={fixPort && canManageContainer ? "outline" : "default"}
              onClick={() => run("redeploy", redeploy)}
              disabled={working}
            >
              {busy === "redeploy" ? <Loader2 className="size-4 animate-spin" /> : <RotateCw className="size-4" />}
              {busy === "redeploy" ? t("redeploying") : t("redeploy")}
            </Button>
          ) : null}
        </div>
        {working && !firstDeploy ? (
          <p className="text-xs text-muted-foreground" aria-live="polite">
            {t("waitNote")}
          </p>
        ) : null}
      </CardContent>

      <FormModal
        open={dialogOpen}
        onOpenChange={(open) => {
          if (busy !== "dialog") setDialogOpen(open);
        }}
        asForm
        onSubmit={submitPort}
        icon={Plug}
        title={t("portDialog.title")}
        description={
          detectedPort ? t("portDialog.descriptionDetected", { port: detectedPort }) : t("portDialog.description")
        }
        footer={
          <>
            <Button type="button" variant="outline" disabled={busy === "dialog"} onClick={() => setDialogOpen(false)}>
              {t("portDialog.cancel")}
            </Button>
            <Button type="submit" disabled={busy === "dialog"}>
              {busy === "dialog" ? <Loader2 className="size-4 animate-spin" /> : null}
              {busy === "dialog" ? t("redeploying") : t("portDialog.save")}
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          <Label htmlFor="container-fix-port">{t("portDialog.label")}</Label>
          <Input
            id="container-fix-port"
            type="number"
            inputMode="numeric"
            min={1}
            max={65535}
            value={portValue}
            onChange={(event) => {
              setPortValue(event.target.value);
              setPortError(null);
            }}
            aria-invalid={Boolean(portError)}
            disabled={busy === "dialog"}
            className="font-mono"
          />
          {portError ? (
            <p className="text-sm text-destructive">{portError}</p>
          ) : (
            <p className="text-xs text-muted-foreground">{t("portDialog.hint")}</p>
          )}
        </div>
        {busy === "dialog" && !firstDeploy ? (
          <p className="text-xs text-muted-foreground" aria-live="polite">
            {t("waitNote")}
          </p>
        ) : null}
      </FormModal>
    </Card>
  );
}
