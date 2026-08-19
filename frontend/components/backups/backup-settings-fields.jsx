"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { useWatch } from "react-hook-form";
import {
  CalendarClock,
  ChevronDown,
  Database,
  ExternalLink,
  HardDrive,
  Layers,
  TriangleAlert,
} from "lucide-react";
import { BACKUP_TYPES } from "@/lib/schemas/backup";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Combobox } from "@/components/ui/combobox";
import { ChoiceField } from "@/components/ui/choice-field";
import { Caution } from "@/components/ui/caution";
import { Button } from "@/components/ui/button";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/**
 * The backup settings form — one definition, two doors.
 *
 * Grouped into named steps rather than a flat run of fields: which site, what
 * to copy, when, and where it goes. Flat, the same nine controls read as a
 * pile with no shape, and people could not tell which answer belonged to which
 * question.
 */

const DAYS_PER_RUN = { daily: 1, weekly: 7, monthly: 30 };

/**
 * Patterns worth excluding, offered rather than applied.
 *
 * RunCloud excludes these automatically and their own documentation admits it
 * breaks applications that need `vendor` present at runtime. Silently dropping
 * a directory someone's site needs — and only finding out during a restore —
 * is worse than a slightly larger archive, so these are one click, not a
 * default.
 */
const SUGGESTED_FILE_EXCLUDES = ["node_modules", ".git", "vendor", "storage/logs", "*.log"];

/**
 * Which half of the archive a type change drops, or null when it drops nothing.
 *
 * `full` → `filesystem` stops copying databases; `full` → `database` stops
 * copying files. Widening, and setting a type for the first time, take nothing
 * away and say nothing.
 */
function droppedByNarrowing(current, next) {
  if (!current || !next || current === next) return null;
  if (current === "full" && next === "filesystem") return "narrower";
  if (current === "full" && next === "database") return "narrowerFiles";
  return null;
}

function Group({ icon: Icon, title, children }) {
  return (
    <section className="space-y-3">
      <h3 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
        <Icon className="size-4 text-muted-foreground" />
        {title}
      </h3>
      {children}
    </section>
  );
}

