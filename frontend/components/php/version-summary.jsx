"use client";

import { removeFailed, versionState } from "@/components/runtime/version-status";
import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Trash2 } from "lucide-react";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { InstallOutput } from "@/components/runtime/install-output";
import { setDefaultPhpVersion, removePhpVersion, installPhpVersion } from "@/lib/api/php";
import { failedWithNothingInstalled } from "@/lib/runtime/failed-install";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

// `children` carries the php.ini button, owned by the page (a Server Component).
export function VersionSummary({
  version,
  canManage,
  lifecycleAvailable = false,
  children,
}) {
  const t = useTranslations("php");
  const { refreshAndWait } = useRefresh();
  const [confirming, setConfirming] = useState(false);
  // WHICH action is running, so only that button spins. They still disable
  // together, because both act on one version.
  const [running, setRunning] = useState(null);
  const pending = running !== null;

  const usedBy = version.in_use_by ?? 0;
  const sites = version.sites ?? [];

  // The API omits `status` on older responses; absent means ready.
  const installState = versionState(version);

  // Present but not set up by the panel (e.g. `lsphp83` from OpenLiteSpeed).
  // Hidden mid-install, when the list is legitimately incomplete.
  const missingPackages = version.missing_packages ?? [];
  const incomplete = missingPackages.length > 0 && !installState;

  // Nothing is on disk, so anything that reads or writes this install fails.
  // Removing is the exception: clearing a failed install is the next step.
  const notReadyReason = installState
    ? installState === "installing"
      ? t("versions.stillInstalling")
      : // Removing is in flight too, so reads and writes are refused,
        // including a second Remove.
        installState === "removing"
        ? t("versions.stillRemoving")
        : t("versions.installFailedShort")
    : null;

  const removeReason = !canManage
    ? t("noPermission")
    : // Already removing: a second DELETE answers 404 once apt finishes.
      installState === "removing"
      ? t("versions.stillRemoving")
      : // Not while apt is installing it (it would race the install). A FAILED
        // install stays removable, so this is not folded into `notReadyReason`.
        installState === "installing"
        ? t("versions.stillInstalling")
        : version.in_use_by_panel
        ? t("versions.panelRuns")
        : version.is_default
          ? t("versions.isDefault")
          : usedBy > 0
            ? t("versions.usedBy", { count: usedBy })
            : null;

  async function makeDefault() {
    setRunning("default");
    try {
      await setDefaultPhpVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.defaultSet", { version: version.version }));
    } catch (error) {
      toast.error(apiMessage(error, t("versions.defaultFailed")));
    } finally {
      setRunning(null);
    }
  }

  async function retry() {
    setRunning("retry");
    try {
      await installPhpVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.retrying", { version: version.version }));
    } catch (error) {
      toast.error(apiMessage(error, t("versions.installFailedShort")));
    } finally {
      setRunning(null);
    }
  }

  async function complete() {
    setRunning("complete");
    try {
      await installPhpVersion(version.version);
      await refreshAndWait();
      toast.success(t("versions.completing", { version: version.version }));
    } catch (error) {
      toast.error(apiMessage(error, t("versions.installFailedShort")));
    } finally {
      setRunning(null);
    }
  }

  async function remove() {
    setRunning("remove");
    try {
      await removePhpVersion(version.version);
      await refreshAndWait();
      // "Removing", not "removed": a 202, apt has minutes of work ahead.
      toast.success(t("versions.removing", { version: version.version }));
      setConfirming(false);
    } catch (error) {
      // The API names the blocking sites in its message.
      toast.error(apiMessage(error, t("versions.removeFailed")));
    } finally {
      setRunning(null);
    }
  }

  // Each action appears only when it applies: the default cannot be made
  // default again, and the panel's own version cannot be removed.
  const showMakeDefault = !version.is_default;
  // `destroy()` refuses a version not installed, so a failed install offers reinstall.
  const nothingToRemove = failedWithNothingInstalled(version);
  const showRemove = !version.in_use_by_panel && !nothingToRemove;

  return (
    <Card>
      <CardHeader>
        {/* php.ini sits with the version it edits, on the title line. */}
        <div className="flex flex-wrap items-start justify-between gap-3">
        <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
          {t("versions.name", { version: version.version })}
          {/* Filled, not outlined: "Default" is a state, not a plain label. */}
          {version.is_default ? (
            <Badge variant="muted" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
          {/* Not folded into the status chain below: a version can be both
              incomplete AND end-of-life. */}
          {incomplete ? (
            <Badge variant="warning" className="font-normal">
              {t("versions.incomplete")}
            </Badge>
          ) : null}
          {/* A failed install must not look like a healthy one. */}
          {removeFailed(version) ? (
            <Badge variant="destructive" className="font-normal">
              {t("versions.statusRemoveFailed")}
            </Badge>
          ) : installState === "failed" ? (
            <Badge variant="destructive" className="font-normal">
              {t("versions.statusFailed")}
            </Badge>
          ) : installState === "removing" ? (
            // A badge rather than hiding the card: the purge takes minutes and
            // may fail.
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("versions.statusRemoving")}
            </Badge>
          ) : installState === "installing" ? (
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {/* The apt phase, not a percentage: the total is unknown until
                  apt finishes. */}
              {version.current_step
                ? t(`versions.steps.${version.current_step}`)
                : t("versions.statusInstalling")}
            </Badge>
          ) : (
            <LifecycleBadge
              lifecycle={version.lifecycle}
              namespace="php"
              available={lifecycleAvailable}
            />
          )}
        </CardTitle>

          {/* Not shrink-0: it keeps max-content width, so flex-wrap never wraps. */}
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            {children}

            {/* The API allows an incomplete version as default; the UI does not. */}
            {!showMakeDefault ? null : (
              <ReasonTooltip
                reason={
                  notReadyReason ??
                  (incomplete ? t("versions.incompleteDefault") : null) ??
                  (canManage ? null : t("noPermission"))
                }
              >
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || pending || Boolean(notReadyReason) || incomplete}
                  onClick={makeDefault}
                >
                  {running === "default" ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("versions.makeDefault")}
                </Button>
              </ReasonTooltip>
            )}

            {/* `PhpController::store` falls through to an idempotent apt install when incomplete. */}
            {!incomplete ? null : (
              <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || pending}
                  onClick={complete}
                >
                  {running === "complete" ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("versions.completeInstall")}
                </Button>
              </ReasonTooltip>
            )}

            {!nothingToRemove ? null : (
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
            )}

            {/* Hidden on the panel's own version, which the API refuses. */}
            {!showRemove ? null : (
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

        {/* The count answers "can I remove this?"; the site tags below answer
            "what breaks?". */}
        <CardDescription>
          {/* Why the removal stopped, as visible text (a `title` is not shown
              on touch or keyboard). */}
          {removeFailed(version) ? (
            <span className="block text-destructive">
              {/* The job records no message for this, so ours stands in. */}
              {version.message ?? t("versions.removeFailed")}
              {version.reference ? (
                <span className="ml-1.5 font-mono whitespace-nowrap text-muted-foreground">
                  {version.reference}
                </span>
              ) : null}
            </span>
          ) : null}
          {incomplete ? (
            <span className="block text-warning">
              {t("versions.incompleteDetail", { packages: missingPackages.join(", ") })}
            </span>
          ) : null}
          {usedBy > 0 ? t("versions.usedByCount", { count: usedBy }) : t("versions.usedByNone")}
          {/* Only when the date is news; supported versions show just the badge. */}
          {lifecycleAvailable &&
          version.lifecycle?.eol_date &&
          version.lifecycle.status !== "active"
            ? ` ${
                version.lifecycle.status === "eol"
                  ? t("lifecycle.endedOn", { date: version.lifecycle.eol_date })
                  : t("lifecycle.endsOn", { date: version.lifecycle.eol_date })
              }`
            : null}
        </CardDescription>

        {/* apt's output while installing and after a failure: the badge gives
            the phase, only this says why it stopped. */}
        {installState && version.output ? (
          <InstallOutput text={version.output.trimEnd()} />
        ) : null}

        {/* Tags, so near-duplicate names stay distinct. The API sends at most
            five and reports how many it held back. */}
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

        {/* "Default" does not mean every site uses this version; said here. */}
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
