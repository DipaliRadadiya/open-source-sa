"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Trash2 } from "lucide-react";
import { RuntimeStatusBadge, removeFailed, versionState } from "@/components/runtime/version-status";
import { SupportCell, UsedByCell, VersionCell } from "@/components/runtime/versions-table";
import { SUBGRID } from "@/lib/runtime/version-rows";

// Long states ("Installation fehlgeschlagen") wrap inside the column instead of spilling.
const WRAP = "h-auto text-left whitespace-normal";
import { cn } from "@/lib/utils";
import {
  setDefaultNodeVersion,
  removeNodeVersion,
  installNodeVersion,
  updateNodeNpm,
} from "@/lib/api/node";
import { failedWithNothingInstalled } from "@/lib/runtime/failed-install";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { InstallOutput } from "@/components/runtime/install-output";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// One row of the Installed versions table: PHP's row minus the panel-version rule, plus npm.
export function VersionSummary({ version, canManage, lifecycleAvailable = false }) {
  const t = useTranslations("node");
  const { refreshAndWait } = useRefresh();
  const [confirming, setConfirming] = useState(false);
  // All buttons disable together (one version, no racing); only the pressed one spins.
  const [running, setRunning] = useState(null);
  const pending = running !== null;
  // Read from props: the card re-reads after an update, and a local copy would go
  // stale.
  const npm = version.npm_version ?? null;
  // `npm_latest` is per Node version, compared by the API (strings misorder versions).
  // `npm_update_available` is also false with an empty catalog, so "current" needs a known latest.
  const npmLatest = version.npm_latest ?? null;
  const npmKnown = Boolean(npm && npmLatest);
  const npmBehind = !npmKnown
    ? false
    : typeof version.npm_update_available === "boolean"
      ? version.npm_update_available
      : // An API that predates the flag. Inequality is weaker than semver but
        // it cannot invent an update that is not there.
        npm !== npmLatest;
  const npmCurrent = npmKnown && !npmBehind;

  const usedBy = version.in_use_by ?? 0;

  // Shares the install-state helper with PHP, so a version mid-install does not
  // render like a healthy one.
  const installState = versionState(version);

  // Nothing is on disk while it installs or purges, so every action fails; Remove
  // included (a second request would 404 once the first finished).
  const notReadyReason =
    installState === "installing"
      ? t("versions.stillInstalling")
      : installState === "removing"
        ? t("versions.stillRemoving")
        : installState === "failed"
          ? t("versions.installFailedShort")
          : null;

  // Same as PHP: a failed install left nothing on disk, and the remove endpoint
  // refuses a version that is not installed.
  const nothingToRemove = failedWithNothingInstalled(version);

  const removeReason = !canManage
    ? t("noPermission")
    : installState === "installing" || installState === "removing"
      ? notReadyReason
      : version.is_default
        ? t("versions.isDefault")
        : usedBy > 0
          ? t("versions.usedBy", { count: usedBy })
          : null;

  // A failed install with nothing on disk: DELETE clears the entry (204) instead of 404ing.
  async function dismiss() {
    setRunning("dismiss");
    try {
      await removeNodeVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.dismissed", { version: version.version }));
    } catch (error) {
      toast.error(apiMessage(error, t("versions.dismissFailed")));
    } finally {
      setRunning(null);
    }
  }

  async function retry() {
    setRunning("retry");
    try {
      await installNodeVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.retrying", { version: version.version }));
    } catch (error) {
      toast.error(apiMessage(error, t("versions.installFailedShort")));
    } finally {
      setRunning(null);
    }
  }

  async function makeDefault() {
    setRunning("default");
    try {
      await setDefaultNodeVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.defaultSet", { version: version.version }));
    } catch (error) {
      toast.error(apiMessage(error, t("versions.defaultFailed")));
    } finally {
      setRunning(null);
    }
  }

  async function remove() {
    setRunning("remove");
    try {
      await removeNodeVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.removed", { version: version.version }));
      setConfirming(false);
    } catch (error) {
      // The API names every site pinning it.
      toast.error(apiMessage(error, t("versions.removeFailed")));
    } finally {
      setRunning(null);
    }
  }

  async function upgradeNpm() {
    setRunning("npm");
    try {
      const before = npm;
      const { data } = await updateNodeNpm(version.version);
      const after = data?.npm_version ?? null;
      // Re-read: "update still available" is the server's semver answer.
      await refreshAndWait();
      // The API reports the version after the attempt; unchanged means it was already current.
      toast.success(
        after && before && after === before
          ? t("npm.alreadyLatest", { version: after })
          : t("npm.updated", { version: after ?? "" }),
      );
    } catch (error) {
      toast.error(apiMessage(error, t("npm.failed")));
    } finally {
      setRunning(null);
    }
  }

  return (
    <li className={cn("relative px-4 py-3", SUBGRID)}>
      <div className={cn("grid gap-y-2 @3xl/versions:items-center", SUBGRID)}>
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <span className="font-medium">{t("versions.name", { version: version.version })}</span>
          {version.is_default ? (
            <Badge variant="muted" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
          <RuntimeStatusBadge version={version} namespace="node" className={WRAP} />
        </div>

        {lifecycleAvailable ? (
          <SupportCell
            version={version}
            namespace="node"
            lifecycleAvailable={lifecycleAvailable}
            label={t("table.colSupport")}
            quiet={["current", "lts"]}
          />
        ) : null}

        {/* npm ships inside Node and updates separately. Null means unreadable: say so
            rather than show a wrong number. */}
        <VersionCell label={t("table.colNpm")}>
          {npm ? (
            <span className="flex min-w-0 flex-wrap items-center gap-2">
              <span className="font-mono text-xs">
                {npmBehind
                  ? t("npm.upgrade", { current: npm, latest: npmLatest })
                  : t("npm.installed", { version: npm })}
              </span>
              {/* Up to date: said, not offered as a button that cannot do anything. */}
              {npmCurrent ? (
                <span className="text-xs text-muted-foreground">{t("table.npmCurrent")}</span>
              ) : (
                <ReasonTooltip
                  reason={canManage ? (notReadyReason ?? null) : t("noPermission")}
                >
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={!canManage || pending || Boolean(notReadyReason) || npmCurrent}
                    onClick={upgradeNpm}
                  >
                    {running === "npm" ? <Loader2 className="size-4 animate-spin" /> : null}
                    {/* Short: the column already says npm. The full name is still read out. */}
                    <span aria-hidden>{t("table.npmUpdate")}</span>
                    <span className="sr-only">{t("npm.action")}</span>
                  </Button>
                </ReasonTooltip>
              )}
            </span>
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
        </VersionCell>

        <UsedByCell version={version} namespace="node" label={t("table.colUsedBy")} />

        <div className="flex flex-wrap items-center gap-2 @3xl/versions:justify-end">
          {version.is_default ? null : (
            <ReasonTooltip reason={canManage ? notReadyReason : t("noPermission")}>
              <Button
                variant="outline"
                size="sm"
                disabled={!canManage || pending || Boolean(notReadyReason)}
                onClick={makeDefault}
              >
                {running === "default" ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("versions.makeDefault")}
              </Button>
            </ReasonTooltip>
          )}

          {nothingToRemove ? (
            <ReasonTooltip reason={canManage ? null : t("noPermission")}>
              <Button
                variant="outline"
                size="sm"
                disabled={!canManage || pending}
                onClick={retry}
              >
                {running === "retry" ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("versions.retry")}
              </Button>
            </ReasonTooltip>
          ) : null}
          {nothingToRemove ? (
            <ReasonTooltip reason={canManage ? null : t("noPermission")}>
              <Button variant="ghost" size="sm" disabled={!canManage || pending} onClick={dismiss}>
                {running === "dismiss" ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("versions.dismiss")}
              </Button>
            </ReasonTooltip>
          ) : (
            <ReasonTooltip reason={removeReason}>
              <Button
                variant="ghost"
                size="sm"
                className="text-destructive/80 hover:bg-destructive/10 hover:text-destructive"
                disabled={Boolean(removeReason) || pending}
                onClick={() => setConfirming(true)}
              >
                {t("versions.remove")}
              </Button>
            </ReasonTooltip>
          )}
        </div>
      </div>

      {/* Why it stopped, and how long it has been going, as visible text. */}
      {removeFailed(version) || (installState && (version.started_at_human || version.output)) ? (
        <div className="mt-2 space-y-1 text-xs @3xl/versions:col-span-full">
          {removeFailed(version) ? (
            <p className="text-destructive">
              {version.message ?? t("versions.removeFailed")}
              {version.reference ? (
                <span className="ml-1.5 font-mono whitespace-nowrap text-muted-foreground">
                  {version.reference}
                </span>
              ) : null}
            </p>
          ) : null}
          {installState && installState !== "failed" && version.started_at_human ? (
            <p className="text-muted-foreground">
              {t(installState === "removing" ? "versions.removingSince" : "versions.installingSince", {
                when: version.started_at_human,
              })}
            </p>
          ) : null}
          {/* The installer's own output, as on the PHP row. */}
          {installState && version.output ? (
            <InstallOutput text={version.output.trimEnd()} />
          ) : null}
        </div>
      ) : null}

      <ConfirmDialog
        open={confirming}
        onOpenChange={(open) => !pending && setConfirming(open)}
        icon={Trash2}
        tone="destructive"
        title={t("versions.confirmRemoveTitle", { version: version.version })}
        description={t("versions.confirmRemoveBody")}
        cancelLabel={t("versions.confirmCancel")}
        confirmLabel={t("versions.remove")}
        pending={running === "remove"}
        onConfirm={remove}
      />
    </li>
  );
}
