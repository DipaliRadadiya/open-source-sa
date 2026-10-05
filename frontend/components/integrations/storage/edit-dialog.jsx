import { useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil } from "lucide-react";
import { editStorageDestinationSchema } from "@/lib/schemas/storage";
import { fieldsFor, presetForProvider } from "@/lib/storage/providers";
import { updateDestination } from "@/lib/api/storage";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { DestinationFormFields } from "@/components/integrations/storage/destination-form-fields";
import { GoogleDriveConnect } from "@/components/integrations/storage/google-drive-connect";
import { GoogleDriveSetup } from "@/components/integrations/storage/google-drive-setup";

// No credentials (the API reads their presence as "rotate") and no provider (immutable).
export function EditDestinationDialog({ destination, open, onOpenChange, oauthRedirectUri }) {
  const t = useTranslations("storage.edit");
  const { refreshAndWait } = useRefresh();

  const provider = destination?.provider ?? "s3";
  const preset = presetForProvider(provider);

  const schema = useMemo(() => editStorageDestinationSchema(destination), [destination]);

  // The non-secret config from the API; secrets are never in the response.
  const values = useMemo(() => {
    const config = {};

    for (const field of fieldsFor(provider)) {
      const current = destination?.config?.[field.name];
      config[field.name] = current ?? (field.default !== undefined ? field.default : "");
    }

    return {
      name: destination?.name ?? "",
      prefix: destination?.prefix ?? "",
      config,
    };
  }, [destination, provider]);

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    values,
  });

  async function onSubmit(formValues) {
    try {
      await updateDestination(destination.id, {
        name: formValues.name.trim(),
        // Empty string, not omitted: the backend treats an absent key as "keep
        // what is stored", so clearing must be explicit.
        prefix: formValues.prefix?.trim() ?? "",
        config: submittableConfig(provider, formValues.config),
      });
      await refreshAndWait();
      toast.success(t("saved"));
      onOpenChange?.(false);
    } catch (error) {
      handleValidationError(error, form, { fallback: t("failed") });
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
          disabled={submitting}
          hideSecrets
          existing
        />
        {/* Here, not in create: the OAuth `state` is issued against an existing id. */}
        {provider === "google_drive_oauth" ? (
          <>
            {/* Collapsed; still available because reconnecting is when a wrong
                redirect URL is usually discovered. */}
            <GoogleDriveSetup redirectUri={oauthRedirectUri} />
            <GoogleDriveConnect destination={destination} />
          </>
        ) : null}
        <p className="text-xs text-muted-foreground">{t("credentialsUntouched")}</p>
        {/* Explains why there is no provider control. */}
        <p className="text-xs text-muted-foreground">
          {t("providerLocked", { provider: destination?.provider_title ?? provider })}
        </p>
      </FormModal>
    </Form>
  );
}

// Non-secret fields only, empty ones included. A credential key must never appear:
// an empty `password` would clear a working one.
function submittableConfig(provider, config = {}) {
  const entries = fieldsFor(provider)
    .filter((f) => f.kind !== "secret" && f.kind !== "textarea")
    .map((f) => {
      const value = config[f.name];
      return [f.name, typeof value === "boolean" ? value : (value ?? "")];
    });

  return Object.fromEntries(entries);
}
