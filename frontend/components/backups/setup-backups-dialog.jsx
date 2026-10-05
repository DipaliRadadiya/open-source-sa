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

// Does NOT close after saving: a second step offers "Back up now", since a daily
// schedule proves nothing for up to 24 hours.
export function SetupBackupsDialog({
  open,
  onOpenChange,
  applications = [],
  destinations = [],
  // Preselected when opened from a specific row's "Set up" button.
  applicationId = null,
  // An existing configuration turns this into an edit, so the application page
  // reuses this form rather than keeping its own copy.
  target = null,
  // How many backups retention would count now (null = unknown), for the prune warning.
  keptCount = null,
  // Passed straight through to the fields: which sites have a database, so the
  // form can say when a database backup would hold nothing.
  databaseCounts = null,
  databasesKnown = false,
  // `GET /backup-targets/options`, read by the page. Null when that failed.
  options: initialOptions = null,
  // The site's name when the dialog is fixed to one site (the application
  // page), which passes no `applications` list to look it up in.
  applicationName = null,
  // Site type catalogue, and the fixed site's type — see BackupSettingsFields.
  siteTypes = null,
  siteType = null,
  // Called after the saved step's "Back up now" is accepted.
  onStarted,
}) {
  const t = useTranslations("backups.setup");
  const { refreshAndWait } = useRefresh();
  const [saved, setSaved] = useState(null);
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const [running, setRunning] = useState(false);
  const [finishing, setFinishing] = useState(false);
  // "Add destination" opens a new tab, so destinations are re-read. Null until
  // refreshed, so the prop stays authoritative; cleared on close.
  const [refreshed, setRefreshed] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const available = refreshed ?? destinations;

  // Re-read here only when the page's read failed and someone pressed retry.
  const [fetchedOptions, setFetchedOptions] = useState(null);
  const [loadingOptions, setLoadingOptions] = useState(false);
  const options = initialOptions ?? fetchedOptions;
  // The resolver is fixed when the form is created; the ref lets it validate
  // against options that arrived after that.
  const optionsRef = useRef(options);
  useEffect(() => {
    optionsRef.current = options;
  }, [options]);

  const form = useForm({
    resolver: (values, context, resolverOptions) =>
      zodResolver(backupTargetFormSchema(optionsRef.current))(values, context, resolverOptions),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults(applicationId, destinations, target, options),
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
      // A new target was seeded before there was a default to seed it with.
      if (!target && !form.getValues("frequency")) {
        form.setValue("frequency", parsed.data.default_frequency);
      }
    } catch (error) {
      toast.error(apiMessage(error, t("optionsFailed")));
    } finally {
      setLoadingOptions(false);
    }
  }

  // Reopening from a different row must not inherit the previous row's site.
  useEffect(() => {
    if (open) form.reset(defaults(applicationId, destinations, target, options));
    // `destinations` and `target` are excluded deliberately: both change
    // identity every parent render, and re-seeding would overwrite a half-filled form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, applicationId]);

  async function onSubmit(values) {
    try {
      await saveBackupTarget(values.application_id, {
        storage_destination_id: values.storage_destination_id,
        type: values.type,
        retention_count: values.retention_count,
        frequency: values.frequency,
        // Only for a frequency that reads it; `manual` never runs on a schedule.
        ...(timeUsage(options, values.frequency) ? { schedule_time: values.schedule_time } : null),
        enabled: values.enabled,
        file_excludes: values.file_excludes,
        database_excludes: values.database_excludes,
      });

      // An edit that keeps the same storage has nothing new to prove: close with a
      // toast instead of the first-setup "Back up now" step. Edits come from the
      // application page, where a refresh keeps this dialog mounted.
      if (target && Number(values.storage_destination_id) === Number(target.storage_destination_id)) {
        await finish();
        toast.success(t("savedChanges"));
        return;
      }

      const application = applications.find(
        (candidate) => candidate.id === Number(values.application_id),
      );
      // No refresh here: on the empty state it would unmount this dialog and
      // lose the "Back up now" step.
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
      await runBackupNow(saved.id);
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

  // Esc, the X and Cancel all come through here; unsaved edits ask first.
  function requestClose() {
    if (!saved && form.formState.isDirty && !form.formState.isSubmitting) {
      setConfirmDiscard(true);
      return;
    }
    close();
  }

  // Leaving the saved step: the page behind has not seen the new settings yet.
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
    // Back to the prop: the page's list is fresher once the form is closed.
    setRefreshed(null);
    form.reset(defaults(applicationId, destinations, target, options));
    onOpenChange?.(false);
  }

  const submitting = form.formState.isSubmitting;
  // useWatch, not form.watch(): the latter returns a fresh function every
  // render and opts the whole component out of the React compiler.
  const values = useWatch({ control: form.control });
  // Not `router.refresh()`, which would re-render this dialog's parent.
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

      // A first destination fills the empty field; never overwrites a choice.
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

  // What is missing, in form order, shown beside the disabled primary action.
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
        {/* "Tonight's run" means nothing when nothing is scheduled. */}
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
          target ? t("editSubtitle") : applications.length ? t("subtitle") : t("subtitleSite")
        }
        footer={
          <>
            {blocker ? (
              // Full width on a phone: with `mr-auto` in a nowrap row it was clipped.
              <span className="w-full text-xs text-muted-foreground sm:mr-auto sm:w-auto">
                {blocker}
              </span>
            ) : null}
            <Button type="button" variant="outline" onClick={requestClose} disabled={submitting}>
              {t("cancel")}
            </Button>
            {/* Repeats the blocker for keyboard users who never see the paragraph. */}
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
          options={options}
          onRetryOptions={retryOptions}
          retryingOptions={loadingOptions}
        />

        {/* Reads the answers back in one line before saving. */}
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


/** The whole configuration as one sentence: site · what · when · how many · where. */
function SummaryLine({ values, applications, applicationName = null, destinations, options }) {
  const t = useTranslations("backups.setup");
  const tf = useTranslations("backups.form");

  // The application page passes no list, so fall back to `applicationName`.
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

/** Every field starts with a sane value, so Save works without any decisions. */
function defaults(applicationId, destinations, target, options) {
  if (target) {
    // Disabled and manual mean the same to the backend, and the form has one
    // switch for both, so normalise here.
    const automatic = target.enabled && target.frequency !== "manual";
    return {
      application_id: target.application_id ?? applicationId ?? "",
      storage_destination_id: target.storage_destination_id,
      type: target.type,
      retention_count: target.retention_count,
      frequency: automatic ? target.frequency : "manual",
      // Fallback for targets saved before `schedule_time` existed.
      schedule_time: target.schedule_time ?? BACKUP_DEFAULT_TIME,
      enabled: automatic,
      file_excludes: target.file_excludes ?? [],
      database_excludes: target.database_excludes ?? [],
    };
  }

  return {
    application_id: applicationId ?? "",
    storage_destination_id: destinations.length === 1 ? destinations[0].id : "",
    type: "full",
    retention_count: 7,
    frequency: options?.default_frequency ?? "",
    schedule_time: BACKUP_DEFAULT_TIME,
    enabled: true,
    file_excludes: [],
    database_excludes: [],
  };
}
