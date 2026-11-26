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
import { timezoneLabel } from "@/lib/format/timezone";

const CUSTOM = "custom";

function groupOf(preset) {
  if (preset.key === CUSTOM || !preset.expression) return "custom";
  const [minute, hour] = preset.expression.split(/\s+/);
  if (hour === "*") return minute?.startsWith("*/") || minute === "*" ? "minutes" : "hours";
  return "days";
}

const GROUP_ORDER = ["minutes", "hours", "days", "custom"];

export function ScheduleField({ form, presets, timezone }) {
  const t = useTranslations("cronJobs");
  const tValidation = useTranslations("validation");
  const [customMode, setCustomMode] = useState(false);
  const expression = useWatch({ control: form.control, name: "expression" });

  const matched = presets.find((p) => p.expression && p.expression === expression);
  const selected = customMode || (expression && !matched) ? CUSTOM : matched?.key;

  const rawFieldRef = useRef(null);
  const focusRawOnClose = useRef(false);

  function onPreset(key) {
    setCustomMode(key === CUSTOM);
    focusRawOnClose.current = key === CUSTOM;
    const preset = presets.find((p) => p.key === key);
    if (preset?.expression) {
      form.setValue("expression", preset.expression, {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  const hasPresets = presets.length > 0;
  const showRawField = !hasPresets || selected === CUSTOM;
  const selectedPreset = presets.find((p) => p.key === selected);

  const errorKey = form.formState.errors.expression?.message;
  const expressionError = errorKey
    ? tValidation.has(errorKey)
      ? tValidation(errorKey)
      : errorKey
    : null;

  const selectInvalid = !showRawField && Boolean(expressionError);

  const grouped = presets.length > 6;
  const buckets = GROUP_ORDER.map((group) => ({
    group,
    items: presets.filter((p) => groupOf(p) === group),
  })).filter((b) => b.items.length > 0);

  const renderItem = (preset) => (
    <SelectItem key={preset.key} value={preset.key}>
      {/* Radix wraps children in a content-sized span. */}
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
          <FormLabel required hint={t("form.scheduleHint")} data-error={selectInvalid}>{t("form.schedule")}</FormLabel>
          <Select value={selected} onValueChange={onPreset}>
            <FormControl aria-invalid={selectInvalid}>
              <SelectTrigger className="w-full">
                {/* Explicit content, or Radix reuses the whole option markup. */}
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
            <SelectContent
              // Radix refocuses the trigger on close after selection handlers run.
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

      {showRawField ? <SchedulePreview expression={expression} timezone={timezone} /> : null}

      {timezone ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Clock className="size-3.5 shrink-0" />
          {t("form.serverTimeNote", { timezone: timezoneLabel(timezone) })}
        </p>
      ) : null}
    </div>
  );
}
