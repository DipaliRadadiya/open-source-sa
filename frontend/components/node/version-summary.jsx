"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Trash2 } from "lucide-react";
import { RuntimeStatusBadge, versionState } from "@/components/runtime/version-status";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
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
import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

// Same card as PHP's, minus the panel-version rule and plus npm.
export function VersionSummary({ version, canManage, lifecycleAvailable = false, children }) {
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
  const sites = version.sites ?? [];

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
    <Card>
      <CardHeader>
        {/* Every action for this version on one line, with the version it acts on. */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
            {t("versions.name", { version: version.version })}
            {version.is_default ? (
              <Badge variant="muted" className="font-normal">
                {t("versions.default")}
              </Badge>
            ) : null}
            <RuntimeStatusBadge version={version} namespace="node" />
            <LifecycleBadge
              lifecycle={version.lifecycle}
              namespace="node"
              available={lifecycleAvailable}
            />
            {/* The installed npm version is a fact about this version, shown here rather than
                in the button label (which read as the version it would install). */}
            {npm ? (
              <Badge variant="outline" className="font-normal">
                {npmBehind
                  ? t("npm.upgrade", { current: npm, latest: npmLatest })
                  : t("npm.installed", { version: npm })}
              </Badge>
            ) : null}
          </CardTitle>

          {/* Not shrink-0: a shrink-0 flex item keeps its max-content width, so its
              flex-wrap never fires and buttons overflow the card. */}
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            {children}
            {/* npm ships inside Node and updates separately. Null means unreadable: hide the
                control rather than show a wrong number. */}
            {npm ? (
              <ReasonTooltip
                reason={
                  canManage
                    ? (notReadyReason ?? (npmCurrent ? t("npm.currentReason") : null))
                    : t("noPermission")
                }
              >
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || pending || Boolean(notReadyReason) || npmCurrent}
                  onClick={upgradeNpm}
                >
                  {running === "npm" ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("npm.action")}
                </Button>
              </ReasonTooltip>
            ) : null}

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

        {/* The count answers "can I remove this?"; the names answer "what breaks?". Kept
            separate, as on the PHP card. */}
        <CardDescription>
          {usedBy > 0 ? t("versions.usedByCount", { count: usedBy }) : t("versions.usedByNone")}
          {/* Only when the date is news; on a supported line the green badge says enough. */}
          {lifecycleAvailable &&
          version.lifecycle?.eol_date &&
          version.lifecycle.status !== "current" &&
          version.lifecycle.status !== "lts"
            ? ` ${
                version.lifecycle.status === "eol"
                  ? t("lifecycle.endedOn", { date: version.lifecycle.eol_date })
                  : t("lifecycle.endsOn", { date: version.lifecycle.eol_date })
              }`
            : null}
        </CardDescription>

        {/* The installer's own output, as on the PHP card. */}
        {installState && version.output ? (
          <InstallOutput text={version.output.trimEnd()} />
        ) : null}

        {/* Tags: each name is one scannable unit. The API sends at most five and reports
            how many it held back. */}
        {sites.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5 pt-1">
            {sites.map((site) => (
              <li
                key={site}
                className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground"
              >
                {site}
              </li>
            ))}
            {version.sites_truncated && usedBy > sites.length ? (
              <li className="px-1 py-0.5 text-xs text-muted-foreground">
                {t("versions.moreCount", { count: usedBy - sites.length })}
              </li>
            ) : null}
          </ul>
        ) : null}

        {version.is_default ? (
          <p className="text-xs text-muted-foreground">{t("versions.defaultHint")}</p>
        ) : null}
      </CardHeader>

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
    </Card>
  );
}
