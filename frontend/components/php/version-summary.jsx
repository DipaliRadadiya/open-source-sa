"use client";

import { RuntimeStatusBadge, removeFailed, versionState } from "@/components/runtime/version-status";
import { useState } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Trash2 } from "lucide-react";
import { SupportCell, UsedByCell } from "@/components/runtime/versions-table";
import { SUBGRID } from "@/lib/runtime/version-rows";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import Link from "@/components/ui/app-link";
import { cn } from "@/lib/utils";
import { requestReveal } from "@/components/php/reveal-settings";
import { InstallOutput } from "@/components/runtime/install-output";
import { setDefaultPhpVersion, removePhpVersion, installPhpVersion } from "@/lib/api/php";
import { failedWithNothingInstalled } from "@/lib/runtime/failed-install";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { cronjobsOnDefaultSchema } from "@/lib/schemas/cronjob-php";

// Long states ("Installation fehlgeschlagen") wrap instead of spilling.
const WRAP = "h-auto text-left whitespace-normal";

// One row of the Installed versions table. Clicking it shows its settings below; the
// selection lives in the URL, keeping whichever settings tab is open.
export function VersionSummary({
  version,
  canManage,
  lifecycleAvailable = false,
  selected = false,
  selectable = false,
}) {
  const t = useTranslations("php");
  const format = useFormatter();
  const { guardNavigation } = useUnsaved();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const params = new URLSearchParams(searchParams);
  params.set("version", version.version);
  const href = selectable ? `${pathname}?${params.toString()}` : null;
  const { refreshAndWait } = useRefresh();
  const [confirming, setConfirming] = useState(false);
  // WHICH action is running, so only that button spins. They still disable
  // together, because both act on one version.
  const [running, setRunning] = useState(null);
  const pending = running !== null;

  const usedBy = version.in_use_by ?? 0;

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
      const { data } = await setDefaultPhpVersion(version.version);
      await refreshAndWait();
      // Plain `php` cron jobs follow the default, so they already run the new version: say which.
      const moved = cronjobsOnDefaultSchema.parse(data?.cronjobs_on_default_php ?? []);
      const names = moved.slice(0, 3).map((job) => job.name);
      if (moved.length > 3) names.push(t("versions.moreCronjobs", { count: moved.length - 3 }));
      toast.success(
        t("versions.defaultSet", { version: version.version }),
        moved.length
          ? { description: t("versions.cronOnDefault", { count: moved.length, version: version.version, names: format.list(names, { type: "conjunction" }) }), duration: 12000 }
          : undefined,
      );
    } catch (error) {
      toast.error(apiMessage(error, t("versions.defaultFailed")));
    } finally {
      setRunning(null);
    }
  }

  // A failed install with nothing on disk: DELETE clears the entry (204) instead of 404ing.
  async function dismiss() {
    setRunning("dismiss");
    try {
      await removePhpVersion(version.version);
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

  const name = t("versions.name", { version: version.version });

  return (
    <li
      className={cn(
        "relative px-4 py-3 transition-colors",
        SUBGRID,
        href && "hover:bg-muted/40",
        // A tint and an edge, so the selected row reads as the one the settings below belong to.
        selected && "bg-primary/[0.04] shadow-[inset_3px_0_0_var(--primary)]",
      )}
    >
      <div className={cn("grid gap-y-2 @3xl/versions:items-center", SUBGRID)}>
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          {/* The whole row selects the version: the link's ::after covers it, and the
              controls sit above it (z-10). */}
          {href ? (
            <Link
              href={href}
              scroll={false}
              prefetch={false}
              aria-current={selected ? "true" : undefined}
              onClick={(event) => {
                // An unsaved php.ini below would be lost by the switch.
                if (guardNavigation(href)) event.preventDefault();
                else requestReveal(version.version);
              }}
              className="rounded-sm font-medium after:absolute after:inset-0 after:content-[''] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {name}
            </Link>
          ) : (
            <span className="font-medium">{name}</span>
          )}
          {/* Filled, not outlined: "Default" is a state, not a plain label. */}
          {version.is_default ? (
            <Badge variant="muted" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
          {/* Not folded into the status badge: a version can be both incomplete AND end-of-life. */}
          {incomplete ? (
            <Badge variant="warning" className="font-normal">
              {t("versions.incomplete")}
            </Badge>
          ) : null}
          <RuntimeStatusBadge version={version} namespace="php" className={WRAP} />
        </div>

        {lifecycleAvailable ? (
          <SupportCell
            version={version}
            namespace="php"
            lifecycleAvailable={lifecycleAvailable}
            label={t("table.colSupport")}
            quiet={["active"]}
          />
        ) : null}

        <UsedByCell version={version} namespace="php" label={t("table.colUsedBy")} />

        {/* Clicks between the buttons fall through to the row link. */}
        <div className="pointer-events-none relative z-10 flex flex-wrap items-center gap-2 @3xl/versions:justify-end [&>*]:pointer-events-auto">
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
            <>
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
              <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                <Button variant="ghost" size="sm" disabled={!canManage || pending} onClick={dismiss}>
                  {running === "dismiss" ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("versions.dismiss")}
                </Button>
              </ReasonTooltip>
            </>
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

      {/* Why something stopped, as visible text (a `title` is not shown on touch or keyboard). */}
      {removeFailed(version) || incomplete || (installState && (version.started_at_human || version.output)) ? (
        <div className="relative z-10 mt-2 space-y-1 text-xs @3xl/versions:col-span-full">
          {removeFailed(version) ? (
            <p className="text-destructive">
              {/* The job records no message for this, so ours stands in. */}
              {version.message ?? t("versions.removeFailed")}
              {version.reference ? (
                <span className="ml-1.5 font-mono whitespace-nowrap text-muted-foreground">
                  {version.reference}
                </span>
              ) : null}
            </p>
          ) : null}
          {incomplete ? (
            <p className="text-warning">
              {t("versions.incompleteDetail", { packages: missingPackages.join(", ") })}
            </p>
          ) : null}
          {installState && installState !== "failed" && version.started_at_human ? (
            <p className="text-muted-foreground">
              {t(installState === "removing" ? "versions.removingSince" : "versions.installingSince", {
                when: version.started_at_human,
              })}
            </p>
          ) : null}
          {/* apt's output while installing and after a failure: the badge gives
              the phase, only this says why it stopped. */}
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
