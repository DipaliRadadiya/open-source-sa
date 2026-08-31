"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils";
import {
  CalendarClock,
  CircleAlert,
  CircleCheck,
  Loader2,
  Lock,
  Power,
  RotateCcw,
  ShieldCheck,
  TriangleAlert,
} from "lucide-react";
import {
  updatesFormSchema,
  scheduleFormSchema,
  MAX_DAY_OF_MONTH,
  REBOOT_DELAY_OPTIONS,
} from "@/lib/schemas/settings";
import {
  updateUpdateSettings,
  updateRebootSchedule,
  rebootServer,
} from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { apiMessage } from "@/lib/api/error-message";
import { useServerRestart } from "@/components/sections/server-restart-overlay";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Form, FormField, FormControl } from "@/components/ui/form";
import {
  Row,
  InfoRow,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const DAYS_OF_MONTH = Array.from({ length: MAX_DAY_OF_MONTH }, (_, i) => i + 1);

/**
 * Updates and restarts: three cards, three intents, three actions.
 *
 * Not one card with three sections — each group commits to its own endpoint,
 * and a card boundary is what makes "this button saves these rows" legible
 * without reading anything. Manual restart has no Save at all, because it has
 * nothing to persist.
 */
export function MaintenanceCard({
  updates,
  schedule,
  rebootRequired,
  presets,
  presetsFailed,
  canManage,
}) {
  const tc = useTranslations("settings.common");

  return (
    // The banner below says it once, visibly. This says the same thing to each
    // disabled control, so hovering one does not contradict the banner with a
    // generic line — and names the permission to ask for.
    <DisabledReasonProvider reason={canManage ? null : tc("noPermission")}>
    <div className="space-y-4">
      {/* Said once above the three cards rather than repeated in each, or left
          as three disabled buttons with the reason hidden behind a hover. */}
      {!canManage ? (
        <p className="flex w-fit items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
          <Lock className="size-3.5 shrink-0" />
          {tc("readOnly")}
        </p>
      ) : null}

      <UpdatesSection updates={updates} canManage={canManage} />

      <ScheduleSection
        schedule={schedule}
        presets={presets}
        presetsFailed={presetsFailed}
        canManage={canManage}
      />

      <ManualSection canManage={canManage} rebootRequired={rebootRequired} />
    </div>
    </DisabledReasonProvider>
  );
}


/**
 * How many updates are waiting, and whether the automation is alive.
 *
 * Two separate facts, and the second is the one that bites: unattended-upgrades
 * can be switched on and silently broken for months, and a toggle reading "on"
 * is not evidence that anything ran. `unattended_last_result` distinguishes
 * never-run from ran-and-failed, and both get said out loud.
 */
function UpdateStatus({ updates }) {
  const t = useTranslations("settings.maintenance");
  const total = updates?.updates_available;
  const security = updates?.security_updates_available ?? 0;

  // Null means the server did not report — say nothing rather than "0 updates",
  // which is a claim we cannot support.
  if (total == null) return null;

  const failed = updates?.unattended_last_result === "failed";
  const neverRun =
    updates?.security_updates_enabled && !updates?.unattended_last_run_at;
  const tone = failed
    ? "border-destructive/30 bg-destructive/5 text-destructive"
    : security > 0
      ? "border-warning/40 bg-warning/10"
      : "border-success/30 bg-success/5";

  return (
    <div className={cn("mt-3.5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3.5 py-2.5 text-sm", tone)}>
      {failed ? (
        <CircleAlert className="size-4 shrink-0" />
      ) : security > 0 ? (
        <TriangleAlert className="size-4 shrink-0 text-warning" />
      ) : (
        <CircleCheck className="size-4 shrink-0 text-success" />
      )}

      <span className="font-medium">
        {total > 0 ? t("updates.pending", { total, security }) : t("updates.upToDate")}
      </span>

      <span className="text-xs text-muted-foreground">
        {updates?.lists_refreshed_at_human
          ? t("updates.checked", { when: updates.lists_refreshed_at_human })
          : t("updates.neverChecked")}
      </span>

      {/* Only worth saying when the automation claims to be doing something. */}
      {failed ? (
        <span className="text-xs">{t("updates.lastFailed")}</span>
      ) : neverRun ? (
        <span className="text-xs text-muted-foreground">{t("updates.neverRun")}</span>
      ) : updates?.unattended_last_run_at_human ? (
        <span className="text-xs text-muted-foreground">
          {t("updates.lastRun", { when: updates.unattended_last_run_at_human })}
        </span>
      ) : null}
    </div>
  );
}

function UpdatesSection({ updates, canManage }) {
  const t = useTranslations("settings.maintenance");
  const tv = useTranslations("settings.validation");
  const router = useRouter();

  const defaults = {
    security_updates_enabled: updates?.security_updates_enabled ?? false,
    auto_reboot: updates?.auto_reboot ?? false,
    // The API also accepts the literal "now"; a time field can't express that,
    // so an existing "now" is shown as a real time the user can edit.
    reboot_time: /^\d{2}:\d{2}$/.test(updates?.reboot_time ?? "")
      ? updates.reboot_time
      : "03:00",
    reboot_with_users: updates?.reboot_with_users ?? false,
  };

  const form = useForm({
    resolver: zodResolver(updatesFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const autoReboot = useWatch({ control: form.control, name: "auto_reboot" });

  async function onSubmit(values) {
    try {
      await updateUpdateSettings(values);
      toast.success(t("updates.saved"));
      form.reset(values);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={ShieldCheck}
          title={t("updates.title")}
          description={t("updates.description")}
          actions={
            <SectionActions
              label={t("updates.save")}
              isDirty={form.formState.isDirty}
              pending={form.formState.isSubmitting}
              onDiscard={() => form.reset(defaults)}
              canManage={canManage}
            />
          }
        >
          {/* What is actually waiting. The two switches below describe intent;
              until now nothing on the page reported the result — you could not
              tell a patched server from one 43 updates behind. */}
          <UpdateStatus updates={updates} />

          <FormField
            control={form.control}
            name="security_updates_enabled"
            render={({ field }) => (
              <Row
                label={t("updates.security")}
                hint={t("updates.securityHint")}
              >
                <FormControl>
                  <Switch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={!canManage}
                  />
                </FormControl>
              </Row>
            )}
          />

          <FormField
            control={form.control}
            name="auto_reboot"
            render={({ field }) => (
              <Row
                label={t("updates.afterUpdate")}
                hint={t("updates.afterUpdateHint")}
              >
                <FormControl>
                  <Switch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={!canManage}
                  />
                </FormControl>
              </Row>
            )}
          />

          {/* Meaningless until something can trigger that restart, so they
              appear with it rather than sitting greyed out. */}
          {autoReboot ? (
            <>
              <FormField
                control={form.control}
                name="reboot_time"
                render={({ field }) => (
                  <Row
                    label={t("updates.rebootTime")}
                    hint={t("updates.rebootTimeHint")}
                    required
                    error={validationMessage(
                      tv,
                      form.formState.errors.reboot_time?.message,
                    )}
                  >
                    <FormControl>
                      <Input
                        placeholder="03:00"
                        type="time"
                        className="w-full font-mono"
                        disabled={!canManage}
                        {...field}
                      />
                    </FormControl>
                  </Row>
                )}
              />

              <FormField
                control={form.control}
                name="reboot_with_users"
                render={({ field }) => (
                  <Row
                    label={t("updates.withUsers")}
                    hint={t("updates.withUsersHint")}
                  >
                    <FormControl>
                      <Switch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        disabled={!canManage}
                      />
                    </FormControl>
                  </Row>
                )}
              />
            </>
          ) : null}
        </Section>
      </form>
    </Form>
  );
}

function ScheduleSection({ schedule, presets, presetsFailed, canManage }) {
  const t = useTranslations("settings.maintenance");
  const router = useRouter();

  const defaults = {
    enabled: schedule?.enabled ?? false,
    frequency: schedule?.frequency ?? "weekly",
    // The API adds its own few minutes past the hour, so this never lands on
    // the same tick as every other :00 cron job.
    hour: schedule?.hour ?? 3,
    day_of_week: schedule?.day_of_week ?? 0,
    day_of_month: schedule?.day_of_month ?? 1,
  };

  const form = useForm({
    resolver: zodResolver(scheduleFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const enabled = useWatch({ control: form.control, name: "enabled" });
  const frequency = useWatch({ control: form.control, name: "frequency" });

  async function onSubmit(values) {
    try {
      // Off removes the cron file; sending a cadence alongside would describe a
      // schedule that is about to stop existing.
      await updateRebootSchedule(values.enabled ? values : { enabled: false });
      toast.success(
        values.enabled ? t("schedule.saved") : t("schedule.turnedOff"),
      );
      form.reset(values);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={CalendarClock}
          title={t("schedule.title")}
          description={t("schedule.description")}
          actions={
            <SectionActions
              label={t("schedule.save")}
              isDirty={form.formState.isDirty}
              pending={form.formState.isSubmitting}
              onDiscard={() => form.reset(defaults)}
              canManage={canManage}
            />
          }
        >
          <FormField
            control={form.control}
            name="enabled"
            render={({ field }) => (
              <Row
                label={t("schedule.enable")}
                hint={
                  presetsFailed
                    ? t("schedule.optionsFailedHint")
                    : t("schedule.enableHint")
                }
              >
                <FormControl>
                  <Switch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={!canManage || presetsFailed}
                  />
                </FormControl>
              </Row>
            )}
          />

          {enabled && !presetsFailed ? (
            <>
              <FormField
                control={form.control}
                name="frequency"
                render={({ field }) => (
                  <Row label={t("schedule.frequency")}>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={!canManage}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {presets.frequencies.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Row>
                )}
              />

              {frequency === "weekly" ? (
                <FormField
                  control={form.control}
                  name="day_of_week"
                  render={({ field }) => (
                    <Row label={t("schedule.dayOfWeek")}>
                      <Select
                        value={String(field.value)}
                        onValueChange={(value) => field.onChange(Number(value))}
                        disabled={!canManage}
                      >
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {presets.days_of_week.map((option) => (
                            <SelectItem
                              key={option.value}
                              value={String(option.value)}
                            >
                              {option.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Row>
                  )}
                />
              ) : null}

              {frequency === "monthly" ? (
                <FormField
                  control={form.control}
                  name="day_of_month"
                  render={({ field }) => (
                    <Row label={t("schedule.dayOfMonth")}>
                      <Select
                        value={String(field.value)}
                        onValueChange={(value) => field.onChange(Number(value))}
                        disabled={!canManage}
                      >
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {DAYS_OF_MONTH.map((day) => (
                            <SelectItem key={day} value={String(day)}>
                              {day}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Row>
                  )}
                />
              ) : null}

              <FormField
                control={form.control}
                name="hour"
                render={({ field }) => (
                  <Row
                    label={t("schedule.hour")}
                    hint={t("schedule.whenHint", {
                      timezone: schedule?.timezone ?? t("schedule.serverTime"),
                    })}
                  >
                    <Select
                      value={String(field.value)}
                      onValueChange={(value) => field.onChange(Number(value))}
                      disabled={!canManage}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {presets.hours.map((option) => (
                          <SelectItem
                            key={option.value}
                            value={String(option.value)}
                          >
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Row>
                )}
              />
            </>
          ) : null}

          {/* Reported, not set — the API computes it from the expression
              actually on disk, so it is the one honest answer to "when". */}
          <InfoRow label={t("schedule.nextLabel")}>
            <span className="text-sm tabular-nums">
              {enabled && schedule?.next_run_human
                ? schedule.next_run_human
                : t("schedule.notScheduled")}
            </span>
          </InfoRow>
        </Section>
      </form>
    </Form>
  );
}

/**
 * No Save: this section has nothing to persist. Its only action happens now,
 * behind a confirmation that says what goes offline.
 */
function ManualSection({ canManage, rebootRequired }) {
  const t = useTranslations("settings.maintenance");
  const router = useRouter();
  const { start } = useServerRestart();
  const [delay, setDelay] = useState("0");
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);

  async function confirm() {
    setPending(true);
    try {
      const minutes = Number(delay);
      await rebootServer(minutes);
      setConfirming(false);

      // Only an immediate restart gets the curtain. A scheduled one has not
      // started — there is nothing to watch yet, and covering the panel for
      // the next hour would be absurd.
      if (minutes === 0) {
        start();
      } else {
        toast.success(t("reboot.scheduled", { minutes }));
        router.refresh();
      }
    } catch (error) {
      toast.error(apiMessage(error, t("reboot.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Section
      icon={TriangleAlert}
      title={t("reboot.title")}
      description={t("reboot.summary")}
      tone="destructive"
      badge={
        rebootRequired ? (
          <Badge variant="warning" className="font-normal">
            {t("summary.pendingYes")}
          </Badge>
        ) : null
      }
      actions={
        <Button
          type="button"
          variant="destructive"
          disabled={!canManage}
          onClick={() => setConfirming(true)}
        >
          {t("reboot.action")}
        </Button>
      }
    >
      {/* The one explanation kept inline: you need it BEFORE you press, not
          after. */}
      {rebootRequired ? (
        <p className="mt-3.5 flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <RotateCcw className="mt-0.5 size-4 shrink-0 text-warning" />
          {t("rebootRequired.title")}
        </p>
      ) : null}

      <p className="pt-3.5 text-sm text-muted-foreground">
        {t("reboot.description")}
      </p>

      {/* The dropdown said "Right now" with nothing saying right now WHAT. */}
      <InfoRow label={t("reboot.when")}>
        <Select value={delay} onValueChange={setDelay} disabled={!canManage}>
          <SelectTrigger id="reboot-delay" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {REBOOT_DELAY_OPTIONS.map((minutes) => (
              <SelectItem key={minutes} value={String(minutes)}>
                {minutes === 0
                  ? t("reboot.now")
                  : t("reboot.inMinutes", { minutes })}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </InfoRow>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        icon={Power}
        tone="destructive"
        title={t("reboot.confirmTitle")}
        description={
          Number(delay) === 0
            ? t("reboot.confirmNow")
            : t("reboot.confirmDelayed", { minutes: Number(delay) })
        }
        cancelLabel={t("reboot.confirmCancel")}
        confirmLabel={t("reboot.confirmSubmit")}
        pending={pending}
        onConfirm={confirm}
      />
    </Section>
  );
}
