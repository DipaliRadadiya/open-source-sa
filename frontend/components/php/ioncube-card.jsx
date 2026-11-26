"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Info, Loader2, Trash2, TriangleAlert } from "lucide-react";

import { installIonCube, removeIonCube } from "@/lib/api/php";
import { apiMessage } from "@/lib/api/error-message";
import { isInFlight } from "@/lib/runtime/in-flight";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";

export function IonCubeCard({ version, ioncube, canManage, failed = false }) {
  const t = useTranslations("php.ioncube");
  const tp = useTranslations("php");
  const { refreshAndWait } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [safetyCopy, setSafetyCopy] = useState(true);
  const tc = useTranslations("common");

  if (failed || !ioncube) {
    return (
      <Card className="@container gap-0 py-0">
        <CardContent className="px-5 py-4 text-sm text-muted-foreground">
          {t("loadFailed")}
        </CardContent>
      </Card>
    );
  }

  const installing = isInFlight(ioncube.status);
  const installFailed = ioncube.status === "failed";
  const { supported, installed } = ioncube;
  const external = installed && ioncube.source === "external";
  const canAct = !external && (supported || installed);

  async function install() {
    setBusy(true);
    try {
      await installIonCube(version, { safetyCopy });
      await refreshAndWait();
      setSafetyCopy(true);
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
      await removeIonCube(version, { safetyCopy });
      await refreshAndWait();
      toast.success(t("removed", { version }));
      setConfirmOpen(false);
      setSafetyCopy(true);
    } catch (error) {
      toast.error(apiMessage(error, t("removeFailed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="@container gap-0 py-0">
      <CardContent className="flex flex-col gap-4 px-5 py-4 @2xl:flex-row @2xl:items-start @2xl:justify-between">
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-medium">{t("title")}</span>
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
            <p className="flex items-start gap-1.5 text-xs leading-relaxed text-destructive">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              <span>
                {ioncube.message ?? t("installFailed")}
                {ioncube.reference ? (
                  <span className="ml-1.5 font-mono whitespace-nowrap text-muted-foreground">{ioncube.reference}</span>
                ) : null}
              </span>
            </p>
          ) : null}

          {canAct && canManage && !installed && !installing ? (
            <div className="space-y-2 pt-2">
              <SafetyCopySwitch
                id={`ioncube-safety-copy-${version}`}
                compact
                checked={safetyCopy}
                onCheckedChange={setSafetyCopy}
                disabled={busy}
                label={tc("keepSettingsCopy")}
              />
              {!safetyCopy ? (
                <Caution tone="destructive">
                  <p>{tc("keepSettingsCopyOff")}</p>
                </Caution>
              ) : null}
            </div>
          ) : null}
        </div>

        {canAct ? (
          <div className="shrink-0">
            <ReasonTooltip reason={canManage ? null : DENIED}>
              {installed ? (
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
        onOpenChange={(next) => {
          setConfirmOpen(next);
          if (!next) setSafetyCopy(true);
        }}
        icon={TriangleAlert}
        tone="destructive"
        title={t("removeConfirmTitle")}
        description={t("removeConfirmBody", { version })}
        cancelLabel={tp("versions.confirmCancel")}
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        pending={busy}
        onConfirm={remove}
      >
        <SafetyCopySwitch
          id={`ioncube-remove-safety-copy-${version}`}
          checked={safetyCopy}
          onCheckedChange={setSafetyCopy}
          disabled={busy}
          label={tc("keepSettingsCopy")}
        />
        {!safetyCopy ? (
          <Caution tone="destructive" size="md">
            <p>{tc("keepSettingsCopyOff")}</p>
          </Caution>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}
