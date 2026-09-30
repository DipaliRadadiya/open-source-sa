import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FolderTree, Loader2 } from "lucide-react";
import { updateWebRoot } from "@/lib/api/applications";
import { listFiles } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/**
 * Which directory the web server actually serves.
 *
 * Set at creation and then fixed forever, though the API has always allowed
 * changing it — a Laravel repo cloned into the wrong shape meant recreating
 * the site.
 *
 * The rules mirror `UpdateWebRootRequest` so nobody meets them as a 422: the
 * same character set, and no `..` segment. The warning is not decoration —
 * saving rewrites the vhost and reloads the web server, so a wrong value takes
 * the site down until it is corrected.
 */
const schema = z.object({
  web_root: z
    .string()
    .trim()
    .max(255, "max255")
    .regex(/^[A-Za-z0-9._\-/]*$/, "invalidPath")
    .refine((value) => !/(^|\/)\.\.(\/|$)/.test(value), "noTraversal"),
});

export function WebRootDialog({ application, open, onOpenChange }) {
  const t = useTranslations("applications.webRoot");
  const { refreshAndWait } = useRefresh();
  const [saving, setSaving] = useState(false);

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onBlur",
    defaultValues: { web_root: application.web_root ?? "" },
  });

  const typed = useWatch({ control: form.control, name: "web_root" }) ?? "";
  const relative = String(typed).trim().replace(/^\/+|\/+$/g, "");
  // `path` is the folder web_root is relative to — /etc here meant
  // public_html/etc, which surprised the one person who typed it.
  const base = String(application.path ?? "").replace(/\/+$/, "");
  const resolved = base ? (relative ? `${base}/${relative}` : base) : null;

  async function save(values) {
    setSaving(true);
    try {
      const folder = String(values.web_root ?? "").trim().replace(/^\/+|\/+$/g, "");
      if (folder) {
        /*
         * The API saves a folder that does not exist and the site answers 403
         * at once. The file list says whether it is there; only a clear "no"
         * stops the save — a role without file access gets 403 here, which
         * says nothing about the folder.
         */
        const missing = await listFiles(application.id, folder).then(
          () => false,
          (error) => [404, 422].includes(error.response?.status),
        );
        if (missing) {
          form.setError("web_root", { type: "manual", message: t("missingFolder", { path: `${base}/${folder}` }) });
          return;
        }
      }
      await updateWebRoot(application.id, values.web_root);
      await refreshAndWait();
      toast.success(t("saved"));
      onOpenChange?.(false);
    } catch (error) {
      if (error.response?.data?.errors) handleValidationError(error, form);
      else toast.error(apiMessage(error, t("failed")));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={(next) => !saving && onOpenChange?.(next)}
        asForm
        onSubmit={form.handleSubmit(save)}
        icon={FolderTree}
        title={t("title")}
        description={t("description")}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => onOpenChange?.(false)} disabled={saving}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={saving}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="web_root"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("hint")}>{t("label")}</FormLabel>
              <FormControl>
                <Input {...field} placeholder="/public" className="font-mono text-sm" disabled={saving} />
              </FormControl>
              <FormMessage />
              {resolved ? (
                <p className="text-sm text-muted-foreground">
                  {t.rich("resolved", {
                    path: resolved,
                    code: (chunks) => <code className="font-mono text-foreground [overflow-wrap:anywhere]">{chunks}</code>,
                  })}
                </p>
              ) : null}
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
