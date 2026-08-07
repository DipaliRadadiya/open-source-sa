"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ArrowLeft, ExternalLink, Loader2, TriangleAlert } from "lucide-react";
import { connectFormSchema } from "@/lib/schemas/git";
import { connectAccount } from "@/lib/api/git";
import { createTokenUrl } from "@/lib/git/provider-links";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { useBranding } from "@/components/branding-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import { PasswordInput } from "@/components/ui/password-input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/** A pasted URL is never a token, and saying so beats a round-trip to GitHub. */
const LOOKS_LIKE_URL = /^https?:\/\//i;

function isSecret(field) {
  return field.type === "password" || field.name === "token";
}

// Placeholders per provider field. The API sends name/label/required/type and
// no example, so the copy lives here — keyed to the field names the provider
// config actually defines. An unknown field gets no placeholder rather than a
// missing-key crash.
const PLACEHOLDER_FIELDS = new Set(["host", "workspace"]);
const TOKEN_PROVIDERS = new Set(["github", "gitlab", "bitbucket"]);

function fieldPlaceholder(t, providerName, fieldName) {
  if (fieldName === "token") {
    return TOKEN_PROVIDERS.has(providerName)
      ? t(`placeholders.token_${providerName}`)
      : undefined;
  }
  return PLACEHOLDER_FIELDS.has(fieldName) ? t(`placeholders.${fieldName}`) : undefined;
}

/**
 * The connect form for one provider.
 *
 * Mounted fresh per provider (keyed by the caller) so the Zod schema, which is
 * generated from that provider's field list, is fixed for the life of the form.
 * The fields themselves are rendered from the backend's description of them —
 * one renderer, not three hardcoded forms, so a fourth provider is a backend
 * change only.
 */

export function ConnectForm({
  provider,
  open,
  showNextStep,
  onFirstAccountConnected,
  onBack,
  onOpenChange,
}) {
  const t = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const router = useRouter();
  // Errors the API returns about the whole submission — a rejected token, most
  // often. Shown in the form, because that is where the thing to fix is.
  const [failure, setFailure] = useState(null);

  const defaults = { label: "" };
  for (const field of provider.fields) defaults[field.name] = "";

  const form = useForm({
    resolver: zodResolver(connectFormSchema(provider)),
    // Blur-time errors on a half-filled new form read as being told off for
    // moving to the next field.
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults,
  });

  async function onSubmit(values) {
    setFailure(null);
    const payload = { provider: provider.name, label: values.label };
    for (const field of provider.fields) {
      const value = values[field.name]?.trim();
      if (value) payload[field.name] = value;
    }

    try {
      await connectAccount(payload);
      toast.success(t("connected", { label: values.label }));
      router.refresh();
      // The next action belongs on the refreshed account list, where it remains
      // readable and actionable, rather than in a modal that closes on a timer.
      if (showNextStep) onFirstAccountConnected?.();
      onOpenChange?.(false);
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
        return;
      }
      setFailure(apiMessage(error, t("rejected", { provider: provider.title })));
    }
  }

  const submitting = form.formState.isSubmitting;
  const busy = submitting;
  // useWatch, not form.watch(): the latter returns a fresh function each
  // render, which opts this whole component out of the React compiler.
  const host = useWatch({ control: form.control, name: "host" });
  const tokenUrl = createTokenUrl(provider.name, host, brand);
  // The dialog's header mark is the provider's own, so the form says which
  // account you are connecting without reading a word.
  const HeaderIcon = (props) => <ProviderLogo provider={provider.name} {...props} />;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={HeaderIcon}
        title={t("title", { provider: provider.title })}
        description={t("subtitle")}
        footer={
          <div className="flex w-full items-center justify-between gap-2">
            <Button
              type="button"
              variant="ghost"
              onClick={onBack}
              disabled={busy}
            >
              <ArrowLeft className="size-4" />
              {t("back")}
            </Button>
            {/* Named, not a spinner: the API verifies the credential against
                the provider before storing it, so this genuinely waits on
                GitHub, and a silent four seconds reads as broken. */}
            <Button type="submit" disabled={busy}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting
                ? t("verifying", { provider: provider.title })
                : t("submit")}
            </Button>
          </div>
        }
      >
        {failure ? (
          <div className="flex gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
            <p className="text-xs leading-relaxed">{failure}</p>
          </div>
        ) : null}

        {/* Ours, not the API's: the name is how this account is identified
            everywhere else, including the app-create dropdown later. */}
        <FormField
          control={form.control}
          name="label"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("nameLabel")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("namePlaceholder", { provider: provider.title })}
                  autoComplete="off"
                  {...field}
                />
              </FormControl>
              <FormDescription>{t("nameHelp")}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        {provider.fields.map((spec) => (
          <FormField
            key={spec.name}
            control={form.control}
            name={spec.name}
            render={({ field }) => (
              <FormItem>
                <FormLabel required={spec.required}>
                  {spec.label}
                  {spec.required ? null : (
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                      {t("optional")}
                    </span>
                  )}
                </FormLabel>
                <FormControl>
                  {isSecret(spec) ? (
                    <PasswordInput
                      autoComplete="off"
                      spellCheck={false}
                      placeholder={fieldPlaceholder(t, provider.name, spec.name)}
                      {...field}
                      // Tokens are pasted, and a copied line often carries a
                      // trailing newline the provider then rejects for no
                      // reason the user can see.
                      onChange={(event) => field.onChange(event.target.value.trim())}
                    />
                  ) : (
                    <Input
                      autoComplete="off"
                      spellCheck={false}
                      placeholder={fieldPlaceholder(t, provider.name, spec.name)}
                      {...field}
                    />
                  )}
                </FormControl>
                {isSecret(spec) ? (
                  <TokenHelp
                    help={provider.token_help}
                    url={tokenUrl}
                    value={field.value}
                    provider={provider}
                  />
                ) : null}
                <FormMessage />
              </FormItem>
            )}
          />
        ))}

        {/* Not a failure, and it looks exactly like one: a repository-scoped
            Bitbucket token connects fine and then lists a single repository,
            because that is the access it was granted. */}
        {provider.name === "bitbucket" ? (
          <p className="rounded-lg border bg-muted/40 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
            {t("bitbucketScope")}
          </p>
        ) : null}
      </FormModal>
    </Form>
  );
}

function TokenHelp({ help, url, value, provider }) {
  const t = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const pastedUrl = LOOKS_LIKE_URL.test(value ?? "");

  return (
    <div className="space-y-1">
      {pastedUrl ? <p className="text-xs text-warning">{t("looksLikeUrl")}</p> : null}
      {help ? <FormDescription>{help}</FormDescription> : null}
      {url ? (
        <a
          href={url}
          target="_blank"
          rel="noreferrer noopener"
          className="inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
        >
          {t("createToken", { provider: provider.title })}
          <ExternalLink className="size-3" />
        </a>
      ) : null}
      {/* Asking someone to paste a credential without saying what will be done
          with it is the whole objection this answers. */}
      <FormDescription>{t("readOnly", { brand })}</FormDescription>
    </div>
  );
}
