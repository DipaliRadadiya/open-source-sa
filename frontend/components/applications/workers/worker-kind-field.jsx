"use client";

import { useTranslations } from "next-intl";
import { WORKER_KINDS, conflictingKind } from "@/lib/applications/worker-kind";
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
 * The worker's kind, as a control rather than a side effect.
 *
 * It was only ever set by picking a template, so a worker created as Custom
 * could not become a Queue worker without being deleted and made again — while
 * the API had accepted the change all along. Worse, the one way to change it
 * was invisible: choosing a template rewrote the command AND the kind, and
 * nothing on screen said the second thing had happened.
 *
 * Above the command, because it frames what the command is meant to be.
 */
export function WorkerKindField({ form, workers = [], disabled = false }) {
  const t = useTranslations("applications.workers");

  return (
    <FormField
      control={form.control}
      name="kind"
      render={({ field }) => (
        <FormItem>
          <FormLabel required>{t("form.kind")}</FormLabel>
          <Select value={field.value} onValueChange={field.onChange} disabled={disabled}>
            <FormControl>
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              {WORKER_KINDS.map((kind) => {
                /*
                 * The same refusal the template menu already shows, on the
                 * other control that sets this field — otherwise the menu
                 * greys Horizon out and the select next to it offers it.
                 *
                 * Never the value already selected, though. Server Sync can
                 * adopt a pair the API would have refused, and disabling the
                 * setting a worker already HAS turns "leave this alone" into
                 * the one thing the form will not let you do.
                 */
                const conflict = kind === field.value ? null : conflictingKind(kind, workers);
                const disabledReason = conflict ? t(`form.conflict.${conflict}`) : null;

                /*
                 * The reason is IN the row, not in a tooltip on it.
                 *
                 * A disabled SelectItem carries `data-disabled:pointer-events-none`,
                 * so a tooltip wrapped around it never fires — hover, focus and
                 * touch all pass straight through. Driving it proved that: the
                 * reason was present, the lint gate was satisfied, and no user
                 * could ever have read it. Rendering it beneath the label costs
                 * a line and needs no pointer at all.
                 */
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
          <p className="text-xs text-muted-foreground">{t("form.kindHint")}</p>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}
