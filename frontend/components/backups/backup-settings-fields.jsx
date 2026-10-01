import Link from "@/components/ui/app-link";
import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { useWatch } from "react-hook-form";
import {
  CalendarClock,
  ChevronDown,
  Clock,
  Database,
  ExternalLink,
  HardDrive,
  Layers,
  Loader2,
  RotateCw,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
  frequencyOption,
  historySpan,
  minuteOf,
  orderedTypes,
  scheduledFrequencies,
  timeUsage,
  withMinute,
} from "@/lib/backups/frequency";
import { hasNoDatabase, siteNeedsDatabase } from "@/lib/backups/database-availability";
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

// Offered, not applied: excluding `vendor` breaks apps that need it at runtime.
const SUGGESTED_FILE_EXCLUDES = ["node_modules", ".git", "vendor", "storage/logs", "*.log"];

// Which half a type change drops, or null: `full` → `filesystem` drops databases.
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
  // Re-reads the storage list without a page reload; when omitted, no refresh button.
  onRefreshDestinations = null,
  refreshingDestinations = false,
  disabled = false,
  // The saved configuration; only used to warn about changes that take something away.
  target = null,
  // How many databases each site has, and whether that answer is trustworthy.
  // Absent means unknown, which reads the same as "say nothing".
  databaseCounts = null,
  databasesKnown = false,
  // `GET /backup-targets/options`: the frequencies, types and retention bounds
  // this form offers. Null when it could not be read, with a way to ask again.
  options = null,
  onRetryOptions = null,
  retryingOptions = false,
  // The site type catalogue, and the type of the one site when the form is
  // fixed to it. Together they say which sites never have a database at all.
  siteTypes = null,
  siteType = null,
}) {
  const t = useTranslations("backups.form");
  const tv = useTranslations("validation");
  // Only the API knows the scheduler's clock. A new target has none yet, so the
  // timezone line is omitted rather than guessing UTC.
  const scheduleTimezone = target?.timezone ?? options?.timezone ?? null;
  const automatic = useWatch({ control: form.control, name: "enabled" });
  const frequency = useWatch({ control: form.control, name: "frequency" });
  const retention = useWatch({ control: form.control, name: "retention_count" });
  const type = useWatch({ control: form.control, name: "type" });
  const applicationId = useWatch({ control: form.control, name: "application_id" });
  // List field errors are per line (`file_excludes.3`) with no field-level
  // message, so name the first bad line.
  const lineError = (error) => {
    if (!error) return undefined;
    if (error.message) return tv.has(error.message) ? tv(error.message) : error.message;
    const index = Array.isArray(error) ? error.findIndex((item) => item?.message) : -1;
    if (index < 0) return undefined;
    const message = error[index].message;
    return t("excludeLineError", {
      line: index + 1,
      message: tv.has(message) ? tv(message) : message,
    });
  };
  const usage = timeUsage(options, frequency);
  const span = historySpan(frequency, Number(retention));

  const destinationId = useWatch({ control: form.control, name: "storage_destination_id" });

  const hasDestinations = destinations.length > 0;
  const onlyDestination = destinations.length === 1 ? destinations[0] : null;

  // The destination this form will write to, including `onlyDestination`
  // (the single-destination case has no picker).
  const chosenDestination =
    onlyDestination ?? destinations.find((d) => String(d.id) === String(destinationId)) ?? null;
  // A never-connected Google Drive is not probed on creation, so
  // `last_test_success` stays null; `config.connected` catches it.
  const notConnected =
    chosenDestination?.provider === "google_drive_oauth" &&
    chosenDestination?.config?.connected === false;
  const failingDestination =
    chosenDestination && (chosenDestination.last_test_success === false || notConnected)
      ? chosenDestination
      : null;

  // Narrowing what gets copied silently drops data from every future run, so warn.
  const dropping = droppedByNarrowing(target?.type, type);

  // Which exclusions this type will honour. The backend gates its steps on the
  // same `wantsFiles`/`wantsDatabase` checks; anything else would be silently ignored.
  const wantsFiles = type === "filesystem" || type === "full";
  const wantsDatabase = type === "database" || type === "full";

  // `true` no databases, `false` some, `null` unknown — and `null` must stay
  // silent. See `hasNoDatabase`.
  const noDatabase = hasNoDatabase(databaseCounts, databasesKnown, applicationId);

  // Site types with no database ever (n8n, static, ...): "Database only" fails
  // with a misleading disk-space error, and "Full" is really files only.
  const chosenType =
    siteType ?? (applications ?? []).find((application) => String(application.id) === String(applicationId))?.site_type;
  const knownType = Boolean(chosenType) && (siteTypes ?? []).some((entry) => entry.name === chosenType);
  const filesOnly = noDatabase === true && knownType && !siteNeedsDatabase(siteTypes, chosenType);

  // A database-less site cannot keep "Database only" (empty backup), but a type
  // the user picked is left alone.
  const typePicked = Boolean(form.formState.dirtyFields?.type);
  useEffect(() => {
    if (filesOnly && type !== "filesystem") {
      form.setValue("type", "filesystem", { shouldDirty: true });
    } else if (noDatabase && type === "database") {
      form.setValue("type", "full", { shouldDirty: true });
    } else if (noDatabase === true && !target && !typePicked && type === "full") {
      form.setValue("type", "filesystem");
    }
  }, [filesOnly, noDatabase, type, form, target, typePicked]);
  // Lowering retention prunes when the settings are SAVED (SaveBackupTarget
  // applies it in the same request), not on the next run.
  const pruning =
    target && Number(retention) > 0 && Number(retention) < target.retention_count
      ? target.retention_count - Number(retention)
      : 0;

  return (
    <div className="space-y-6">
      {/* `applications?.length`: an empty array is truthy and would render an
          empty site picker where the site is fixed. */}
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

      {!options ? (
        <div className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
          <div className="space-y-2">
            <p>{t("optionsFailed")}</p>
            {onRetryOptions ? (
              <Button type="button" size="sm" variant="outline" disabled={retryingOptions} onClick={onRetryOptions}>
                {retryingOptions ? <Loader2 className="size-4 animate-spin" /> : <RotateCw className="size-4" />}
                {t("optionsRetry")}
              </Button>
            ) : null}
          </div>
        </div>
      ) : (
      <>
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
                  options={orderedTypes(options).map(({ value: option, label }) => ({
                    value: option,
                    label,
                    hint: t.has(`types.${option}.hint`) ? t(`types.${option}.hint`) : undefined,
                    // Blocked, not just warned: this would produce an empty
                    // archive that reports success.
                    disabledReason:
                      filesOnly && option !== "filesystem"
                        ? t("noDatabase.typeHasNone")
                        : option === "database" && noDatabase
                          ? t("noDatabase.blocked")
                          : undefined,
                  }))}
                />
              </FormControl>
              {/* Still allowed (a site can gain a database later), but today it
                  copies files only, so say so. */}
              {filesOnly ? null : noDatabase && type === "full" ? (
                <Caution className="mt-2">{t("noDatabase.filesOnly")}</Caution>
              ) : null}
              <FormMessage />
            </FormItem>
          )}
        />
        {dropping ? <Caution>{t(`warnings.${dropping}`)}</Caution> : null}
      </Group>

      <Group icon={CalendarClock} title={t("groups.schedule")}>
        {/* One switch instead of a "manual" frequency: the backend treats
            `enabled: false` and `frequency: manual` identically. */}
        <FormField
          control={form.control}
          name="enabled"
          render={({ field }) => (
            <FormItem className="flex items-start justify-between gap-4 rounded-lg border p-3">
              <div className="space-y-1">
                <FormLabel hint={t("automaticHint")}>{t("automatic")}</FormLabel>
                <FormDescription>
                  {automatic ? t("automaticOn") : t("automaticOff")}
                </FormDescription>
                {/* `enabled` is always sent, so a 422 on it needs a place to render. */}
                <FormMessage />
              </div>
              <FormControl>
                <Switch
                  checked={field.value}
                  onCheckedChange={(next) => {
                    field.onChange(next);
                    form.setValue("frequency", next ? options.default_frequency : "manual", {
                      shouldDirty: true,
                    });
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
                      options={scheduledFrequencies(options).map(({ value, label }) => ({
                        value,
                        label,
                      }))}
                      value={field.value}
                      onChange={field.onChange}
                      disabled={disabled}
                      placeholder={t("frequencyPlaceholder")}
                    />
                  </FormControl>
                  {frequencyOption(options, field.value)?.hint ? (
                    <FormDescription>{frequencyOption(options, field.value).hint}</FormDescription>
                  ) : null}
                  <FormMessage />
                </FormItem>
              )}
            />

            {/* Native time input: it returns exactly the "HH:MM" the API validates. */}
            {usage ? (
            <FormField
              control={form.control}
              name="schedule_time"
              render={({ field }) => (
                <FormItem>
                  {/* Hourly reads only the minute; an hour field would be silently ignored. */}
                  {usage === "minute" ? (
                    <>
                      <FormLabel required hint={t("scheduleMinuteHint")}>{t("scheduleMinute")}</FormLabel>
                      <FormControl>
                        <Input
                          type="number"
                          inputMode="numeric"
                          min={0}
                          max={59}
                          disabled={disabled}
                          className="w-full tabular-nums"
                          name={field.name}
                          ref={field.ref}
                          onBlur={field.onBlur}
                          value={Number(minuteOf(field.value))}
                          onChange={(event) => field.onChange(withMinute(field.value, event.target.value))}
                        />
                      </FormControl>
                    </>
                  ) : (
                    <>
                      <FormLabel required hint={t("scheduleTimeHint")}>{t("scheduleTime")}</FormLabel>
                      <FormControl>
                        <Input
                          {...field}
                          type="time"
                          step={60}
                          disabled={disabled}
                          className="w-full tabular-nums"
                        />
                      </FormControl>
                    </>
                  )}
                  {/* The app timezone, NOT server time: the scheduler resolves
                      the slot against it, unlike Linux cron. */}
                  {scheduleTimezone ? (
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                      <Clock className="size-3.5 shrink-0" />
                      {t("scheduleTimeZone", { timezone: scheduleTimezone })}
                    </p>
                  ) : null}
                  <FormMessage />
                </FormItem>
              )}
            />
            ) : null}

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
                      min={options.retention.min}
                      max={options.retention.max}
                      disabled={disabled}
                      {...field}
                    />
                  </FormControl>
                  {/* Counts backups, not days; the hint says so on hourly schedules.
                      Hidden while the value is invalid. */}
                  {form.formState.errors.retention_count ? null : (
                    <FormDescription>
                      {span?.unit === "hours"
                        ? t("retentionHintHours", { hours: span.amount })
                        : span
                          ? t("retentionHint", { days: span.amount })
                          : t("retentionHintCount", { count: Number(retention) || 0 })}
                    </FormDescription>
                  )}
                  <FormMessage>
                    {form.formState.errors.retention_count?.message === "retentionRange"
                      ? tv("retentionRange", options.retention)
                      : undefined}
                  </FormMessage>
                </FormItem>
              )}
            />
          </div>
        ) : null}
        {automatic && pruning > 0 ? (
          <Caution>{t("warnings.retentionDown", { count: pruning })}</Caution>
        ) : null}
      </Group>
      </>
      )}

      <Group icon={HardDrive} title={t("groups.storage")}>
        {!hasDestinations ? (
          <div className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <div className="space-y-2">
              <p>{t("noDestinations")}</p>
              {/* "Add destination" opens a new tab, so a refresh action is needed
                  here to avoid reloading and losing the form. */}
              <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline">
                  <Link href="/integrations/storage" target="_blank" rel="noreferrer" prefetch={false}>
                    <HardDrive className="size-4" />
                    {t("addDestination")}
                    <ExternalLink className="size-3.5" />
                  </Link>
                </Button>
                {onRefreshDestinations ? (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={refreshingDestinations}
                    onClick={onRefreshDestinations}
                  >
                    {refreshingDestinations ? (
                      <Loader2 className="size-4 animate-spin" />
                    ) : (
                      <RotateCw className="size-4" />
                    )}
                    {t("checkAgain")}
                  </Button>
                ) : null}
              </div>
            </div>
          </div>
        ) : (
          <FormField
            control={form.control}
            name="storage_destination_id"
            render={({ field }) => (
              <FormItem>
                {/* A single destination is shown as a row, not a one-option dropdown. */}
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
                {/* The chosen destination fails its own connection test. A
                    warning, not a blocker, so credentials can be fixed mid-setup. */}
                {failingDestination ? (
                  <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-warning" />
                    <span>
                      {notConnected
                        ? t("destinationNotConnected", { name: failingDestination.name })
                        : t("destinationFailing", { name: failingDestination.name })}{" "}
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
          {/* One shared hint for both fields. */}
          <p className="text-xs text-muted-foreground">{t("excludeHint")}</p>

          {/* `items-start` keeps one textarea from stretching the other. One
              column when only one box applies. */}
          <div
            className={cn(
              "grid items-start gap-4",
              wantsFiles && wantsDatabase ? "sm:grid-cols-2" : null,
            )}
          >
            {wantsFiles ? (
            <FormField
              control={form.control}
              name="file_excludes"
              render={({ field, fieldState }) => (
                <FormItem>
                  <FormLabel hint={t("fileExcludesHint")}>{t("fileExcludes")}</FormLabel>
                  <FormControl>
                    <Textarea
                      // `field-sizing-fixed`: the base Textarea grows with its
                      // content and ignores `rows`; fixed height keeps the pair even.
                      className="h-24 resize-none overflow-y-auto font-mono text-xs field-sizing-fixed"
                      disabled={disabled}
                      placeholder={t("fileExcludesPlaceholder")}
                      value={(field.value ?? []).join("\n")}
                      onChange={(event) => field.onChange(toLines(event.target.value))}
                    />
                  </FormControl>
                  <FormMessage>{lineError(fieldState.error)}</FormMessage>
                </FormItem>
              )}
            />
            ) : null}

            {wantsDatabase ? (
            <FormField
              control={form.control}
              name="database_excludes"
              render={({ field, fieldState }) => (
                <FormItem>
                  <FormLabel hint={t("databaseExcludesHint")}>{t("databaseExcludes")}</FormLabel>
                  <FormControl>
                    <Textarea
                      // `field-sizing-fixed`: the base Textarea grows with its
                      // content and ignores `rows`; fixed height keeps the pair even.
                      className="h-24 resize-none overflow-y-auto font-mono text-xs field-sizing-fixed"
                      disabled={disabled}
                      placeholder={t("databaseExcludesPlaceholder")}
                      value={(field.value ?? []).join("\n")}
                      onChange={(event) => field.onChange(toLines(event.target.value))}
                    />
                  </FormControl>
                  <FormMessage>{lineError(fieldState.error)}</FormMessage>
                </FormItem>
              )}
            />
            ) : null}
          </div>

          {/* The chips add file patterns, so they follow the file box. */}
          {wantsFiles ? (
          <FormField
            control={form.control}
            name="file_excludes"
            render={({ field }) => {
              // Added patterns leave the row; the row disappears when none are left.
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
          ) : null}
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
