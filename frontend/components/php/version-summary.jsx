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
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { cronjobsOnDefaultSchema } from "@/lib/schemas/cronjob-php";

const WRAP = "h-auto text-left whitespace-normal";

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
  const [running, setRunning] = useState(null);
  const pending = running !== null;

  const usedBy = version.in_use_by ?? 0;

  const installState = versionState(version);

  const missingPackages = version.missing_packages ?? [];
  const incomplete = missingPackages.length > 0 && !installState;

  const notReadyReason = installState
    ? installState === "installing"
      ? t("versions.stillInstalling")
      :
        installState === "removing"
        ? t("versions.stillRemoving")
        : t("versions.installFailedShort")
    : null;

  const removeReason = !canManage
    ? DENIED
    :
      installState === "removing"
      ? t("versions.stillRemoving")
      : // Not while apt is installing it; it would race the install.
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
      toast.success(t("versions.removing", { version: version.version }));
      setConfirming(false);
    } catch (error) {
      toast.error(apiMessage(error, t("versions.removeFailed")));
    } finally {
      setRunning(null);
    }
  }

  const showMakeDefault = !version.is_default;
  const nothingToRemove = failedWithNothingInstalled(version);
  const showRemove = !version.in_use_by_panel && !nothingToRemove;

  const name = t("versions.name", { version: version.full_version ?? version.version });

  return (
    <li
      className={cn(
        "relative px-4 py-3 transition-colors",
        SUBGRID,
        href && "hover:bg-muted/40",
        selected && "bg-primary/[0.04] shadow-[inset_3px_0_0_var(--primary)]",
      )}
    >
      <div className={cn("grid gap-y-2 @3xl/versions:items-center", SUBGRID)}>
        <div className="flex min-w-0 flex-wrap items-center gap-2">
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
          {version.is_default ? (
            <Badge variant="muted" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
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

        <div className="pointer-events-none relative z-10 flex flex-wrap items-center gap-2 @3xl/versions:justify-end [&>*]:pointer-events-auto">
          {!showMakeDefault ? null : (
            <ReasonTooltip
              reason={
                notReadyReason ??
                (incomplete ? t("versions.incompleteDefault") : null) ??
                (canManage ? null : DENIED)
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

          {!incomplete ? null : (
            <ReasonTooltip reason={canManage ? null : DENIED}>
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
              <ReasonTooltip reason={canManage ? null : DENIED}>
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
              <ReasonTooltip reason={canManage ? null : DENIED}>
                <Button variant="ghost" size="sm" disabled={!canManage || pending} onClick={dismiss}>
                  {running === "dismiss" ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("versions.dismiss")}
                </Button>
              </ReasonTooltip>
            </>
          )}

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

      {removeFailed(version) || incomplete || (installState && (version.started_at_human || version.output)) ? (
        <div className="relative z-10 mt-2 space-y-1 text-xs @3xl/versions:col-span-full">
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
