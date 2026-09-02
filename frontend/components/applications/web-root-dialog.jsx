import { useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FolderTree, Loader2 } from "lucide-react";
import { updateWebRoot } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormControl,
  FormDescription,
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
  const router = useRouter();
  const [saving, setSaving] = useState(false);

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onBlur",
    defaultValues: { web_root: application.web_root ?? "" },
  });

  async function save(values) {
    setSaving(true);
    try {
      await updateWebRoot(application.id, values.web_root);
      toast.success(t("saved"));
      onOpenChange?.(false);
      router.refresh();
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
              <FormLabel>{t("label")}</FormLabel>
              <FormControl>
                <Input {...field} placeholder="/public" className="font-mono text-sm" disabled={saving} />
              </FormControl>
              <FormDescription>{t("hint")}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
