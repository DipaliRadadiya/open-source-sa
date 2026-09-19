import { useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
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

/**
 * Editing where a destination points — deliberately without the credentials,
 * and deliberately without the provider.
 *
 * The API reads the *presence* of a credential as "rotate this", so a form
 * that posted everything it knew about would overwrite the stored secrets with
 * whatever happened to be in its state. Rotation is its own dialog; this one
 * cannot touch them by construction.
 *
 * The provider is immutable server-side — the config blob's shape is defined
 * by it, so changing it would reinterpret a bucket and a secret key as a
 * hostname and a password. There is no picker here because a control whose
 * only possible outcome is a 422 is not a control; the copy says to delete and
 * recreate instead.
 */
export function EditDestinationDialog({ destination, open, onOpenChange, oauthRedirectUri }) {
  const t = useTranslations("storage.edit");
  const router = useRouter();

  const provider = destination?.provider ?? "s3";
  const preset = presetForProvider(provider);

  const schema = useMemo(() => editStorageDestinationSchema(destination), [destination]);

  // The non-secret config as the API reports it. Secrets are absent from the
  // response entirely, so there is nothing to accidentally round-trip.
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
        // Sent as an empty string rather than omitted: the backend treats an
        // absent key as "keep what is stored", so clearing a field has to be
        // explicit or it silently does nothing.
        prefix: formValues.prefix?.trim() ?? "",
        config: submittableConfig(provider, formValues.config),
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
        <DestinationFormFields
          form={form}
          preset={preset}
          disabled={submitting}
          hideSecrets
          existing
        />
        {/* Approval lives here rather than in the create dialog because the
            connection needs a destination that already exists — the sealed
            `state` is issued against its id, and the refresh token is written
            onto its row. So: save the client id and secret first, then connect.

            No completion handler: Connect navigates the whole browser to
            Google, so this dialog is gone by the time anything is approved.
            The operator comes back to the callback page, not to here. */}
        {provider === "google_drive_oauth" ? (
          <>
            {/* Collapsed here: this reader already has a client and wants the
                Connect button. It stays available because reconnecting is also
                when somebody discovers their client was registered with the
                wrong redirect URL, and that is the value they need. */}
            <GoogleDriveSetup redirectUri={oauthRedirectUri} />
            <GoogleDriveConnect destination={destination} />
          </>
        ) : null}
        <p className="text-xs text-muted-foreground">{t("credentialsUntouched")}</p>
        {/* Why there is no provider control, rather than leaving its absence
            to be discovered. */}
        <p className="text-xs text-muted-foreground">
          {t("providerLocked", { provider: destination?.provider_title ?? provider })}
        </p>
      </FormModal>
    </Form>
  );
}

/**
 * Only the non-secret fields, always sent — including the empty ones.
 *
 * A credential key must never appear here: its presence is what the API reads
 * as "rotate", so including an empty `password` would clear a working one.
 */
function submittableConfig(provider, config = {}) {
  const entries = fieldsFor(provider)
    .filter((f) => f.kind !== "secret" && f.kind !== "textarea")
    .map((f) => {
      const value = config[f.name];
      return [f.name, typeof value === "boolean" ? value : (value ?? "")];
    });

  return Object.fromEntries(entries);
}
