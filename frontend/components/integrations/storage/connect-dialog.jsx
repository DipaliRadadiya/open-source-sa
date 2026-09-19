import { useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
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

/**
 * Adding a destination.
 *
 * The API cannot check the credentials at creation — its test endpoint takes a
 * saved destination id — so this saves first and then immediately tests, and
 * reports the result of that test rather than a bare "Added". Without it, a
 * mistyped secret key looks like success here and only surfaces as a failed
 * backup at 3am, which is the worst possible moment to learn it.
 */
export function ConnectDestinationDialog({ open, onOpenChange, oauthRedirectUri }) {
  const t = useTranslations("storage.connect");
  const router = useRouter();

  // The preset lives in component state rather than the form, because it
  // selects the *schema* — a resolver cannot be rebuilt from a value it is
  // itself validating.
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
      toast.success(t("added"));
      onOpenChange?.(false);
      reset(DEFAULT_PRESET);
      router.refresh();

      /*
       * A Drive destination cannot pass this check on the way in.
       *
       * Approving access needs the destination to exist first — that is what
       * the consent redirect is keyed to — so a brand-new one is never
       * connected, and probing it always fails. The reader had just filled in
       * a form correctly and got a red error toast for it, with no way to act
       * on it from where they were standing.
       *
       * So say what actually happened and what comes next. The Connect button
       * is on the row behind this dialog.
       */
      if (created && provider === "google_drive_oauth") {
        toast.info(t("addedNeedsConnect"), { duration: 8000 });
        return;
      }

      // Saved is not the same as working. The check runs after the dialog
      // closes so it never blocks the save, and its verdict is what the user
      // is actually told.
      if (created?.id) {
        const verdict = await probeDestination(created.id, t("testFailed"));
        if (verdict.ok) toast.success(t("testPassed"));
        else toast.error(verdict.message, { duration: 10000 });
      }
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const submitting = form.formState.isSubmitting;
  // Suppressed for Drive: the setup guide links each Console page from the
  // step that needs it, and a lone link to the credentials page underneath
  // five numbered steps is the vaguer of the two.
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

    // Switching between providers swaps the whole field set, so values typed
    // under the old one are not merely irrelevant — they would be submitted.
    // Within the S3 family the fields are the same, so what was typed is kept
    // and only the requiredness moves.
    if (nextProvider !== provider) {
      form.reset({
        name: form.getValues("name"),
        prefix: form.getValues("prefix"),
        config: defaultConfig(nextProvider),
      });
      return;
    }

    // Requiredness moves with the preset, so an error raised under the old one
    // has to be re-judged — otherwise "Endpoint is required" stays on screen
    // after switching to AWS, where it is not.
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

        {/* Above the client ID and secret, because all of it is needed before
            either of them exists: the reader is about to go to Google Cloud
            Console and make an OAuth client, and this is where they find out
            how. Open by default here — somebody adding a destination has done
            none of it yet. */}
        {provider === "google_drive_oauth" ? (
          <GoogleDriveSetup redirectUri={oauthRedirectUri} defaultOpen />
        ) : null}

        {/* Said BEFORE the credentials are created, not after the test fails.
            The probe writes an object, reads it back and deletes it, and
            backups prune old archives when they pass the retention limit — so
            a read-only credential cannot work, and that is the single most
            common reason one of these never starts working.

            Not for Drive: there is no permission to get wrong there. The scope
            is fixed by the panel and the guide above already says what it
            grants, so a second box would be noise beside the steps. */}
        {provider === "google_drive_oauth" ? null : (
          <div className="rounded-lg border bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground">
            {provider === "s3" ? t("permissionsNote") : t("permissionsNoteRemote")}
          </div>
        )}

        {/* Where these come from, for the service actually chosen. Every one
            calls them something else — an API token at Cloudflare, an
            Application Key at Backblaze — so "paste your access key" sends a
            first-time user hunting a console for a phrase that is not there. */}
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

        {/* Said before they are typed: these are stored encrypted and the API
            never sends them back, so the panel genuinely cannot show them
            again — replacing is the only way to change them later. */}
        <p className="text-xs text-muted-foreground">{t("credentialsNote")}</p>
      </FormModal>
    </Form>
  );
}

/**
 * Drop the keys the user left empty.
 *
 * An empty string is not the same as "not set": sending `password: ""` for an
 * SFTP destination authenticating by key would store an empty password and
 * phpseclib would try to authenticate with it. Booleans are kept as they are —
 * `false` is a deliberate answer, and stripping it would silently re-enable
 * TLS on a destination whose owner turned it off.
 */
function cleanConfig(config = {}) {
  return Object.fromEntries(
    Object.entries(config).filter(([, value]) =>
      typeof value === "boolean" ? true : String(value ?? "").trim() !== "",
    ),
  );
}