export function BackupSettingsFields({
  form,
  applications,
  destinations = [],
  disabled = false,
  // The configuration as it stands today. Only used to warn about changes that
  // take something away — there is nothing to warn about on a new target.
  target = null,
}) {
  const t = useTranslations("backups.form");
  const automatic = useWatch({ control: form.control, name: "enabled" });
  const frequency = useWatch({ control: form.control, name: "frequency" });
  const retention = useWatch({ control: form.control, name: "retention_count" });
  const type = useWatch({ control: form.control, name: "type" });

  const destinationId = useWatch({ control: form.control, name: "storage_destination_id" });

  const hasDestinations = destinations.length > 0;
  const onlyDestination = destinations.length === 1 ? destinations[0] : null;

  // Whichever destination this form is about to write to, if its own
  // connection test last failed. `onlyDestination` counts too — the single-
  // destination case renders a plain row with no picker, and that is precisely
  // the install where the one bucket being broken matters most.
  const chosenDestination =
    onlyDestination ?? destinations.find((d) => String(d.id) === String(destinationId)) ?? null;
  const failingDestination =
    chosenDestination && chosenDestination.last_test_success === false ? chosenDestination : null;

  // Narrowing what gets copied is silent data loss on a delay: every future
  // run drops something, and nobody finds out until a restore comes up short.
  // Forge asks a second time for the same reason.
  const dropping = droppedByNarrowing(target?.type, type);
  // Lowering retention prunes on the very next run. "Keeps 3" explains the
  // future; it does not say four archives are about to be deleted.
  const pruning =
    target && Number(retention) > 0 && Number(retention) < target.retention_count
      ? target.retention_count - Number(retention)
      : 0;

  return (
    <div className="space-y-6">
      {/* `applications?.length`, not `applications` — an empty array is truthy,
          which rendered an empty site picker in the application page's edit
          modal where the site is already fixed. */}
      {applications?.length ? (
        <Group icon={Layers} title={t("groups.site")}>
          <FormField
            control={form.control}
            name="application_id"
            render={({ field }) => (
              <FormItem>
                <FormControl>
                  <Combobox
                    options={applications.map((application) => ({
                      value: application.id,
                      label: application.name,
                      hint: application.domain,
                    }))}
                    value={field.value}
                    onChange={field.onChange}
                    disabled={disabled}
                    placeholder={t("applicationPlaceholder")}
                    searchPlaceholder={t("applicationSearch")}
                    empty={t("applicationEmpty")}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </Group>
      ) : null}

      <Group icon={Database} title={t("groups.content")}>
        <FormField
          control={form.control}
          name="type"
          render={({ field }) => (
            <FormItem>
              <FormControl>
                <ChoiceField
                  value={field.value}
                  onChange={field.onChange}
                  disabled={disabled}
                  variant="card"
                  className="sm:grid sm:grid-cols-3 sm:gap-2"
                  options={BACKUP_TYPES.map((type) => ({
                    value: type,
                    label: t(`types.${type}.label`),
                    hint: t(`types.${type}.hint`),
                  }))}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        {dropping ? <Caution>{t(`warnings.${dropping}`)}</Caution> : null}
      </Group>

      <Group icon={CalendarClock} title={t("groups.schedule")}>
        {/* One switch, not a switch AND a "manual" frequency option. The
            backend treats `enabled: false` and `frequency: manual` identically,
            so exposing both invents a combination that means nothing and lets
            someone set a daily schedule that never runs. Off IS manual. */}
        <FormField
          control={form.control}
          name="enabled"
          render={({ field }) => (
            <FormItem className="flex items-start justify-between gap-4 rounded-lg border p-3">
              <div className="space-y-1">
                <FormLabel>{t("automatic")}</FormLabel>
                <FormDescription>
                  {automatic ? t("automaticOn") : t("automaticOff")}
                </FormDescription>
              </div>
              <FormControl>
                <Switch
                  checked={field.value}
                  onCheckedChange={(next) => {
                    field.onChange(next);
                    form.setValue("frequency", next ? "daily" : "manual", { shouldDirty: true });
                  }}
                  disabled={disabled}
                />
              </FormControl>
            </FormItem>
          )}
        />

        {automatic ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="frequency"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("frequency")}</FormLabel>
                  <FormControl>
                    <Combobox
                      options={["daily", "weekly", "monthly"].map((value) => ({
                        value,
                        label: t(`frequencies.${value}`),
                      }))}
                      value={field.value}
                      onChange={field.onChange}
                      disabled={disabled}
                      placeholder={t("frequencyPlaceholder")}
                    />
                  </FormControl>
                  <FormDescription>{t(`frequencyHint.${field.value ?? "daily"}`)}</FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            {/* Until now the card said "Daily" and never said when — every
                site on the server backed up at 02:00 with nothing on screen
                admitting it. A native time input rather than a picker: this is
                one value the OS already has a good control for, and it hands
                back exactly the "HH:MM" the API validates. */}
            <FormField
              control={form.control}
              name="schedule_time"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("scheduleTime")}</FormLabel>
                  <FormControl>
                    <Input
                      {...field}
                      type="time"
                      step={60}
                      disabled={disabled}
                      className="w-full tabular-nums"
                    />
                  </FormControl>
                  {/* Server time, said out loud for the same reason the cron
                      dialog says it: a browser in another timezone would
                      otherwise read this as local and be hours out. The zone's
                      name is not worth a `/server/facts` shell-out on three
                      more pages — "not your computer's" is the part that
                      prevents the mistake. */}
                  <FormDescription>{t("scheduleTimeHint")}</FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="retention_count"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("retention")}</FormLabel>
                  <FormControl>
                    <Input
                      type="number"
                      inputMode="numeric"
                      min={1}
                      max={365}
                      disabled={disabled}
                      {...field}
                    />
                  </FormControl>
                  <FormDescription>
                    {t("retentionHint", {
                      count: Number(retention) || 0,
                      days: (Number(retention) || 0) * (DAYS_PER_RUN[frequency] ?? 1),
                    })}
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>
        ) : null}
        {automatic && pruning > 0 ? (
          <Caution>{t("warnings.retentionDown", { count: pruning })}</Caution>
        ) : null}
      </Group>

      <Group icon={HardDrive} title={t("groups.storage")}>
        {!hasDestinations ? (
          <div className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <div className="space-y-2">
              <p>{t("noDestinations")}</p>
              <Button asChild size="sm" variant="outline">
                <Link href="/integrations/storage" target="_blank" rel="noreferrer" prefetch={false}>
                  <HardDrive className="size-4" />
                  {t("addDestination")}
                  <ExternalLink className="size-3.5" />
                </Link>
              </Button>
            </div>
          </div>
        ) : (
          <FormField
            control={form.control}
            name="storage_destination_id"
            render={({ field }) => (
              <FormItem>
                {/* A dropdown holding one option is a question with no answer,
                    so a single destination is stated as a fact — but as a
                    proper bordered row, not a grey aside, because where the
                    archives land is not a footnote. */}
                {onlyDestination ? (
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-muted/30 px-3 py-2.5 text-sm">
                    <HardDrive className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 font-medium break-all">{onlyDestination.name}</span>
                    <span className="text-muted-foreground">{onlyDestination.bucket}</span>
                    <Link
                      href="/integrations/storage"
                      target="_blank" rel="noreferrer" prefetch={false}
                      className="ml-auto inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
                    >
                      {t("manageStorage")}
                      <ExternalLink className="size-3" />
                    </Link>
                  </div>
                ) : (
                  <FormControl>
                    <Combobox
                      options={destinations.map((destination) => ({
                        value: destination.id,
                        label: destination.name,
                        hint: destination.bucket,
                      }))}
                      value={field.value}
                      onChange={field.onChange}
                      disabled={disabled}
                      placeholder={t("destinationPlaceholder")}
                      searchPlaceholder={t("destinationSearch")}
                      empty={t("destinationEmpty")}
                    />
                  </FormControl>
                )}
                {/* The chosen destination is failing its own connection test.
                    Nothing here warned about it, so backups could be pointed at
                    a bucket already known to reject writes and every run would
                    fail — the server-level screen would say why, days later.
                    Not a blocker: credentials get fixed, and refusing to save
                    would strand someone mid-repair. */}
                {failingDestination ? (
                  <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-warning" />
                    <span>
                      {t("destinationFailing", { name: failingDestination.name })}{" "}
                      <Link
                        href="/integrations/storage"
                        target="_blank" rel="noreferrer" prefetch={false}
                        className="inline-flex items-center gap-1 font-medium text-primary underline-offset-4 hover:underline"
                      >
                        {t("manageStorage")}
                        <ExternalLink className="size-3" />
                      </Link>
                    </span>
                  </p>
                ) : null}
                <FormMessage />
              </FormItem>
            )}
          />
        )}
      </Group>

      <Collapsible>
        <CollapsibleTrigger className="group flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">
          <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
          {t("excludeTitle")}
        </CollapsibleTrigger>
        <CollapsibleContent className="space-y-3 pt-3 data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
          {/* One short line for both fields, at the top. Two separate
              paragraphs under two separate boxes was 173 characters of prose
              in a column narrow enough to wrap it over four lines. */}
          <p className="text-xs text-muted-foreground">{t("excludeHint")}</p>

          {/* `items-start` matters: the suggestion chips used to live inside
              the left cell, which made that cell taller and stretched the
              right-hand textarea to 112px against the left's 64px. The chips
              are now their own full-width row, so neither column can push the
              other around. */}
          <div className="grid items-start gap-4 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="file_excludes"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("fileExcludes")}</FormLabel>
                  <FormControl>
                    <Textarea
                      // `field-sizing-fixed` matters: the base Textarea sets
                      // `field-sizing-content`, so it grows with what you type
                      // and `rows` is ignored. Adding five suggestions to one
                      // box stretched it to five lines while its neighbour
                      // stayed at one — the uneven pair. Fixed height, scroll
                      // past it.
                      className="h-24 resize-none overflow-y-auto font-mono text-xs field-sizing-fixed"
                      disabled={disabled}
                      placeholder={t("fileExcludesPlaceholder")}
                      value={(field.value ?? []).join("\n")}
                      onChange={(event) => field.onChange(toLines(event.target.value))}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="database_excludes"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("databaseExcludes")}</FormLabel>
                  <FormControl>
                    <Textarea
                      // `field-sizing-fixed` matters: the base Textarea sets
                      // `field-sizing-content`, so it grows with what you type
                      // and `rows` is ignored. Adding five suggestions to one
                      // box stretched it to five lines while its neighbour
                      // stayed at one — the uneven pair. Fixed height, scroll
                      // past it.
                      className="h-24 resize-none overflow-y-auto font-mono text-xs field-sizing-fixed"
                      disabled={disabled}
                      placeholder={t("databaseExcludesPlaceholder")}
                      value={(field.value ?? []).join("\n")}
                      onChange={(event) => field.onChange(toLines(event.target.value))}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>

          <FormField
            control={form.control}
            name="file_excludes"
            render={({ field }) => {
              // Already-added patterns leave the row rather than sitting there
              // greyed out. A line of five dead buttons reads as broken; a
              // shrinking list reads as progress, and the row disappears once
              // there is nothing left to offer.
              const remaining = SUGGESTED_FILE_EXCLUDES.filter(
                (pattern) => !(field.value ?? []).includes(pattern),
              );
              if (remaining.length === 0) return <span />;

              return (
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="text-xs text-muted-foreground">{t("suggestions")}</span>
                  {remaining.map((pattern) => (
                    <Button
                      key={pattern}
                      type="button"
                      size="sm"
                      variant="outline"
                      className="h-6 px-2 font-mono text-xs font-normal"
                      disabled={disabled}
                      onClick={() => field.onChange([...(field.value ?? []), pattern])}
                    >
                      {pattern}
                    </Button>
                  ))}
                </div>
              );
            }}
          />
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
}

/** One pattern per line; blank lines are typing, not intent. */
function toLines(value) {
  return value
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean);
}
