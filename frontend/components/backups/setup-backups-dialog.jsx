import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, PlayCircle, ShieldCheck } from "lucide-react";
import { BACKUP_DEFAULT_TIME, backupTargetFormSchema } from "@/lib/schemas/backup";
import { runBackupNow, saveBackupTarget } from "@/lib/api/backups";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Form } from "@/components/ui/form";
import { FormModal } from "@/components/ui/form-modal";
import { BackupSettingsFields } from "@/components/backups/backup-settings-fields";

/**
 * Setting up backups for a site, from the Backups screen.
 *
 * The application is the first field rather than a page you have to navigate
 * into first: someone looking for "Backups" looks in the sidebar, and the id
 * the API wants is just a value on the form.
 *
 * Two states. After saving it does NOT simply close — a schedule that says
 * "daily" has produced nothing yet, and closing on a toast leaves a new user
 * with no evidence anything works for up to 24 hours. So the second state
 * offers the one action that answers it.
 */
export function SetupBackupsDialog({
  open,
  onOpenChange,
  applications = [],
  destinations = [],
  // Preselected when opened from a specific row's "Set up" button.
  applicationId = null,
  // An existing configuration turns this into an edit: same modal, same
  // fields, prefilled — so the application page never needs its own copy of
  // the form, and the two can never drift apart.
  target = null,
}) {
  const t = useTranslations("backups.setup");
  const router = useRouter();
  const [saved, setSaved] = useState(null);
  const [running, setRunning] = useState(false);

  const form = useForm({
    resolver: zodResolver(backupTargetFormSchema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults(applicationId, destinations, target),
  });

  // Reopening from a different row must not inherit the previous row's site.
  useEffect(() => {
    if (open) form.reset(defaults(applicationId, destinations, target));
    // `destinations` and `target` are excluded deliberately. Both change
    // identity on every parent render, and re-seeding on them would overwrite a
    // half-filled form; `open` going false→true already covers arriving from a
    // different row, which is the case this exists for.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, applicationId]);

  async function onSubmit(values) {
    try {
      await saveBackupTarget(values.application_id, {
        storage_destination_id: values.storage_destination_id,
        type: values.type,
        retention_count: values.retention_count,
        frequency: values.frequency,
        // Only meaningful for a schedule. Sending it with `manual` would store
        // a time for a backup that never runs on its own.
        ...(values.frequency === "manual" ? null : { schedule_time: values.schedule_time }),
        enabled: values.enabled,
        file_excludes: values.file_excludes,
        database_excludes: values.database_excludes,
      });

      const application = applications.find(
        (candidate) => candidate.id === Number(values.application_id),
      );
      setSaved({ id: Number(values.application_id), name: application?.name ?? "", ...values });
      router.refresh();
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
      toast.success(t("started"));
      close();
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setRunning(false);
    }
  }

  function close() {
    setSaved(null);
    form.reset(defaults(applicationId, destinations, target));
    onOpenChange?.(false);
  }

  const submitting = form.formState.isSubmitting;
  // useWatch, not form.watch(): the latter returns a fresh function every
  // render and opts the whole component out of the React compiler.
  const values = useWatch({ control: form.control });
  // Required fields, mirrored from the schema. The primary action stays
  // disabled until they are answered rather than failing on submit.
  const incomplete =
    !values.application_id ||
    !values.storage_destination_id ||
    !values.type ||
    (values.enabled && (!values.frequency || !values.retention_count));

  // What is missing, in the order the form asks for it. A disabled primary
  // action that does not say why is the anti-pattern; this is the sentence.
  const blocker =
    destinations.length === 0
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
        onOpenChange={(next) => (next ? onOpenChange?.(true) : close())}
        icon={CheckCircle2}
        title={t("savedTitle", { name: saved.name })}
        description={
          saved.enabled ? t("savedScheduled") : t("savedManual")
        }
        footer={
          <>
            <Button type="button" variant="outline" onClick={close} disabled={running}>
              {t("done")}
            </Button>
            <Button type="button" onClick={backUpNow} disabled={running}>
              {running ? <Loader2 className="size-4 animate-spin" /> : <PlayCircle className="size-4" />}
              {t("backUpNow")}
            </Button>
          </>
        }
      >
        {/* The reason to press the button, stated plainly. Waiting until
            tonight to discover the credentials are wrong is the failure this
            step exists to prevent. */}
        <p className="text-sm text-muted-foreground">{t("verifyHint")}</p>
      </FormModal>
    );
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={(next) => (next ? onOpenChange?.(true) : close())}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={ShieldCheck}
        className="sm:max-w-xl"
        title={target ? t("editTitle") : t("title")}
        description={target ? t("editSubtitle") : t("subtitle")}
        footer={
          <>
            {blocker ? (
              // Full width on a phone: as `mr-auto` in a nowrap row it was
              // pushed off the left edge of the footer and clipped.
              <span className="w-full text-xs text-muted-foreground sm:mr-auto sm:w-auto">
                {blocker}
              </span>
            ) : null}
            <Button type="button" variant="outline" onClick={close} disabled={submitting}>
              {t("cancel")}
            </Button>
            {/* The blocker is already printed above the button; the tooltip
                repeats it for anyone who reaches the control by keyboard and
                never sees the paragraph. */}
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
          destinations={destinations}
          disabled={submitting}
          target={target}
        />

        {/* What pressing Save will actually do, in one line. Reading your own
            answers back is the cheapest way to catch the wrong site or a
            schedule you did not mean. */}
        <SummaryLine values={values} applications={applications} destinations={destinations} />
      </FormModal>
    </Form>
  );
}


/**
 * The whole configuration as one sentence, read back before committing.
 *
 * Site · what · when · how many · where. Everything above is a control you
 * set one at a time; this is the only place the answers appear together, which
 * is where a wrong site or an unintended `manual` becomes obvious.
 */
function SummaryLine({ values, applications, destinations }) {
  const t = useTranslations("backups.setup");
  const tf = useTranslations("backups.form");

  const site = applications.find((a) => a.id === Number(values.application_id));
  const destination = destinations.find((d) => d.id === Number(values.storage_destination_id));
  if (!site || !values.type) return null;

  const parts = [
    site.name,
    tf(`types.${values.type}.label`),
    values.enabled ? tf(`frequencies.${values.frequency ?? "daily"}`) : tf("automaticOffShort"),
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

/**
 * Opens already answered.
 *
 * Every field carries a sane value so a nervous first-timer can press Save
 * without making a single decision — which for this audience is the whole
 * difference between "set up" and "meant to set up".
 */
function defaults(applicationId, destinations, target) {
  if (target) {
    // A disabled target and a manual one mean the same thing to the backend,
    // and the form has one switch for both — so normalise on the way in, or
    // the switch would read "on" for a schedule that never runs.
    const automatic = target.enabled && target.frequency !== "manual";
    return {
      application_id: target.application_id ?? applicationId ?? "",
      storage_destination_id: target.storage_destination_id,
      type: target.type,
      retention_count: target.retention_count,
      frequency: automatic ? target.frequency : "manual",
      // Falls back to the backend's own 02:00 because `BackupTargetResource`
      // does not return `schedule_time` yet — so a target that HAS a time
      // saved will still open showing 02:00. Remove the fallback the moment
      // the API carries the field.
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
    frequency: "daily",
    schedule_time: BACKUP_DEFAULT_TIME,
    enabled: true,
    file_excludes: [],
    database_excludes: [],
  };
}
