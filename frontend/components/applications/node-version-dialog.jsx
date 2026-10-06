import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Hexagon, Loader2 } from "lucide-react";
import { useRefresh } from "@/hooks/use-refresh";
import { updateNodeVersion } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { versionsInRange, rangeLabel } from "@/lib/runtime/version-range";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Note } from "@/components/ui/note";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

const schema = z.object({ node_version: z.string().min(1, "requiredField") });

// Only installed versions inside the site type's range: the API refuses the rest.
export function NodeVersionDialog({ application, range, versions = [], versionsFailed = false, open, onOpenChange }) {
  const t = useTranslations("applications.nodeVersion");
  const { refreshAndWait } = useRefresh();
  const [saving, setSaving] = useState(false);

  const current = application.node_version ?? "";
  const choices = versionsInRange(versions, range).filter((item) => item.version !== current);
  const span = rangeLabel(range);

  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: { node_version: "" },
  });

  async function save(values) {
    setSaving(true);
    try {
      const { status } = await updateNodeVersion(application.id, values.node_version);
      // 202: the card follows `node_version_change`; 200: already on it, nothing restarts.
      await refreshAndWait();
      if (status === 202) toast.info(t("switching", { target: values.node_version }));
      else toast.success(t("already", { version: values.node_version }));
      onOpenChange?.(false);
      form.reset({ node_version: "" });
    } catch (error) {
      // A 422 on node_version arrives translated and lands on the field.
      if (error.response?.data?.errors) handleValidationError(error, form, { fallback: t("failed") });
      else toast.error(apiMessage(error, t("failed")));
    } finally {
      setSaving(false);
    }
  }

  const nodePage = (chunks) => (
    <Link href="/node" prefetch={false} className="font-medium underline underline-offset-2">
      {chunks}
    </Link>
  );

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={(next) => !saving && onOpenChange?.(next)}
        asForm
        onSubmit={form.handleSubmit(save)}
        icon={Hexagon}
        title={t("title")}
        description={t("description")}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => onOpenChange?.(false)} disabled={saving}>
              {t("cancel")}
            </Button>
            {/* Not offered when there is nothing to switch to; the note says why. */}
            {choices.length > 0 && !versionsFailed ? (
              <Button type="submit" disabled={saving}>
                {saving ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("submit")}
              </Button>
            ) : null}
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          {t.rich("current", { version: current, code: (chunks) => <code className="font-mono text-foreground">{chunks}</code> })}
          {span ? ` ${t("range", { range: span })}` : null}
        </p>

        {versionsFailed ? (
          <Note>{t("loadFailed")}</Note>
        ) : choices.length === 0 ? (
          <Note>{t.rich(span ? "noneInRange" : "none", { range: span, link: nodePage })}</Note>
        ) : (
          <FormField
            control={form.control}
            name="node_version"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t("label")}</FormLabel>
                <Select value={field.value} onValueChange={field.onChange} disabled={saving}>
                  <FormControl>
                    <SelectTrigger className="w-full font-mono">
                      <SelectValue placeholder={t("placeholder")} />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {choices.map((item) => (
                      <SelectItem key={item.version} value={item.version} className="font-mono">
                        {item.version}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage field={t("label")} />
              </FormItem>
            )}
          />
        )}

        <p className="text-xs text-muted-foreground">{t("restartNote")}</p>
      </FormModal>
    </Form>
  );
}
