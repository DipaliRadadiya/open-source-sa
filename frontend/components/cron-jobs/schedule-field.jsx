import { useRef, useState } from "react";
import { useWatch } from "react-hook-form";
import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";
import { Input } from "@/components/ui/input";
import { SchedulePreview } from "@/components/cron-jobs/schedule-preview";
import {
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const CUSTOM = "custom";

// Buckets a preset by the coarsest unit its expression varies on, so a long
// flat list reads as three short ones. Anything unrecognised falls into
// "days" rather than being dropped — a preset must never vanish from the menu.
function groupOf(preset) {
  if (preset.key === CUSTOM || !preset.expression) return "custom";
  const [minute, hour] = preset.expression.split(/\s+/);
  if (hour === "*") return minute?.startsWith("*/") || minute === "*" ? "minutes" : "hours";
  return "days";
}

const GROUP_ORDER = ["minutes", "hours", "days", "custom"];

/**
 * Schedule picker. Each option carries its cron expression so the dropdown
 * teaches the syntax while you use it. The editable cron field appears only
 * under "Custom", which is also where a command template's expression lands
 * when it doesn't match any preset.
 */
export function ScheduleField({ form, presets, timezone }) {
  const t = useTranslations("cronJobs");
  const tValidation = useTranslations("validation");
  // Only "did the user ask to hand-write it" is state. Which preset is showing
  // is derived from the expression itself, so a command template that rewrites
  // the expression can never leave the dropdown displaying a stale label.
  const [customMode, setCustomMode] = useState(false);
  const expression = useWatch({ control: form.control, name: "expression" });

  const matched = presets.find((p) => p.expression && p.expression === expression);
  // An expression matching no preset IS custom (e.g. jobs adopted from
  // /etc/cron.d); derived, so editing such a job shows its raw schedule.
  const selected = customMode || (expression && !matched) ? CUSTOM : matched?.key;

  /*
   * Picking "Custom" changes nothing by itself, so Save stays disabled; focus
   * moves to the expression field instead of force-dirtying the form, and the
   * first keystroke enables Save.
   */
  const rawFieldRef = useRef(null);
  const focusRawOnClose = useRef(false);

  function onPreset(key) {
    setCustomMode(key === CUSTOM);
    focusRawOnClose.current = key === CUSTOM;
    const preset = presets.find((p) => p.key === key);
    if (preset?.expression) {
      // `shouldDirty`: the edit dialog gates Save on `isDirty`, which setValue
      // leaves alone unless asked.
      form.setValue("expression", preset.expression, {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  const hasPresets = presets.length > 0;
  const showRawField = !hasPresets || selected === CUSTOM;
  const selectedPreset = presets.find((p) => p.key === selected);

  // Zod emits translation keys (e.g. "cronExpression"); mirror FormMessage's
  // lookup, falling back to the raw string for already-localized API errors.
  const errorKey = form.formState.errors.expression?.message;
  const expressionError = errorKey
    ? tValidation.has(errorKey)
      ? tValidation(errorKey)
      : errorKey
    : null;

  const selectInvalid = !showRawField && Boolean(expressionError);

  // Grouping only earns its keep once the list is long enough to scan.
  const grouped = presets.length > 6;
  const buckets = GROUP_ORDER.map((group) => ({
    group,
    items: presets.filter((p) => groupOf(p) === group),
  })).filter((b) => b.items.length > 0);

  const renderItem = (preset) => (
    <SelectItem key={preset.key} value={preset.key}>
      {/* Radix wraps item children in a content-sized span, so w-full can't
          stretch here — a fixed label column is what actually aligns the
          expressions into a readable second column. */}
      <span className="flex items-center gap-3">
        <span className="min-w-36">{preset.label}</span>
        {preset.expression ? (
          <span className="font-mono text-xs text-muted-foreground">
            {preset.expression}
          </span>
        ) : null}
      </span>
    </SelectItem>
  );

  return (
    <div className="space-y-4">
      {hasPresets ? (
        <FormItem>
          {/* Not a FormField (the value is derived, not bound), so the error
              state is passed by hand. */}
          <FormLabel required hint={t("form.scheduleHint")} data-error={selectInvalid}>{t("form.schedule")}</FormLabel>
          <Select value={selected} onValueChange={onPreset}>
            <FormControl aria-invalid={selectInvalid}>
              <SelectTrigger className="w-full">
                {/* Explicit trigger content: Radix would otherwise reuse the
                    option markup, dragging the list's fixed label column into
                    the trigger and indenting the value by ~15px. */}
                <SelectValue placeholder={t("form.schedulePlaceholder")}>
                  {selectedPreset ? (
                    <span className="flex items-center gap-2">
                      <span>{selectedPreset.label}</span>
                      {selectedPreset.expression ? (
                        <span className="font-mono text-xs text-muted-foreground">
                          {selectedPreset.expression}
                        </span>
                      ) : null}
                    </span>
                  ) : null}
                </SelectValue>
              </SelectTrigger>
            </FormControl>
            {/* popper, not the default item-aligned: with 10 presets the menu
                would otherwise open on top of the fields above it. */}
            <SelectContent
              /*
               * Radix returns focus to the trigger on close, after any selection
               * handler, so the focus redirect must happen here.
               */
              onCloseAutoFocus={(event) => {
                if (!focusRawOnClose.current) return;
                focusRawOnClose.current = false;
                event.preventDefault();
                rawFieldRef.current?.focus();
              }} position="popper" className="max-h-72">
              {grouped
                ? buckets.map((bucket) => (
                    <SelectGroup key={bucket.group}>
                      <SelectLabel>{t(`form.scheduleGroups.${bucket.group}`)}</SelectLabel>
                      {bucket.items.map(renderItem)}
                    </SelectGroup>
                  ))
                : presets.map(renderItem)}
            </SelectContent>
          </Select>
          {!showRawField && expressionError ? (
            <p className="text-sm text-destructive">{expressionError}</p>
          ) : null}
        </FormItem>
      ) : null}

      {showRawField ? (
        <FormField
          control={form.control}
          name="expression"
          render={({ field }) => (
            <FormItem>
              <FormLabel required hint={t("form.expressionHint")}>{t("form.expression")}</FormLabel>
              <FormControl>
                <Input
                  className="font-mono"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="* * * * *"
                  {...field}
                  ref={(node) => {
                    field.ref(node);
                    rawFieldRef.current = node;
                  }}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      ) : null}

      {/* "0 0 2 * *" reads as 2 AM daily to most people; it is midnight on the 2nd. */}
      {showRawField ? <SchedulePreview expression={expression} timezone={timezone} /> : null}

      {/* Timezone only: the trigger already shows label and expression, and the
          page subtitle is hidden behind this dialog. */}
      {timezone ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Clock className="size-3.5 shrink-0" />
          {t("form.serverTimeNote", { timezone })}
        </p>
      ) : null}
    </div>
  );
}
