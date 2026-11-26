import { useEffect, useRef, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, PlayCircle, ShieldCheck, TriangleAlert } from "lucide-react";
import {
  BACKUP_DEFAULT_TIME,
  backupTargetFormSchema,
  backupTargetOptionsSchema,
} from "@/lib/schemas/backup";
import { frequencyOption, timeUsage } from "@/lib/backups/frequency";
import { fetchBackupTargetOptions, runBackupNow, saveBackupTarget } from "@/lib/api/backups";
import { runDatabaseBackupNow, saveDatabaseBackupTarget } from "@/lib/api/database-backups";
import { markBackupStarted } from "@/lib/backups/just-started";
import { listDestinations } from "@/lib/api/storage";
import { storageDestinationsResponseSchema } from "@/lib/schemas/storage";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Form } from "@/components/ui/form";
import { FormModal } from "@/components/ui/form-modal";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { BackupSettingsFields } from "@/components/backups/backup-settings-fields";

export function SetupBackupsDialog({
  open,
  onOpenChange,
  applications = [],
  destinations = [],
  applicationId = null,
  target = null,
  keptCount = null,
  databaseCounts = null,
  databasesKnown = false,
  options: initialOptions = null,
  applicationName = null,
  siteTypes = null,
  siteType = null,
  servingProfile = null,
  hasVolumes = null,
  database = null,
  onStarted,
}) {
  const t = useTranslations("backups.setup");
  const { refreshAndWait } = useRefresh();
  const [saved, setSaved] = useState(null);
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const [running, setRunning] = useState(false);
  const [finishing, setFinishing] = useState(false);
  const [refreshed, setRefreshed] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const available = refreshed ?? destinations;

  const [fetchedOptions, setFetchedOptions] = useState(null);
  const [loadingOptions, setLoadingOptions] = useState(false);
  const options = initialOptions ?? fetchedOptions;
  const optionsRef = useRef(options);
  useEffect(() => {
    optionsRef.current = options;
  }, [options]);

  const form = useForm({
    resolver: (values, context, resolverOptions) =>
      zodResolver(backupTargetFormSchema(optionsRef.current))(values, context, resolverOptions),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults(applicationId, destinations, target, options, database),
  });

  async function retryOptions() {
    setLoadingOptions(true);
    try {
      const { data } = await fetchBackupTargetOptions();
      const parsed = backupTargetOptionsSchema.safeParse(data);
      if (!parsed.success) {
        toast.error(t("optionsFailed"));
        return;
      }
      setFetchedOptions(parsed.data);
      if (!target && !form.getValues("frequency")) {
        form.setValue("frequency", parsed.data.default_frequency);
      }
    } catch (error) {
      toast.error(apiMessage(error, t("optionsFailed")));
    } finally {
      setLoadingOptions(false);
    }
  }

  // Reopening from another row must not keep the previous site.
  useEffect(() => {
    if (open) form.reset(defaults(applicationId, destinations, target, options, database));
    // `destinations` and `target` are excluded deliberately: both change
    // identity every parent render, and re-seeding would overwrite a half-filled form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, applicationId]);

  async function onSubmit(values) {
    try {
      const common = {
        storage_destination_id: values.storage_destination_id,
        retention_count: values.retention_count,
        frequency: values.frequency,
        ...(timeUsage(options, values.frequency) ? { schedule_time: values.schedule_time } : null),
        enabled: values.enabled,
      };
      await (database
        ? saveDatabaseBackupTarget(database.id, common)
        : saveBackupTarget(values.application_id, {
            ...common,
            type: values.type,
            file_excludes: values.file_excludes,
            database_excludes: values.database_excludes,
          }));

      if (target && Number(values.storage_destination_id) === Number(target.storage_destination_id)) {
        await finish();
        toast.success(t("savedChanges"));
        return;
      }

      const application = applications.find(
        (candidate) => candidate.id === Number(values.application_id),
      );
      setSaved({
        id: Number(values.application_id),
        name: application?.name ?? applicationName ?? "",
        ...values,
      });
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
        return;
      }
      toast.error(apiMessage(error, t("failed")));
    }
  }

  async function backUpNow() {
    setRunning(true);
    try {
      await (database ? runDatabaseBackupNow(saved.id) : runBackupNow(saved.id));
      markBackupStarted();
      onStarted?.();
      await refreshAndWait();
      toast.success(t("started"));
      close();
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setRunning(false);
    }
  }

  function requestClose() {
    if (!saved && form.formState.isDirty && !form.formState.isSubmitting) {
      setConfirmDiscard(true);
      return;
    }
    close();
  }

  async function finish() {
    setFinishing(true);
    try {
      await refreshAndWait();
    } finally {
      setFinishing(false);
    }
    close();
  }

  function close() {
    setConfirmDiscard(false);
    setSaved(null);
    setRefreshed(null);
    form.reset(defaults(applicationId, destinations, target, options, database));
    onOpenChange?.(false);
  }

  const submitting = form.formState.isSubmitting;
  const values = useWatch({ control: form.control });
  async function refreshDestinations() {
    setRefreshing(true);
    try {
      const { data } = await listDestinations();
      const parsed = storageDestinationsResponseSchema.safeParse(data);
      if (!parsed.success) {
        toast.error(t("refreshFailed"));
        return;
      }
      const next = parsed.data.storage_destinations;
      setRefreshed(next);

      if (next.length === 1 && !form.getValues("storage_destination_id")) {
        form.setValue("storage_destination_id", next[0].id, { shouldValidate: true });
      }
      toast.success(
        next.length > destinations.length ? t("refreshFound") : t("refreshNoneNew"),
      );
    } catch (error) {
      toast.error(apiMessage(error, t("refreshFailed")));
    } finally {
      setRefreshing(false);
    }
  }

  const incomplete =
    !values.application_id ||
    !values.storage_destination_id ||
    !values.type ||
    (values.enabled && (!values.frequency || !values.retention_count));

  const blocker = !options
    ? t("blocked.noOptions")
    : available.length === 0
      ? t("blocked.noStorage")
      : !values.application_id
        ? t("blocked.noSite")
        : !values.storage_destination_id
          ? t("blocked.noDestination")
          : incomplete
            ? t("blocked.incomplete")
            : target && !form.formState.isDirty
              ? t("blocked.unchanged")
              : null;

  if (saved) {
    return (
      <FormModal
        open={open}
        onOpenChange={(next) => (next ? onOpenChange?.(true) : running || finishing ? null : finish())}
        icon={CheckCircle2}
        title={
          saved.name
            ? t(saved.enabled ? "savedTitle" : "savedTitleManual", { name: saved.name })
            : t(saved.enabled ? "savedTitleNoName" : "savedTitleManualNoName")
        }
        description={
          saved.enabled ? t("savedScheduled") : t("savedManual")
        }
        footer={
          <>
            <Button type="button" variant="outline" onClick={finish} disabled={running || finishing}>
              {finishing ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("done")}
            </Button>
            <Button type="button" onClick={backUpNow} disabled={running || finishing}>
              {running ? <Loader2 className="size-4 animate-spin" /> : <PlayCircle className="size-4" />}
              {t("backUpNow")}
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">{saved.enabled ? t("verifyHint") : t("verifyHintManual")}</p>
      </FormModal>
    );
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={(next) => (next ? onOpenChange?.(true) : requestClose())}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={ShieldCheck}
        className="sm:max-w-xl"
        title={target ? t("editTitle") : t("title")}
        description={
          target ? t(database ? "editSubtitleDatabase" : "editSubtitle") : database ? t("subtitleDatabase") : applications.length ? t("subtitle") : t("subtitleSite")
        }
        footer={
          <>
            {blocker ? (
              <span className="w-full text-xs text-muted-foreground sm:mr-auto sm:w-auto">
                {blocker}
              </span>
            ) : null}
            <Button type="button" variant="outline" onClick={requestClose} disabled={submitting}>
              {t("cancel")}
            </Button>
            <ReasonTooltip reason={!submitting && blocker ? blocker : null}>
            <Button type="submit" disabled={submitting || Boolean(blocker)}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting ? t("saving") : target ? t("saveChanges") : t("submit")}
            </Button>
            </ReasonTooltip>
          </>
        }
      >
        <BackupSettingsFields
          form={form}
          applications={applications}
          destinations={available}
          onRefreshDestinations={refreshDestinations}
          refreshingDestinations={refreshing}
          disabled={submitting}
          target={target}
          keptCount={keptCount}
          databaseCounts={databaseCounts}
          databasesKnown={databasesKnown}
          siteTypes={siteTypes}
          siteType={siteType}
          servingProfile={servingProfile}
          hasVolumes={hasVolumes}
          databaseOnly={Boolean(database)}
          options={options}
          onRetryOptions={retryOptions}
          retryingOptions={loadingOptions}
        />

        <SummaryLine
          values={values}
          applications={applications}
          applicationName={applicationName}
          destinations={available}
          options={options}
        />
      </FormModal>

      <ConfirmDialog
        open={confirmDiscard}
        onOpenChange={setConfirmDiscard}
        icon={TriangleAlert}
        tone="warning"
        confirmVariant="destructive"
        title={t("discardTitle")}
        description={t("discardDescription")}
        cancelLabel={t("discardKeep")}
        confirmLabel={t("discardConfirm")}
        onConfirm={close}
      />
    </Form>
  );
}

function SummaryLine({ values, applications, applicationName = null, destinations, options }) {
  const t = useTranslations("backups.setup");
  const tf = useTranslations("backups.form");

  const siteName =
    applications.find((a) => a.id === Number(values.application_id))?.name ?? applicationName;
  const destination = destinations.find((d) => d.id === Number(values.storage_destination_id));
  if (!siteName || !values.type) return null;

  const parts = [
    siteName,
    options?.types.find((type) => type.value === values.type)?.label,
    values.enabled ? frequencyOption(options, values.frequency)?.label : tf("automaticOffShort"),
    values.enabled ? t("keep", { count: Number(values.retention_count) || 0 }) : null,
    destination?.name,
  ].filter(Boolean);

  return (
    <div className="rounded-lg border bg-muted/40 px-3 py-2.5">
      <p className="text-xs text-muted-foreground">{t("summaryLabel")}</p>
      <p className="mt-0.5 text-sm font-medium">{parts.join(" · ")}</p>
    </div>
  );
}

function defaults(applicationId, destinations, target, options, database = null) {
  if (target) {
    const automatic = target.enabled && target.frequency !== "manual";
    return {
      application_id: (database ? database.id : target.application_id) ?? applicationId ?? "",
      storage_destination_id: target.storage_destination_id,
      type: database ? "database" : target.type,
      retention_count: target.retention_count,
      frequency: automatic ? target.frequency : "manual",
      schedule_time: target.schedule_time ?? BACKUP_DEFAULT_TIME,
      enabled: automatic,
      file_excludes: target.file_excludes ?? [],
      database_excludes: target.database_excludes ?? [],
    };
  }

  return {
    application_id: database?.id ?? applicationId ?? "",
    storage_destination_id: destinations.length === 1 ? destinations[0].id : "",
    type: database ? "database" : "full",
    retention_count: 7,
    frequency: options?.default_frequency ?? "",
    schedule_time: BACKUP_DEFAULT_TIME,
    enabled: true,
    file_excludes: [],
    database_excludes: [],
  };
}
