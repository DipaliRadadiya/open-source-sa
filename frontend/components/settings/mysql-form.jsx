"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { Database, TriangleAlert } from "lucide-react";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { mysqlFormSchema } from "@/lib/schemas/settings";
import { updateMysqlSettings } from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { Input } from "@/components/ui/input";
import { Form, FormField, FormControl } from "@/components/ui/form";
import {
  Row,
  InfoRow,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";

/**
 * `max_connections` for MySQL / MariaDB.
 *
 * The card shows two numbers that are usually the same and occasionally are
 * not: what was asked for, and what the server is actually running. MySQL
 * silently reduces the limit when `open_files_limit` cannot support it, so a
 * form that echoed back the submitted value would report a setting the server
 * never adopted. `capped` comes from the API for exactly that case, and the
 * warning names the file limit as the cause rather than leaving an operator to
 * wonder why their 2000 became 214.
 *
 * The recommendation is advice, not a rule. Each connection reserves its own
 * buffers, so a large value on a small box is an OOM waiting for a traffic
 * spike — but the operator knows their workload and the panel does not, so the
 * number is shown beside the field rather than enforced against it.
 */
export function MysqlForm({ mysql, canManage, changedBy }) {
  const t = useTranslations("settings.mysql");
  const tv = useTranslations("settings.validation");
  const format = useFormatter();
  const router = useRouter();
  const [saving, setSaving] = useState(false);

  const effective = mysql?.max_connections ?? 0;
  const requested = mysql?.configured_max_connections ?? null;

  const form = useForm({
    resolver: zodResolver(mysqlFormSchema),
    mode: "onBlur",
    // The live value, not the configured one: this field is "what is the
    // server doing", and pre-filling it with a number the server refused
    // would invite the operator to save it again unchanged.
    defaultValues: { max_connections: String(effective) },
  });

  async function onSubmit(values) {
    setSaving(true);
    try {
      await updateMysqlSettings({ max_connections: Number(values.max_connections) });
      toast.success(t("saved"));
      // Re-read rather than trust the submission: the server may have capped
      // it, and the card has to show what happened, not what was asked.
      router.refresh();
      form.reset({ max_connections: values.max_connections });
    } catch (error) {
      handleValidationError(error, form, { fallback: t("saveFailed") });
    } finally {
      setSaving(false);
    }
  }

  const number = (value) => format.number(Number(value));
  const reason = canManage ? null : undefined;

  return (
    <DisabledReasonProvider reason={reason}>
      <Form {...form}>
        <form
          method="post"
          onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        >
          <Section
            icon={Database}
            title={t("title")}
            description={t("description")}
            changedBy={changedBy}
            readOnly={!canManage}
            actions={
              <SectionActions
                label={t("title")}
                isDirty={form.formState.isDirty}
                pending={saving}
                onDiscard={() => form.reset()}
                canManage={canManage}
              />
            }
          >
            {/* The server disagreeing with the request is the one thing on this
                card worth interrupting for. */}
            {mysql?.capped ? (
              <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                <div>
                  <p className="font-medium">{t("cappedTitle")}</p>
                  <p className="text-muted-foreground">
                    {t("cappedBody", {
                      requested: number(requested),
                      effective: number(effective),
                      limit: number(mysql?.open_files_limit ?? 0),
                    })}
                  </p>
                </div>
              </div>
            ) : null}

            <FormField
              control={form.control}
              name="max_connections"
              render={({ field, fieldState }) => (
                <Row
                  label={t("maxConnections")}
                  hint={t("maxConnectionsHint")}
                  error={validationMessage(tv, fieldState.error)}
                  required
                >
                  <FormControl>
                    <Input
                      type="number"
                      inputMode="numeric"
                      min={mysql?.floor ?? 10}
                      className="max-w-40 tabular-nums"
                      disabled={!canManage || saving}
                      {...field}
                    />
                  </FormControl>
                </Row>
              )}
            />

            <InfoRow label={t("inUse")}>
              <p className="text-sm tabular-nums">
                {number(mysql?.connections ?? 0)} / {number(effective)}
              </p>
            </InfoRow>

            {mysql?.recommended_max ? (
              <InfoRow
                label={t("recommended", { value: number(mysql.recommended_max) })}
                hint={t("recommendedHint")}
              />
            ) : null}
          </Section>
        </form>
      </Form>
    </DisabledReasonProvider>
  );
}
