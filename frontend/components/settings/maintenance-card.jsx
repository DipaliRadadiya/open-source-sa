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
  ChevronDown,
  CircleAlert,
  CircleCheck,
  Loader2,
  Lock,
  Power,
  RotateCcw,
  ShieldCheck,
  SquareTerminal,
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
  cancelReboot,
} from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { apiMessage } from "@/lib/api/error-message";
import { useServerRestart } from "@/components/sections/server-restart-overlay";
import { RebootCountdown } from "@/components/settings/reboot-countdown";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
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
  pendingReboot,
  pendingRebootFailed,
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

        <ManualSection
          canManage={canManage}
          rebootRequired={rebootRequired}
          pendingReboot={pendingReboot}
          pendingRebootFailed={pendingRebootFailed}
        />
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

  const failed = updates?.unattended_last_result === "failed";
  // The panel could not open the log, which is not the same answer as the log
  // holding no run. Both were silence before, and only one is a broken panel.
  const unreadable = updates?.unattended_log_readable === false;
  const neverRun =
    updates?.security_updates_enabled && !updates?.unattended_last_run_at;

  // Nothing true left to say. Previously this returned on a null count alone,
  // which meant a failed run went unreported whenever the *unrelated* apt-check
  // command also failed — the reason was hidden behind a different question.
  if (total == null && !failed && !unreadable && !neverRun) return null;

  const tone = failed
    ? "border-destructive/30 bg-destructive/5 text-destructive"
    : unreadable || total == null
      ? // Not green: green here would be a claim about a count nobody has.
        "border-warning/40 bg-warning/10"
      : security > 0
        ? "border-warning/40 bg-warning/10"
        : "border-success/30 bg-success/5";

  return (
    <div
      className={cn(
        "mt-3.5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3.5 py-2.5 text-sm",
        tone,
      )}
    >
      {failed ? (
        <CircleAlert className="size-4 shrink-0" />
      ) : unreadable || total == null || security > 0 ? (
        <TriangleAlert className="size-4 shrink-0 text-warning" />
      ) : (
        <CircleCheck className="size-4 shrink-0 text-success" />
      )}

      {/* Omitted entirely when the count is unknown, rather than guessed at:
        `null` is "nobody knows" and `0` is "nothing is waiting", and the
        sentences below carry the state in either case. */}
      {total == null ? null : (
        <span className="font-medium">
          {total > 0
            ? t("updates.pending", { total, security })
            : t("updates.upToDate")}
        </span>
      )}

      <span className="text-xs text-muted-foreground">
        {updates?.lists_refreshed_at_human
          ? t("updates.checked", { when: updates.lists_refreshed_at_human })
          : t("updates.neverChecked")}
      </span>

      {/* Only worth saying when the automation claims to be doing something. */}
      {failed ? (
        <span className="text-xs">{t("updates.lastFailed")}</span>
      ) : unreadable ? (
        <span className="text-xs">{t("updates.logUnreadable")}</span>
      ) : neverRun ? (
        <span className="text-xs text-muted-foreground">
          {t("updates.neverRun")}
        </span>
      ) : updates?.unattended_last_run_at_human ? (
        <span className="text-xs text-muted-foreground">
          {t("updates.lastRun", { when: updates.unattended_last_run_at_human })}
        </span>
      ) : null}

      {/* The reason, verbatim, on its own line.
       *
       * `w-full` inside the wrapping row rather than a sibling block, so it
       * stays inside the coloured border that already says which state this
       * is. Monospace and untranslated for the same reason `panel:doctor`
       * renders its `detail` that way: this is the string an operator will
       * paste into a search box, and a paraphrase is not searchable.
       *
       * `wrap-anywhere` because a log line has no spaces where it needs them
       * — a long package name would otherwise push the card wider than the
       * column and take the layout with it. */}
      {failed && updates?.unattended_last_error ? (
        <p className="w-full font-mono text-xs wrap-anywhere opacity-90">
          {updates.unattended_last_error}
        </p>
      ) : null}

      {/* And the run behind the line, one click away.
       *
       * Closed by default: the line above answers the common case — a
       * transient apt lock — in a glance, and opening a wall of dpkg output on
       * every page load would bury it. But "why did this package refuse" is
       * only answerable from the log, and dpkg writes that part to a file the
       * panel was not reading at all until now.
       *
       * Absent for a viewer without `setting,manage`, and absent when the
       * excerpt could not be built — never an empty panel pretending there is
       * something to see. */}
      {failed && updates?.unattended_last_log ? (
        <Collapsible className="group/log w-full">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="-ml-2 h-8 gap-2 px-2">
              <SquareTerminal className="size-4" />
              {t("updates.viewLog")}
              <ChevronDown className="size-4 transition-transform group-data-[state=open]/log:rotate-180" />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            {updates.unattended_last_log_truncated ? (
              <p className="mb-1 text-xs text-muted-foreground">
                {t("updates.logTruncated")}
              </p>
            ) : null}
            <pre className="max-h-80 overflow-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100">
              {updates.unattended_last_log}
            </pre>
          </CollapsibleContent>
        </Collapsible>
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
function ManualSection({
  canManage,
  rebootRequired,
  pendingReboot,
  pendingRebootFailed,
}) {
  const t = useTranslations("settings.maintenance");
  const router = useRouter();
  const { start } = useServerRestart();
  const [delay, setDelay] = useState("0");
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  async function cancelPending() {
    setCancelling(true);
    try {
      await cancelReboot();
      toast.success(t("reboot.cancelled"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("reboot.cancelFailed")));
    } finally {
      setCancelling(false);
    }
  }

  async function confirm() {
    setPending(true);
    try {
      const minutes = Number(delay);
      const { data } = await rebootServer(minutes);
      setConfirming(false);

      // Only an immediate restart gets the curtain. A scheduled one has not
      // started — there is nothing to watch yet, and covering the panel for
      // the next hour would be absurd.
      if (minutes === 0) {
        start();
      } else {
        // `at` is the server's own clock. Falling back to "in N minutes" only
        // when it is absent, because adding the delay to the browser's clock
        // is wrong by whatever the two have drifted — on the one value where
        // wrong means expecting a restart at the wrong hour.
        const at = data?.reboot?.at;
        toast.success(
          at
            ? t("reboot.scheduledAt", { at })
            : t("reboot.scheduled", { minutes }),
        );
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
      {/* A restart already counting down outranks everything else on this
          card — including the reason you might want another one. Read from
          systemd, so one scheduled from a shell shows up here too. */}
      {pendingReboot?.scheduled ? (
        <div className="mt-3.5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <span className="flex items-start gap-2">
            <CalendarClock className="mt-0.5 size-4 shrink-0 text-warning" />
            {/* `at` comes from the server's clock. It can be null on a pending
                shutdown whose systemd record has no timestamp — still pending,
                just unable to say when. The countdown leads and the absolute
                time follows it: how long you have is the decision, what time it
                happens is the detail. */}
            {pendingReboot.at ? (
              <span className="flex flex-col gap-0.5">
                {typeof pendingReboot.seconds_remaining === "number" ? (
                  <RebootCountdown
                    // Keyed so a refreshed measurement remounts it and
                    // re-anchors the deadline, rather than leaving it counting
                    // down from a number the server has since revised.
                    key={pendingReboot.seconds_remaining}
                    secondsRemaining={pendingReboot.seconds_remaining}
                    // Zero is where this screen stops being able to tell the
                    // truth: the page was rendered by a server that is now
                    // going down, so "Restarting now…" would sit there
                    // unchanged long after the machine came back. The curtain
                    // watches for that and hard-reloads.
                    onElapsed={start}
                  />
                ) : null}
                <span className="text-muted-foreground">
                  {t("reboot.pendingAt", { at: pendingReboot.at })}
                </span>
              </span>
            ) : (
              t("reboot.pendingUnknownTime")
            )}
          </span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canManage || cancelling}
            onClick={cancelPending}
          >
            {cancelling ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("reboot.cancel")}
          </Button>
        </div>
      ) : pendingRebootFailed ? (
        // Not the same as "nothing scheduled", and this is the card someone
        // opens to decide whether to stop one.
        <p className="mt-3.5 flex items-start gap-2 rounded-lg border p-3 text-sm text-muted-foreground">
          <CircleAlert className="mt-0.5 size-4 shrink-0" />
          {t("reboot.pendingUnknown")}
        </p>
      ) : null}

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
