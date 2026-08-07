"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil } from "lucide-react";
import { editStorageDestinationSchema } from "@/lib/schemas/storage";
import { updateDestination } from "@/lib/api/storage";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { DestinationFormFields } from "@/components/integrations/storage/destination-form-fields";

/**
 * Editing where a destination points — deliberately without the credentials.
 *
 * The API reads the *presence* of `access_key`/`secret_key` as "rotate these",
 * so a form that posted everything it knew about would overwrite the stored
 * secrets with whatever happened to be in its state. Rotation is its own
 * dialog; this one cannot touch them by construction.
 */
export function EditDestinationDialog({ destination, open, onOpenChange }) {
  const t = useTranslations("storage.edit");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(editStorageDestinationSchema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    values: {
      name: destination?.name ?? "",
      endpoint: destination?.endpoint ?? "",
      region: destination?.region ?? "",
      bucket: destination?.bucket ?? "",
      prefix: destination?.prefix ?? "",
    },
  });

  async function onSubmit(values) {
    try {
      await updateDestination(destination.id, {
        name: values.name.trim(),
        bucket: values.bucket.trim(),
        // Sent as an empty string rather than omitted: the backend treats ""
        // as "clear this and use the provider default", and omitting would
        // instead keep the old value — so clearing a field has to be explicit.
        endpoint: values.endpoint?.trim() ?? "",
        region: values.region?.trim() ?? "",
        prefix: values.prefix?.trim() ?? "",
      });
      toast.success(t("saved"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const submitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Pencil}
        title={t("title")}
        description={t("subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={submitting}
              onClick={() => onOpenChange?.(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting ? t("saving") : t("submit")}
            </Button>
          </>
        }
      >
        <DestinationFormFields form={form} disabled={submitting} />
        <p className="text-xs text-muted-foreground">{t("credentialsUntouched")}</p>
      </FormModal>
    </Form>
  );
}
