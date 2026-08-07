"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
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

function joinPath(base, name) {
  return base ? `${base}/${name}` : name;
}

export function NewFolderDialog({ appId, path, open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  const form = useForm({
    resolver: zodResolver(newFolderSchema),
    defaultValues: { name: "" },
  });

  async function onSubmit(values) {
    try {
      await createDirectory(appId, joinPath(path, values.name.trim()));
      toast.success(t("newFolder.created", { name: values.name.trim() }));
      onSuccess?.(joinPath(path, values.name.trim()));
      onOpenChange?.(false);
      form.reset({ name: "" });
      router.refresh();
    } catch (error) {
      // The API validates a "path" field (the folder name joined onto the
      // current directory) — this form only exposes "name", so the error has
      // to be remapped onto it rather than going through the generic handler.
      const pathError = error.response?.data?.errors?.path?.[0];
      if (pathError) {
        form.setError("name", { message: pathError });
      } else {
        toast.error(apiMessage(error, t("newFolder.failed")));
      }
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  function handleOpenChange(next) {
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
