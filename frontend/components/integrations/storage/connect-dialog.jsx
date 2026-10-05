import { useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ExternalLink, HardDrive, Loader2 } from "lucide-react";
import { createStorageDestinationSchema } from "@/lib/schemas/storage";
import { defaultConfig, keyDocsUrl, providerForPreset } from "@/lib/storage/providers";
import { createDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { DestinationFormFields } from "@/components/integrations/storage/destination-form-fields";
import { GoogleDriveSetup } from "@/components/integrations/storage/google-drive-setup";

const DEFAULT_PRESET = "aws";

// The test endpoint needs a saved id, so this saves first, then tests.
export function ConnectDestinationDialog({ open, onOpenChange, oauthRedirectUri }) {
  const t = useTranslations("storage.connect");
  const { refreshAndWait } = useRefresh();

  // The preset lives in component state because it selects the schema; a
  // resolver cannot be rebuilt from a value it is validating.
  const [preset, setPreset] = useState(DEFAULT_PRESET);
  const provider = providerForPreset(preset);

  const resolver = useMemo(() => zodResolver(createStorageDestinationSchema(preset)), [preset]);

  const form = useForm({
    resolver,
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: {
      name: "",
      prefix: "",
      config: defaultConfig(providerForPreset(DEFAULT_PRESET)),
    },
  });

  async function onSubmit(values) {
    try {
      const { data } = await createDestination({
        name: values.name.trim(),
        provider,
        prefix: values.prefix?.trim() || undefined,
        config: cleanConfig(values.config),
      });

      const created = data?.storage_destination;
      await refreshAndWait();
      toast.success(t("added"));
      onOpenChange?.(false);
      reset(DEFAULT_PRESET);

      // Drive consent is keyed to an existing destination, so a new one cannot pass yet.
      if (created && provider === "google_drive_oauth") {
        toast.info(t("addedNeedsConnect"), { duration: 8000 });
        return;
      }

      // The check runs after the dialog closes so it never blocks the save; its
      // verdict is what the user is told.
      if (created?.id) {
        const verdict = await probeDestination(created.id, t("testFailed"), t("testNotRun"));
        if (verdict.ok) toast.success(t("testPassed"));
        else toast.error(verdict.message, { duration: 10000 });
      }
    } catch (error) {
      handleValidationError(error, form, { fallback: t("failed") });
    }
  }

  const submitting = form.formState.isSubmitting;
  // Not for Drive: its setup guide links each Console page from its own step.
  const keyDocs = provider === "google_drive_oauth" ? null : keyDocsUrl(preset);

  function reset(nextPreset) {
    setPreset(nextPreset);
    form.reset({
      name: "",
      prefix: "",
      config: defaultConfig(providerForPreset(nextPreset)),
    });
  }

  function handlePresetChange(next) {
    const nextProvider = providerForPreset(next);
    setPreset(next);

    // Switching providers swaps the field set, so old values are cleared (they
    // would be submitted). Within the S3 family the values are kept.
    if (nextProvider !== provider) {
      form.reset({
        name: form.getValues("name"),
        prefix: form.getValues("prefix"),
        config: defaultConfig(nextProvider),
      });
      return;
    }

    // Requiredness moves with the preset, so re-validate errors raised under
    // the old one.
    if (form.formState.isSubmitted) form.trigger(["config.endpoint", "config.region"]);
  }

  function handleOpenChange(next) {
    if (!next) reset(DEFAULT_PRESET);
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={HardDrive}
        title={t("title")}
        description={t("subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={submitting}
              onClick={() => handleOpenChange(false)}
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
          onPresetChange={handlePresetChange}
          disabled={submitting}
        />

        {/* The setup guide comes first: the OAuth client must be created in
            Google Cloud Console before these fields can be filled. */}
        {provider === "google_drive_oauth" ? (
          <GoogleDriveSetup redirectUri={oauthRedirectUri} defaultOpen />
        ) : null}

        {/* The probe writes and deletes, and backups prune, so read-only credentials fail.
            Drive's scope is fixed by the panel. */}
        {provider === "google_drive_oauth" ? null : (
          <div className="rounded-lg border bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground">
            {provider === "s3" ? t("permissionsNote") : t("permissionsNoteRemote")}
          </div>
        )}

        {/* Provider-specific docs: each service names its keys differently. */}
        {keyDocs ? (
          <a
            href={keyDocs}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
          >
            {t("keyDocs")}
            <ExternalLink className="size-3" />
          </a>
        ) : null}

        {/* Credentials are stored encrypted and never returned; replacing is
            the only way to change them. */}
        <p className="text-xs text-muted-foreground">{t("credentialsNote")}</p>
      </FormModal>
    </Form>
  );
}

// Drop empty keys: `password: ""` on key-auth SFTP would be stored and used. Keep
// booleans: stripping `false` would re-enable TLS.
function cleanConfig(config = {}) {
  return Object.fromEntries(
    Object.entries(config).filter(([, value]) =>
      typeof value === "boolean" ? true : String(value ?? "").trim() !== "",
    ),
  );
}
