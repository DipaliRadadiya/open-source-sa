import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, FolderPlus } from "lucide-react";
import { newFolderSchema } from "@/lib/schemas/file";
import { createDirectory } from "@/lib/api/files";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormModal } from "@/components/ui/form-modal";
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";
import { useRefresh } from "@/hooks/use-refresh";

function joinPath(base, name) {
  return base ? `${base}/${name}` : name;
}

export function NewFolderDialog({ appId, path, existingNames = [], open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  const { pending: refreshing, refreshThen } = useRefresh();
  const form = useForm({
    resolver: zodResolver(newFolderSchema),
    defaultValues: { name: "" },
  });

  async function onSubmit(values) {
    // Checked against the visible list first so the answer is instant; the API
    // refuses it too (path_exists) for a name the list has not loaded.
    if (existingNames.includes(values.name.trim())) {
      form.setError("name", { message: t("newFolder.taken", { name: values.name.trim() }) });
      return;
    }
    try {
      await createDirectory(appId, joinPath(path, values.name.trim()));
      const name = values.name.trim();
      refreshThen(() => {
        toast.success(t("newFolder.created", { name }));
        onSuccess?.(joinPath(path, name));
        onOpenChange?.(false);
        form.reset({ name: "" });
      });
    } catch (error) {
      // The API validates "path" (name joined onto the current directory); this form
      // only has "name", so the error is remapped onto it.
      const pathError = error.response?.data?.errors?.path?.[0];
      if (pathError) {
        form.setError("name", { message: pathError });
      } else if ([404, 409, 422].includes(error.response?.status)) {
        // A refusal with no field key (a taken name) still belongs on the field.
        form.setError("name", { message: apiMessage(error, t("newFolder.failed")) });
      } else {
        toast.error(apiMessage(error, t("newFolder.failed")));
      }
    }
  }

  const isSubmitting = form.formState.isSubmitting || refreshing;

  function handleOpenChange(next) {
    if (isSubmitting) return;
    if (!next) form.reset({ name: "" });
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={FolderPlus}
        title={t("newFolder.title")}
        description={t("newFolder.subtitle")}
        footer={
          <>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={() => handleOpenChange(false)}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("newFolder.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("newFolder.name")}</FormLabel>
              <FormControl>
                <Input autoFocus autoComplete="off" spellCheck={false} placeholder="new-folder" className="font-mono" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
