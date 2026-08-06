"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, FilePlus } from "lucide-react";
import { newFileSchema } from "@/lib/schemas/file";
import { uploadFile } from "@/lib/api/files";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormModal } from "@/components/ui/form-modal";
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";

function joinPath(base, name) {
  return base ? `${base}/${name}` : name;
}

// There's no "create an empty text file" endpoint — upload is documented as
// the thing allowed to create a new file, so this is a real empty blob
// through the same call Upload uses, not a shortcut around anything.
export function NewFileDialog({ appId, path, open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  const form = useForm({
    resolver: zodResolver(newFileSchema),
    defaultValues: { name: "" },
  });

  async function onSubmit(values) {
    const targetPath = joinPath(path, values.name.trim());
    try {
      const empty = new Blob([""], { type: "text/plain" });
      await uploadFile(appId, targetPath, new File([empty], values.name.trim()));
      toast.success(t("newFile.created", { name: values.name.trim() }));
      onSuccess?.(targetPath);
      onOpenChange?.(false);
      form.reset({ name: "" });
      router.refresh();
    } catch (error) {
      const pathError = error.response?.data?.errors?.path?.[0];
      if (pathError) {
        form.setError("name", { message: pathError });
      } else {
        toast.error(apiMessage(error, t("newFile.failed")));
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
        icon={FilePlus}
        title={t("newFile.title")}
        description={t("newFile.subtitle")}
        footer={
          <>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={() => handleOpenChange(false)}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("newFile.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("newFile.name")}</FormLabel>
              <FormControl>
                <Input
                  autoFocus
                  autoComplete="off"
                  spellCheck={false}
                  className="font-mono"
                  placeholder="notes.txt"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
