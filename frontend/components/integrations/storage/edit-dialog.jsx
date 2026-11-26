import { useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil } from "lucide-react";
import { editStorageDestinationSchema } from "@/lib/schemas/storage";
import { defaultConfig, fieldsFor, hasCredentials, presetForProvider } from "@/lib/storage/providers";
import { updateDestination } from "@/lib/api/storage";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { markTakenName } from "@/lib/storage/name-taken";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { Note } from "@/components/ui/note";
import { DestinationFormFields } from "@/components/integrations/storage/destination-form-fields";
import { GoogleDriveConnect } from "@/components/integrations/storage/google-drive-connect";
import { GoogleDriveSetup } from "@/components/integrations/storage/google-drive-setup";

export function EditDestinationDialog({ destination, open, onOpenChange, oauthRedirectUri, localSettings = null, existingNames = [] }) {
  const t = useTranslations("storage.edit");
  const { refreshAndWait } = useRefresh();

  const provider = destination?.provider ?? "s3";
  const preset = presetForProvider(provider);

  const schema = useMemo(() => editStorageDestinationSchema(destination, localSettings), [destination, localSettings]);

  const values = useMemo(() => {
    const config = {};
    const defaults = defaultConfig(provider, localSettings);

    for (const field of fieldsFor(provider)) {
      const current = destination?.config?.[field.name];
      config[field.name] = current ?? defaults[field.name];
    }

    return {
      name: destination?.name ?? "",
      prefix: destination?.prefix ?? "",
      config,
    };
  }, [destination, provider, localSettings]);

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    values,
  });

  async function onSubmit(formValues) {
    try {
      const { data } = await updateDestination(destination.id, {
        name: formValues.name.trim(),
        // An empty string, because the backend treats a missing key as keep.
        ...(provider === "local" ? {} : { prefix: formValues.prefix?.trim() ?? "" }),
        config: submittableConfig(provider, formValues.config),
      });
      await refreshAndWait();
      const moved = data?.folder?.message && formValues.config?.path !== destination?.config?.path;
      toast.success(t("saved"), moved ? { description: data.folder.message } : undefined);
      onOpenChange?.(false);
    } catch (error) {
      handleValidationError(error, form, { fallback: t("failed") });
      markTakenName(error, form, formValues.name, existingNames);
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
        <DestinationFormFields
          form={form}
          preset={preset}
          localSettings={localSettings}
          disabled={submitting}
          hideSecrets
          existing
        />
        {provider === "google_drive_oauth" ? (
          <>
            <GoogleDriveSetup redirectUri={oauthRedirectUri} />
            <GoogleDriveConnect destination={destination} />
          </>
        ) : null}
        <Note>
          <ul className="list-disc space-y-1 ps-4">
            {hasCredentials(provider) ? <li>{t("credentialsUntouched")}</li> : null}
            <li>{t("providerLocked", { provider: destination?.provider_title ?? provider })}</li>
          </ul>
        </Note>
      </FormModal>
    </Form>
  );
}

// Never include credential keys: an empty password would clear a working one.
function submittableConfig(provider, config = {}) {
  const entries = fieldsFor(provider)
    .filter((f) => f.kind !== "secret" && f.kind !== "textarea")
    .map((f) => {
      const value = config[f.name];
      return [f.name, typeof value === "boolean" ? value : (value ?? "")];
    });

  return Object.fromEntries(entries);
}
