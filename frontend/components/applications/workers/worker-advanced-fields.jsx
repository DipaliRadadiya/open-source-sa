"use client";

import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

// supervisord's own seven, in its own order — quietest first is how its
// documentation lists them, and a reordered list would not match anything the
// reader can look up.
const LOG_LEVELS = ["critical", "error", "warn", "info", "debug", "trace", "blather"];

/**
 * Everything behind "Advanced" on a worker, for both the create and edit
 * dialogs.
 *
 * Shared rather than written twice: the two dialogs already held identical
 * copies of the first two fields, and adding five more to each by hand is how
 * they start to disagree. One of them would have got a hint the other did not.
 *
 * These arrived when workers moved from systemd units to supervisord programs.
 * The API accepts every one of them and the panel offered none, so a worker
 * could only ever run with supervisord's defaults.
 */
export function WorkerAdvancedFields({ form, runsAs = null, disabled = false }) {
  const t = useTranslations("applications.workers");

  return (
    <>
      <FormField
        control={form.control}
        name="directory"
        render={({ field }) => (
          <FormItem>
            <FormLabel hint={t("form.directoryHint")}>{t("form.directory")}</FormLabel>
            <FormControl>
              <Input
                className="font-mono"
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                placeholder={t("form.directoryPlaceholder")}
                {...field}
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      {/* Shown, never typed. The account a worker runs as is not a choice this
          form offers: a free-text name accepted `root`, which let anyone who can
          manage an application's workers run commands as root. */}
      {runsAs ? (
        <div className="space-y-2">
          <Label htmlFor="worker-runs-as" hint={t("form.userHint")}>{t("form.user")}</Label>
          <Input id="worker-runs-as" className="font-mono" value={runsAs} readOnly />
        </div>
      ) : null}

      <FormField
        control={form.control}
        name="log_file"
        render={({ field }) => (
          <FormItem>
            <FormLabel hint={t("form.logFileHint")}>{t("form.logFile")}</FormLabel>
            <FormControl>
              <Input
                className="font-mono"
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                placeholder={t("form.logFilePlaceholder")}
                {...field}
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      <FormField
        control={form.control}
        name="log_level"
        render={({ field }) => (
          <FormItem>
            <FormLabel hint={t("form.logLevelHint")}>{t("form.logLevel")}</FormLabel>
            <Select
              value={field.value || ""}
              onValueChange={field.onChange}
              disabled={disabled}
            >
              <FormControl>
                <SelectTrigger className="w-full">
                  <SelectValue placeholder={t("form.logLevelDefault")} />
                </SelectTrigger>
              </FormControl>
              <SelectContent>
                {LOG_LEVELS.map((level) => (
                  <SelectItem key={level} value={level}>
                    {t(`form.logLevels.${level}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FormMessage />
          </FormItem>
        )}
      />

      <FormField
        control={form.control}
        name="stop_wait_seconds"
        render={({ field }) => (
          <FormItem>
            <FormLabel required hint={t("form.stopWaitSecondsHint")}>{t("form.stopWaitSeconds")}</FormLabel>
            <FormControl>
              <Input
                placeholder="10"
                type="number"
                inputMode="numeric"
                min={1}
                max={600}
                disabled={disabled}
                {...field}
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      <FormField
        control={form.control}
        name="auto_start"
        render={({ field }) => (
          <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
            <div className="space-y-0.5">
              <FormLabel hint={t("form.autoStartHint")}>{t("form.autoStart")}</FormLabel>
            </div>
            <FormControl>
              <Switch
                checked={Boolean(field.value)}
                onCheckedChange={field.onChange}
                disabled={disabled}
              />
            </FormControl>
          </FormItem>
        )}
      />

      <FormField
        control={form.control}
        name="extra_config"
        render={({ field }) => (
          <FormItem>
            <FormLabel hint={t("form.extraConfigHint")}>{t("form.extraConfig")}</FormLabel>
            <FormControl>
              <Textarea
                rows={3}
                className="font-mono text-xs"
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                placeholder={t("form.extraConfigPlaceholder")}
                {...field}
              />
            </FormControl>
            {/* The API refuses a `[` here — these lines go inside a program
                block, and opening a new section would rewrite somebody else's
                worker. Said here rather than discovered as a 422. */}
            <FormMessage />
          </FormItem>
        )}
      />
    </>
  );
}
