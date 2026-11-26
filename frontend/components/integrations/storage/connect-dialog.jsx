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
import { markTakenName } from "@/lib/storage/name-taken";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { DestinationFormFields } from "@/components/integrations/storage/destination-form-fields";
import { GoogleDriveSetup } from "@/components/integrations/storage/google-drive-setup";

const DEFAULT_PRESET = "aws";

export function ConnectDestinationDialog({ open, onOpenChange, oauthRedirectUri, localSettings = null, existingNames = [], initialPreset = null }) {
  const t = useTranslations("storage.connect");
  const { refreshAndWait } = useRefresh();

  // The preset picks the schema, and a resolver can't be rebuilt from a value it's validating.
  const startPreset = initialPreset ?? DEFAULT_PRESET;
  const [preset, setPreset] = useState(startPreset);
  const provider = providerForPreset(preset);

  const resolver = useMemo(() => zodResolver(createStorageDestinationSchema(preset, localSettings)), [preset, localSettings]);

  const form = useForm({
    resolver,
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: {
      name: "",
      prefix: "",
      config: defaultConfig(providerForPreset(startPreset), localSettings),
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

      if (data?.existing) {
        toast.info(data.message ?? t("alreadyAdded", { name: created?.name ?? "" }));
        onOpenChange?.(false);
        reset(startPreset);
        return;
      }

      toast.success(t("added"), data?.folder?.message ? { description: data.folder.message } : undefined);
      onOpenChange?.(false);
      reset(startPreset);

      if (created && provider === "google_drive_oauth") {
        toast.info(t("addedNeedsConnect"), { duration: 8000 });
        return;
      }

      if (created?.id) {
        const verdict = await probeDestination(created.id, t("testFailed"), t("testNotRun"));
        if (verdict.ok) toast.success(t("testPassed"));
        else toast.error(verdict.message, { duration: 10000 });
        if (!verdict.notRun) await refreshAndWait();
      }
    } catch (error) {
      handleValidationError(error, form, { fallback: t("failed") });
      markTakenName(error, form, values.name, existingNames);
    }
  }

  const submitting = form.formState.isSubmitting;
  const keyDocs = provider === "google_drive_oauth" ? null : keyDocsUrl(preset);

  function reset(nextPreset) {
    setPreset(nextPreset);
    form.reset({
      name: "",
      prefix: "",
      config: defaultConfig(providerForPreset(nextPreset), localSettings),
    });
  }

  function handlePresetChange(next) {
    const nextProvider = providerForPreset(next);
    setPreset(next);

    if (nextProvider !== provider) {
      form.reset({
        name: form.getValues("name"),
        prefix: form.getValues("prefix"),
        config: defaultConfig(nextProvider, localSettings),
      });
      return;
    }

    if (form.formState.isSubmitted) form.trigger(["config.endpoint", "config.region"]);
  }

  function handleOpenChange(next) {
    if (!next) reset(startPreset);
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
          localSettings={localSettings}
          onPresetChange={handlePresetChange}
          disabled={submitting}
        />

        {provider === "google_drive_oauth" ? (
          <GoogleDriveSetup redirectUri={oauthRedirectUri} defaultOpen />
        ) : null}

        {provider === "google_drive_oauth" || provider === "local" ? null : (
          <div className="rounded-lg border bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground">
            {provider === "s3" ? t("permissionsNote") : t("permissionsNoteRemote")}
          </div>
        )}

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

        {provider === "local" ? null : <p className="text-xs text-muted-foreground">{t("credentialsNote")}</p>}
      </FormModal>
    </Form>
  );
}

// Drop empty keys (password: "" would be stored and used) but keep booleans (dropping false re-enables TLS).
function cleanConfig(config = {}) {
  return Object.fromEntries(
    Object.entries(config).filter(([, value]) =>
      typeof value === "boolean" ? true : String(value ?? "").trim() !== "",
    ),
  );
}
