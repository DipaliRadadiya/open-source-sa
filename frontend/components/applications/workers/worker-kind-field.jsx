"use client";

import { useTranslations } from "next-intl";
import { workerKinds, conflictingKind } from "@/lib/applications/worker-kind";
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

/**
 * The worker's kind as an explicit control (templates also set it). Rendered
 * above the command, which it frames.
 */
export function WorkerKindField({ form, presets = [], workers = [], disabled = false }) {
  const t = useTranslations("applications.workers");

  return (
    <FormField
      control={form.control}
      name="kind"
      render={({ field }) => (
        <FormItem>
          <FormLabel required hint={t("form.kindHint")}>{t("form.kind")}</FormLabel>
          <Select value={field.value} onValueChange={field.onChange} disabled={disabled}>
            <FormControl>
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              {workerKinds(presets, field.value).map((kind) => {
                // Same conflict rule as the template menu, but never for the
                // current value (Server Sync can adopt a pair the API would refuse).
                const conflict = kind === field.value ? null : conflictingKind(kind, workers);
                const disabledReason = conflict ? t(`form.conflict.${conflict}`) : null;

                // Reason shown in the row: a disabled SelectItem has
                // `pointer-events-none`, so a tooltip on it never opens.
                return (
                  <SelectItem key={kind} value={kind} disabled={Boolean(disabledReason)}>
                    <span className="flex flex-col items-start gap-0.5">
                      <span>{t(`form.kinds.${kind}`)}</span>
                      {disabledReason ? (
                        <span className="text-xs text-muted-foreground">{disabledReason}</span>
                      ) : null}
                    </span>
                  </SelectItem>
                );
              })}
            </SelectContent>
          </Select>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}
