"use client";

import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { getLiveMetrics } from "@/lib/api/server-metrics";
import { formatBytes } from "@/lib/format/bytes";
import { Caution } from "@/components/ui/caution";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
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
  const { refreshAndWait } = useRefresh();
  const [pendingValues, setPendingValues] = useState(null);
  const [saving, setSaving] = useState(false);

  // The API reads bytes and writes megabytes; the conversion happens here, once.
  const currentMb = Math.round((swap?.size ?? 0) / MB);
  const defaults = { size_mb: String(currentMb) };

  // Swap is a safety net, not extra RAM: 1–2 GB covers a spike, more just
  // costs disk. Only offered when the memory size could be read.
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
  const format = useFormatter();

  // Checks the swap file fits (the API does not); silent if unreadable.
  const [diskFree, setDiskFree] = useState(null);
  useEffect(() => {
    const controller = new AbortController();
    getLiveMetrics(controller.signal)
      .then((metrics) => {
        const free = metrics?.disk?.free;
        if (Number.isFinite(free)) setDiskFree(free);
      })
      .catch(() => {});
    return () => controller.abort();
  }, []);

  // Only the growth needs new space: the current file is replaced.
  const growth = Math.max(0, Number(sizeMb) * MB - (swap?.size ?? 0));
  const left = diskFree === null ? null : diskFree - growth;
  // Refused past half the free space, not only when it cannot fit.
  const noRoom = diskFree !== null && growth > 0 && growth > diskFree * 0.5;
  const tight = diskFree !== null && growth > 0 && !noRoom && (left < 10 * 1024 * MB || left < diskFree * 0.6);
  const matchesPreset = PRESETS.includes(Number(sizeMb));
  // Custom stays open once chosen, so the field doesn't vanish under the cursor
  // the moment a typed value happens to equal a preset.
  const [custom, setCustom] = useState(!matchesPreset);

  async function save(values) {
    setSaving(true);
    try {
      await updateSwapSettings({ size_mb: Number(values.size_mb) });
      form.reset({ size_mb: String(values.size_mb) });
      await refreshAndWait();
      toast.success(t("swap.saved"));
      setPendingValues(null);
    } catch (error) {
      setPendingValues(null);
      handleValidationError(error, form, { fallback: t("swap.saveFailed") });
    } finally {
      setSaving(false);
    }
  }

  function onSubmit(values) {
    if (noRoom) {
      form.setError("size_mb", { type: "manual", message: t("swap.noRoom", { free: formatBytes(diskFree, format) }) });
      return;
    }
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
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Form {...form}>
        <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
          <Section
            icon={HardDriveDownload}
            title={t("swap.title")}
            description={t("swap.description")}
            // The size buttons need the wider share; the current usage is one line.
            gridClassName="@3xl/section:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
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
                  ? t("swap.memoryTotal", {
                      size: formatBytes(memoryTotal.bytes, format) ?? memoryTotal.human,
                    })
                  : undefined
              }
            >
              {/* The API's *_human strings are English-formatted; format the bytes for the locale. */}
              <p className="text-sm">
                {swap?.enabled
                  ? t("swap.currentValue", {
                      size: formatBytes(swap.size, format) ?? swap.size_human,
                      used: formatBytes(swap.used, format) ?? swap.used_human,
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
  
                  {/* Only once "Custom" is chosen, so there is one control per value. */}
                  {custom ? (
                    <div className="flex items-center gap-2 pt-1">
                      <FormControl>
                        <Input
                          placeholder="2048"
                          className="w-full font-mono"
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

                  {tight ? (
                    <Caution className="mt-2">
                      {t("swap.tight", {
                        size: formatBytes(Number(sizeMb) * MB, format),
                        left: formatBytes(left, format),
                      })}
                    </Caution>
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
          confirmVariant="destructive"
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
    </DisabledReasonProvider>
  );
}
