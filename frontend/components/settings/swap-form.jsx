"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { HardDriveDownload } from "lucide-react";
import { swapFormSchema } from "@/lib/schemas/settings";
import { updateSwapSettings } from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { Input } from "@/components/ui/input";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { Form, FormField, FormControl } from "@/components/ui/form";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Row,
  InfoRow,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";

const MB = 1024 * 1024;
const PRESETS = [0, 1024, 2048, 4096];
const CUSTOM = "custom";

export function SwapForm({ swap, memoryTotal, canManage, changedBy }) {
  const t = useTranslations("settings.performance");
  const tv = useTranslations("settings.validation");
  const router = useRouter();
  const [pendingValues, setPendingValues] = useState(null);
  const [saving, setSaving] = useState(false);

  // The API reads bytes and writes megabytes; the conversion happens here, once.
  const currentMb = Math.round((swap?.size ?? 0) / MB);
  const defaults = { size_mb: String(currentMb) };

  // Swap is a safety net, not a second bank of RAM: 1–2 GB covers the spike
  // that would otherwise get a process killed, and more just costs disk. The
  // earlier "closest preset to total RAM" rule recommended 4 GB on a 15 GB box
  // while the hint said "about the same as your memory" — advice that argued
  // with itself. Only offered when we were allowed to read the memory size.
  const recommendedMb = memoryTotal
    ? memoryTotal.bytes / MB < 2048
      ? 1024
      : 2048
    : null;

  const form = useForm({
    resolver: zodResolver(swapFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const sizeMb = useWatch({ control: form.control, name: "size_mb" });
  const matchesPreset = PRESETS.includes(Number(sizeMb));
  // Custom stays open once chosen, so the field doesn't vanish under the cursor
  // the moment a typed value happens to equal a preset.
  const [custom, setCustom] = useState(!matchesPreset);

  async function save(values) {
    setSaving(true);
    try {
      await updateSwapSettings({ size_mb: Number(values.size_mb) });
      toast.success(t("swap.saved"));
      form.reset({ size_mb: String(values.size_mb) });
      setPendingValues(null);
      router.refresh();
    } catch (error) {
      setPendingValues(null);
      handleValidationError(error, form);
    } finally {
      setSaving(false);
    }
  }

  function onSubmit(values) {
    // Turning swap off while it's in use is the one change here that can end
    // with the kernel killing processes.
    if (Number(values.size_mb) === 0 && swap?.enabled) {
      setPendingValues(values);
      return;
    }
    return save(values);
  }

  function pick(next) {
    if (!next) return;
    if (next === CUSTOM) {
      setCustom(true);
      return;
    }
    setCustom(false);
    form.setValue("size_mb", next, { shouldDirty: true });
  }

  const submitting = saving || form.formState.isSubmitting;

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={HardDriveDownload}
          title={t("swap.title")}
          description={t("swap.description")}
          readOnly={!canManage}
          changedBy={changedBy}
          actions={
            <SectionActions
              label={t("swap.save")}
              isDirty={form.formState.isDirty}
              pending={submitting}
              onDiscard={() => {
                form.reset(defaults);
                setCustom(!PRESETS.includes(currentMb));
              }}
              canManage={canManage}
            />
          }
        >
          <InfoRow
            label={t("swap.current")}
            hint={
              memoryTotal?.human
                ? t("swap.memoryTotal", { size: memoryTotal.human })
                : undefined
            }
          >
            <p className="text-sm whitespace-nowrap">
              {swap?.enabled
                ? t("swap.currentValue", {
                    size: swap.size_human,
                    used: swap.used_human,
                  })
                : t("swap.none")}
            </p>
          </InfoRow>

          <FormField
            control={form.control}
            name="size_mb"
            render={({ field }) => (
              <Row
                label={t("swap.size")}
                hint={t("swap.sizeHint")}
                error={validationMessage(
                  tv,
                  form.formState.errors.size_mb?.message,
                )}
                wide
              >
                <ToggleGroup
                  type="single"
                  value={custom ? CUSTOM : String(sizeMb)}
                  onValueChange={pick}
                  variant="outline"
                  disabled={!canManage}
                  className="flex-wrap justify-start gap-2"
                >
                  {PRESETS.map((mb) => (
                    <ToggleGroupItem
                      key={mb}
                      value={String(mb)}
                      className="px-4"
                    >
                      {mb === 0
                        ? t("swap.off")
                        : t("swap.gb", { gb: mb / 1024 })}
                      {mb !== 0 && mb === recommendedMb ? (
                        <span className="text-xs text-muted-foreground">
                          {t("swap.recommended")}
                        </span>
                      ) : null}
                    </ToggleGroupItem>
                  ))}
                  <ToggleGroupItem value={CUSTOM} className="px-4">
                    {t("swap.custom")}
                  </ToggleGroupItem>
                </ToggleGroup>

                {/* The number field only exists once "Custom" is chosen. Showing
                    it beside the presets made two controls for one value, and
                    left people wondering which one counted. */}
                {custom ? (
                  <div className="flex items-center gap-2 pt-1">
                    <FormControl>
                      <Input
                        placeholder="2048"
                        className="w-28 font-mono"
                        inputMode="numeric"
                        autoComplete="off"
                        disabled={!canManage}
                        {...field}
                      />
                    </FormControl>
                    <span className="text-sm text-muted-foreground">
                      {t("swap.megabytes")}
                    </span>
                  </div>
                ) : null}
              </Row>
            )}
          />
        </Section>
      </form>

      <ConfirmDialog
        open={pendingValues !== null}
        onOpenChange={(open) => !open && setPendingValues(null)}
        icon={HardDriveDownload}
        tone="warning"
        title={t("swap.confirmTitle")}
        description={t("swap.confirmDescription", {
          used: swap?.used_human ?? "0 B",
        })}
        cancelLabel={t("swap.confirmCancel")}
        confirmLabel={t("swap.confirmSubmit")}
        pending={submitting}
        onConfirm={() => save(pendingValues)}
      />
    </Form>
  );
}
