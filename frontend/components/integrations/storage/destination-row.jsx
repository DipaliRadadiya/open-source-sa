import { useFormatter, useTranslations } from "next-intl";
import { DENIED, DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { GoogleDriveConnect } from "@/components/integrations/storage/google-drive-connect";
import {
  CheckCircle2,
  CircleHelp,
  HardDrive,
  KeyRound,
  Loader2,
  MoreHorizontal,
  Pencil,
  PlugZap,
  Trash2,
  TriangleAlert,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { describeDestination, hasCredentials, s3ServiceFor } from "@/lib/storage/providers";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ActionIcon } from "@/components/ui/action-icon";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

const FAILURE_KEYS = {
  invalid_credentials: "failedCredentials",
  drive_personal: "failedDrivePersonal",
  drive_not_shared: "failedDriveNotShared",
  drive_folder_missing: "failedDriveFolderMissing",
  drive_not_a_folder: "failedDriveNotAFolder",
  drive_bad_key: "failedDriveBadKey",
  drive_quota: "failedDriveQuota",
  drive_incomplete: "failedDriveIncomplete",
  unreachable: "failedUnreachable",
  host_key_mismatch: "failedHostKey",
  invalid_private_key: "failedPrivateKey",
  root_missing: "failedRootMissing",
  mismatch: "failedMismatch",
  local_missing: "failedLocalMissing",
  local_foreign: "failedLocalForeign",
  local_not_writable: "failedLocalNotWritable",
  local_no_space: "failedLocalNoSpace",
  local_path_not_allowed: "failedLocalPath",
  local_path_link: "failedLocalPath",
  local_path_invalid: "failedLocalPath",
};

export function DestinationRow({
  destination,
  canManage,
  testing,
  result,
  usage = null,
  onTest,
  onEdit,
  onReplace,
  onDelete,
}) {
  const t = useTranslations("storage");
  const format = useFormatter();
  const { location, address } = describeDestination(destination);
  const isS3 = destination.provider === "s3";
  const service = s3ServiceFor(destination);
  const secrets = hasCredentials(destination.provider);
  const free = destination.provider === "local" ? formatBytes(destination.config?.free_bytes, format) : null;
  const total = destination.provider === "local" ? formatBytes(destination.config?.total_bytes, format) : null;

  const needsConnect =
    destination.provider === "google_drive_oauth" && destination.config?.connected === false;

  const verdict = testing ? (
    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
      <Loader2 className="size-3 animate-spin" />
      {t("row.testing")}
    </p>
  ) : needsConnect ? (
    <div className="space-y-1.5">
      <p className="flex items-start gap-1.5 text-xs text-warning">
        <TriangleAlert className="mt-0.5 size-3 shrink-0" />
        {t("row.needsConnect")}
      </p>
      {canManage ? <GoogleDriveConnect destination={destination} compact /> : null}
    </div>
  ) : result ? (
    <p
      className={
        result.ok
          ? "flex items-start gap-1.5 text-xs text-success"
          : result.notRun
            ? "flex items-start gap-1.5 text-xs text-muted-foreground"
            : "flex items-start gap-1.5 text-xs text-destructive"
      }
    >
      {result.ok ? <CheckCircle2 className="mt-0.5 size-3 shrink-0" /> : <TriangleAlert className="mt-0.5 size-3 shrink-0" />}
      <span>
        {result.ok ? (result.latency ? t("row.testPassedIn", { ms: result.latency }) : t("row.testPassed")) : result.message}
        {!result.ok && !result.notRun && canManage && secrets ? <ReplaceLink onClick={onReplace} /> : null}
      </span>
    </p>
  ) : (
    <StoredVerdict destination={destination} canManage={canManage} onReplace={onReplace} />
  );

  return (
    <DisabledReasonProvider denied={!canManage}>
      <div className="flex flex-wrap items-start gap-x-3 gap-y-2 py-3.5 lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,3fr)_minmax(0,3fr)_auto] lg:gap-6">
        <div className="flex w-full min-w-0 gap-3 lg:w-auto">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
            <HardDrive className="size-4" />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="min-w-0 font-medium break-all">{destination.name}</span>
              {service || destination.provider_title ? (
                <Badge variant="outline" className="font-normal">
                  {service ? t(`form.providers.${service}`) : destination.provider_title}
                </Badge>
              ) : null}
              {!secrets ? null : destination.has_credentials ? (
                <Badge variant="muted">{t("row.credentialsSet")}</Badge>
              ) : (
                <>
                  <Badge variant="warning">{t("row.credentialsMissing")}</Badge>
                  {canManage ? (
                    <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" onClick={onReplace}>
                      {t("row.addCredentials")}
                    </Button>
                  ) : null}
                </>
              )}
            </div>
            {location ? <p className="truncate font-mono text-xs text-muted-foreground">{location}</p> : null}
            {address ? (
              <p className="truncate font-mono text-xs text-muted-foreground">{address}</p>
            ) : isS3 ? (
              <p className="text-xs text-muted-foreground">{t("row.awsDefault")}</p>
            ) : null}
            {isS3 ? (
              <p className="text-xs text-muted-foreground">{destination.config?.region || t("row.regionDefault")}</p>
            ) : null}
            {free && total ? (
              <p className="text-xs text-muted-foreground tabular-nums">{t("row.diskFree", { free, total })}</p>
            ) : null}
          </div>
        </div>

        <div className="w-full space-y-0.5 ps-11 text-xs lg:w-auto lg:ps-0">
          <p className="hidden font-mono text-xs font-medium tracking-wide text-muted-foreground uppercase lg:block">{t("row.colBackups")}</p>
          {usage === null ? (
            <p className="hidden text-muted-foreground lg:block">—</p>
          ) : usage.applications > 0 ? (
            <>
              <p className="text-foreground">{t("row.usedBy", { count: usage.applications })}</p>
              <LastBackupLine backup={usage.lastBackup} />
            </>
          ) : (
            <p className="text-muted-foreground">{t("row.notUsed")}</p>
          )}
        </div>

        <div className="w-full space-y-0.5 ps-11 lg:w-auto lg:ps-0">
          <p className="hidden font-mono text-xs font-medium tracking-wide text-muted-foreground uppercase lg:block">{t("row.colTest")}</p>
          {verdict}
          {destination.created_at_human ? (
            <p className="text-xs text-muted-foreground">{t("row.added", { when: destination.created_at_human })}</p>
          ) : null}
        </div>

        <div className="ml-auto flex shrink-0 items-center gap-1">
          <ReasonTooltip
            reason={
              !canManage
                ? DENIED
                : !destination.has_credentials
                  ? t("row.testNeedsCredentials")
                  : null
            }
          >
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onTest}
              disabled={!canManage || testing || !destination.has_credentials}
            >
              <ActionIcon icon={PlugZap} pending={testing} className="size-3.5" />
              {t("row.test")}
            </Button>
          </ReasonTooltip>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="ghost" size="icon" aria-label={t("row.actions")}>
                <MoreHorizontal className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={onEdit} disabled={!canManage}>
                <Pencil className="size-3.5" />
                {t("row.edit")}
              </DropdownMenuItem>
              {secrets ? (
                <DropdownMenuItem onSelect={onReplace} disabled={!canManage}>
                  <KeyRound className="size-3.5" />
                  {t("row.replace")}
                </DropdownMenuItem>
              ) : null}
              <DropdownMenuItem onSelect={onDelete} disabled={!canManage} variant="destructive">
                <Trash2 className="size-3.5" />
                {t("row.delete")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </DisabledReasonProvider>
  );
}

function StoredVerdict({ destination, canManage, onReplace }) {
  const t = useTranslations("storage");
  const { status, last_test_success: success, last_tested_at_human: when } = destination;

  if (!status || status === "never_tested") {
    return (
      <p className="flex items-center gap-1.5 pt-0.5 text-xs text-muted-foreground">
        <CircleHelp className="size-3 shrink-0" />
        {t(hasCredentials(destination.provider) ? "row.neverTested" : "row.neverTestedFolder")}
      </p>
    );
  }

  if (success) {
    return (
      <p className="flex items-center gap-1.5 pt-0.5 text-xs text-muted-foreground">
        <CheckCircle2 className="size-3 shrink-0 text-success" />
        {when ? t("row.lastTestedOk", { when }) : t("row.testPassed")}
      </p>
    );
  }

  return (
    <p className="flex items-start gap-1.5 pt-0.5 text-xs text-destructive">
      <TriangleAlert className="mt-0.5 size-3 shrink-0" />
      <span>
        {t(`row.${FAILURE_KEYS[destination.last_test_error] ?? "failed"}`, { when: when ?? "" })}
        {destination.last_test_error === "invalid_credentials" && canManage ? (
          <ReplaceLink onClick={onReplace} />
        ) : null}
      </span>
    </p>
  );
}

function LastBackupLine({ backup }) {
  const t = useTranslations("storage");
  if (!backup) return null;
  if (BACKUP_IN_FLIGHT.includes(backup.status)) {
    return (
      <p className="flex items-center gap-1.5 text-muted-foreground">
        <Loader2 className="size-3 shrink-0 animate-spin" />
        {t("row.backingUp")}
      </p>
    );
  }
  if (!backup.created_at_human) return null;
  if (backup.status === "failed") {
    return (
      <p className="flex items-start gap-1.5 text-destructive">
        <TriangleAlert className="mt-0.5 size-3 shrink-0" />
        {t("row.lastBackupFailed", { when: backup.created_at_human })}
      </p>
    );
  }
  return <p className="text-muted-foreground">{t("row.lastBackup", { when: backup.created_at_human })}</p>;
}

function ReplaceLink({ onClick }) {
  const t = useTranslations("storage");
  return (
    <>
      {" "}
      <button
        type="button"
        onClick={onClick}
        className="ms-1 rounded-sm font-medium whitespace-nowrap text-primary underline underline-offset-2 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        {t("row.replace")}
      </button>
    </>
  );
}
