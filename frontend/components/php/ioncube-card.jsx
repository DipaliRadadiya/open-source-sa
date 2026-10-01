"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Info, Loader2, ShieldCheck, Trash2, TriangleAlert } from "lucide-react";

import { installIonCube, removeIonCube } from "@/lib/api/php";
import { apiMessage } from "@/lib/api/error-message";
import { isInFlight } from "@/lib/runtime/in-flight";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * Install or remove the ionCube Loader for one PHP version.
 *
 * Its own card, not an extensions row: it is a vendor `.so` declared as a
 * `zend_extension`, not an apt package. ionCube publishes no loader for PHP
 * 8.0, so `unsupported` is stated instead of letting the button earn a 422.
 */
export function IonCubeCard({ version, ioncube, canManage, failed = false }) {
  const t = useTranslations("php.ioncube");
  // Reuses existing strings under `php`.
  const tp = useTranslations("php");
  const { refreshAndWait } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);

  if (failed || !ioncube) {
    return (
      <Card className="gap-0 py-0">
        <CardContent className="px-5 py-4 text-sm text-muted-foreground">
          {t("loadFailed")}
        </CardContent>
      </Card>
    );
  }

  const installing = isInFlight(ioncube.status);
  const installFailed = ioncube.status === "failed";
  const { supported, installed } = ioncube;
  // Installed outside the panel: both buttons would earn a 422, so neither is
  // offered.
  const external = installed && ioncube.source === "external";
  const canAct = !external && (supported || installed);

  async function install() {
    setBusy(true);
    try {
      await installIonCube(version);
      await refreshAndWait();
      // Always 202 (the archive is ~29 MB), so the message says it has started.
      toast.success(t("installStarted", { version }));
    } catch (error) {
      toast.error(apiMessage(error, t("installFailed")));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    try {
      await removeIonCube(version);
      await refreshAndWait();
      toast.success(t("removed", { version }));
      setConfirmOpen(false);
    } catch (error) {
      toast.error(apiMessage(error, t("removeFailed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="gap-0 py-0">
      <CardContent className="flex flex-col gap-4 px-5 py-4 @2xl:flex-row @2xl:items-start @2xl:justify-between">
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="flex shrink-0 items-center justify-center text-muted-foreground">
              <ShieldCheck className="size-4" />
            </span>
            <span className="font-medium">{t("title")}</span>
            {/* Installing wins over installed: during a reinstall both are true. */}
            {installing ? (
              <Badge variant="warning" className="font-normal">
                {tp("versions.statusInstalling")}
              </Badge>
            ) : installed ? (
              <Badge variant="success" className="font-normal">
                {t("installed")}
              </Badge>
            ) : supported ? (
              <Badge variant="muted" className="font-normal">
                {t("notInstalled")}
              </Badge>
            ) : null}
            {/* Read from PHP itself, so it is the loader actually running. */}
            {installed && ioncube.loader_version ? (
              <span className="font-mono text-xs text-muted-foreground">
                {t("loaderVersion", { version: ioncube.loader_version })}
              </span>
            ) : null}
          </div>

          <p className="text-xs leading-relaxed text-muted-foreground">{t("subtitle")}</p>

          {external ? (
            <p className="flex items-start gap-1.5 text-xs leading-relaxed text-muted-foreground">
              <Info className="mt-0.5 size-3.5 shrink-0" />
              {t("external", { version })}
            </p>
          ) : !supported && !installed ? (
            <p className="flex items-start gap-1.5 text-xs leading-relaxed text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("unsupported", { version })}
            </p>
          ) : installing ? (
            <p className="text-xs leading-relaxed text-muted-foreground">{t("installing")}</p>
          ) : installFailed ? (
            /* The server's sentence names the cause (checksum, download);
               `reason` is a code. */
            <p className="flex items-start gap-1.5 text-xs leading-relaxed text-destructive">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              <span>
                {ioncube.message ?? t("installFailed")}
                {ioncube.reference ? (
                  <span className="ml-1.5 font-mono whitespace-nowrap text-muted-foreground">{ioncube.reference}</span>
                ) : null}
              </span>
            </p>
          ) : (
            <p className="text-xs leading-relaxed text-muted-foreground">{t("hint")}</p>
          )}
        </div>

        {canAct ? (
          <div className="shrink-0">
            <ReasonTooltip reason={canManage ? null : tp("noPermission")}>
              {installed ? (
                /*
                 * `destructive`, like every other button that removes
                 * something from the server; the confirmation dialog remains
                 * the safety net.
                 */
                <Button
                  variant="destructive"
                  onClick={() => setConfirmOpen(true)}
                  disabled={!canManage || busy || installing}
                >
                  <Trash2 className="size-4" />
                  {t("remove")}
                </Button>
              ) : (
                <Button onClick={install} disabled={!canManage || busy || installing}>
                  {busy || installing ? <Loader2 className="size-4 animate-spin" /> : null}
                  {installFailed ? tp("versions.retry") : t("install")}
                </Button>
              )}
            </ReasonTooltip>
          </div>
        ) : null}
      </CardContent>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        icon={TriangleAlert}
        tone="destructive"
        title={t("removeConfirmTitle")}
        description={t("removeConfirmBody", { version })}
        cancelLabel={tp("versions.confirmCancel")}
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        pending={busy}
        onConfirm={remove}
      />
    </Card>
  );
}
