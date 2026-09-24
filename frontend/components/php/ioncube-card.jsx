"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
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
 * Its own card rather than a row in the extensions list. Every row there is an
 * apt package toggled with phpenmod; this is a closed-source `.so` fetched
 * from the vendor and declared as a `zend_extension` at an absolute path. One
 * row whose Install means something entirely different from every other row's
 * is worse than a card that admits it is a different kind of thing.
 *
 * Six states, and `unsupported` is the one worth spelling out: ionCube
 * publishes no loader for PHP 8.0 and this panel still offers 8.0, so the card
 * says so instead of letting the button earn a 422.
 */
export function IonCubeCard({ version, ioncube, canManage, failed = false }) {
  const t = useTranslations("php.ioncube");
  // Reused rather than re-worded: these three already exist under `php`.
  const tp = useTranslations("php");
  const router = useRouter();
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
  // Installed outside the panel. Both buttons would earn a 422, so neither is
  // offered.
  const external = installed && ioncube.source === "external";
  const canAct = !external && (supported || installed);

  async function install() {
    setBusy(true);
    try {
      await installIonCube(version);
      // Always 202: the archive is ~29 MB, so the message says it has started
      // rather than that it is done.
      toast.success(t("installStarted", { version }));
      router.refresh();
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
      toast.success(t("removed", { version }));
      setConfirmOpen(false);
      router.refresh();
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
            {/* The state, said once. Installing wins over installed: during a
                reinstall both are true and "Installing" is the newer fact. */}
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
            {/* Read out of PHP itself, so it is the loader actually running. */}
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
            /* The server's own sentence: it names what went wrong — a checksum
               that did not match, a download that failed — and ours could only
               say that something did. `reason` is a code, not this. */
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
                 * `destructive`, like every other button that takes something
                 * off the server — "Remove certificate" one screen over, and 39
                 * other places in the panel.
                 *
                 * This was `outline`, so an uninstall looked exactly like a
                 * neutral action. Reported as "not even looks like remove
                 * button", which is precisely the failure: the only thing
                 * saying it was destructive was the word, and the word is the
                 * part people skim.
                 *
                 * The confirmation dialog behind it is unchanged — the styling
                 * is not the safety net, it is the warning before the net.
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
