"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { ScrollText, TriangleAlert } from "lucide-react";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { mysqlBinlogFormSchema } from "@/lib/schemas/settings";
import {
  purgeMysqlBinlog,
  updateMysqlBinlogSettings,
} from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Form, FormField, FormControl } from "@/components/ui/form";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Row,
  InfoRow,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";

const MB = 1024 * 1024;
const DAY = 86400;

/**
 * Binary log retention and size.
 *
 * There is no on/off control here, and that is deliberate: `log_bin` is a
 * read-only variable, so switching it means restarting the database. What this
 * card does is keep the logs from filling the disk on every server that
 * already has them — which on MySQL 8 is all of them, since it defaults to on.
 *
 * Purging is separated from saving because it is not a setting. It deletes the
 * window a point-in-time recovery would have replayed, so it gets its own
 * button and its own confirmation rather than riding along with a form save.
 */
export function BinlogForm({ binlog, canManage, changedBy }) {
  const t = useTranslations("settings.binlog");
  const tv = useTranslations("settings.validation");
  const format = useFormatter();
  const router = useRouter();
  const [saving, setSaving] = useState(false);
  const [purging, setPurging] = useState(false);
  const [purgeOpen, setPurgeOpen] = useState(false);
  const [purgeDays, setPurgeDays] = useState(7);

  const unreachable = binlog?.present === true && binlog?.reachable === false;
  const enabled = !unreachable && (binlog?.enabled ?? false);

  const form = useForm({
    resolver: zodResolver(mysqlBinlogFormSchema),
    mode: "onBlur",
    defaultValues: {
      expire_days: String(Math.round((binlog?.expire_seconds ?? 0) / DAY)),
      max_binlog_size_mb: String(
        Math.max(1, Math.round((binlog?.max_binlog_size ?? MB) / MB)),
      ),
    },
  });

  // Keeping forever is the default and the dangerous one. Warned about live
  // rather than on save, because the point is to talk someone out of it.
  const expireDays = useWatch({ control: form.control, name: "expire_days" });
  const keepsForever = Number(expireDays) === 0;

  async function onSubmit(values) {
    setSaving(true);
    try {
      await updateMysqlBinlogSettings({
        expire_seconds: Number(values.expire_days) * DAY,
        max_binlog_size: Number(values.max_binlog_size_mb) * MB,
      });
      toast.success(t("saved"));
      router.refresh();
      form.reset(values);
    } catch (error) {
      handleValidationError(error, form, { fallback: t("saveFailed") });
    } finally {
      setSaving(false);
    }
  }

  async function purge() {
    setPurging(true);
    try {
      await purgeMysqlBinlog(Number(purgeDays));
      toast.success(t("purged"));
      router.refresh();
      setPurgeOpen(false);
    } catch (error) {
      toast.error(apiMessage(error, t("purgeFailed")));
    } finally {
      setPurging(false);
    }
  }

  const reason = canManage ? null : undefined;

  return (
    <DisabledReasonProvider reason={reason}>
      <Form {...form}>
        <form
          method="post"
          onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        >
          <Section
            icon={ScrollText}
            title={t("title")}
            description={t("description")}
            changedBy={changedBy}
            readOnly={!canManage}
            actions={
              enabled && !unreachable ? (
                <SectionActions
                  label={t("title")}
                  isDirty={form.formState.isDirty}
                  pending={saving}
                  onDiscard={() => form.reset()}
                  canManage={canManage}
                />
              ) : null
            }
          >
            <InfoRow label={t("state")}>
              <Badge variant={enabled ? "success" : "secondary"}>
                {enabled ? t("statusOn") : t("statusOff")}
              </Badge>
            </InfoRow>

            {/* Two different "nothing to show here" states, and they need
                different words: the panel cannot ask the server, versus the
                server answered and logging is off. */}
            {unreachable ? (
              <p className="text-sm text-muted-foreground">{t("unreachableBody")}</p>
            ) : !enabled ? (
              <p className="text-sm text-muted-foreground">{t("disabledBody")}</p>
            ) : (
              <>
                {binlog?.format ? (
                  <InfoRow label={t("format")}>
                    <p className="text-sm tabular-nums">{binlog.format}</p>
                  </InfoRow>
                ) : null}

                {/* The number that says whether retention is actually working. */}
                <InfoRow label={t("onDisk")}>
                  <p className="text-sm tabular-nums">
                    {t("onDiskValue", {
                      count: binlog?.log_count ?? 0,
                      size: formatBytes(binlog?.log_bytes ?? 0, format) ?? "0 B",
                    })}
                  </p>
                </InfoRow>

                {binlog?.oldest_log ? (
                  <InfoRow label={t("oldest")}>
                    <p className="font-mono text-sm">{binlog.oldest_log}</p>
                  </InfoRow>
                ) : null}

                {keepsForever ? (
                  <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>{t("retentionForeverWarning")}</p>
                  </div>
                ) : null}

                <FormField
                  control={form.control}
                  name="expire_days"
                  render={({ field, fieldState }) => (
                    <Row
                      label={t("retention")}
                      hint={t("retentionHint")}
                      error={validationMessage(tv, fieldState.error)}
                      required
                    >
                      <FormControl>
                        <Input
                          type="number"
                          inputMode="numeric"
                          min={0}
                          className="max-w-32 tabular-nums"
                          disabled={!canManage || saving}
                          {...field}
                        />
                      </FormControl>
                    </Row>
                  )}
                />

                <FormField
                  control={form.control}
                  name="max_binlog_size_mb"
                  render={({ field, fieldState }) => (
                    <Row
                      label={t("maxSize")}
                      hint={t("maxSizeHint")}
                      error={validationMessage(tv, fieldState.error)}
                      required
                    >
                      <FormControl>
                        <Input
                          type="number"
                          inputMode="numeric"
                          min={1}
                          className="max-w-32 tabular-nums"
                          disabled={!canManage || saving}
                          {...field}
                        />
                      </FormControl>
                    </Row>
                  )}
                />

                <InfoRow label={t("purge")} hint={t("purgeDays")}>
                  <div className="flex items-center gap-2">
                    <Input
                      type="number"
                      inputMode="numeric"
                      min={1}
                      max={365}
                      value={purgeDays}
                      onChange={(event) => setPurgeDays(event.target.value)}
                      className="max-w-24 tabular-nums"
                      disabled={!canManage || purging}
                      aria-label={t("purgeDays")}
                    />
                    <Button
                      type="button"
                      variant="outline"
                      disabled={!canManage || purging || Number(purgeDays) < 1}
                      onClick={() => setPurgeOpen(true)}
                    >
                      {t("purge")}
                    </Button>
                  </div>
                </InfoRow>
              </>
            )}
          </Section>
        </form>
      </Form>

      {/* Destructive and unrecoverable — what it deletes is what a
          point-in-time recovery would have replayed. */}
      <ConfirmDialog
        open={purgeOpen}
        onOpenChange={setPurgeOpen}
        title={t("purgeTitle")}
        description={t("purgeDescription", { days: Number(purgeDays) })}
        confirmLabel={purging ? t("purge") : t("purgeConfirm")}
        destructive
        onConfirm={purge}
      />
    </DisabledReasonProvider>
  );
}
