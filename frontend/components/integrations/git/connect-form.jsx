import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ExternalLink, KeyRound, Loader2, TriangleAlert } from "lucide-react";
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

/**
 * The credential last, after the fields that describe where it comes from.
 *
 * GitLab sent `token` then `host`, and the "Create a token" link is BUILT from
 * `host` — so a self-hosted user clicked a link to gitlab.com, the wrong
 * server, because the field it depends on was below it. Ordering here rather
 * than asking the backend to reorder: this is a property of the form's
 * reading order, not of the provider.
 */
function credentialLast(fields) {
  return [...fields].sort((a, b) => Number(isSecret(a)) - Number(isSecret(b)));
}

// Placeholders per provider field. The API sends name/label/required/type and
// no example, so the copy lives here — keyed to the field names the provider
// config actually defines. An unknown field gets no placeholder rather than a
// missing-key crash.
const PLACEHOLDER_FIELDS = new Set(["host", "workspace"]);

/**
 * Our explanation for a field the API describes with a placeholder alone.
 *
 * Keyed by provider and field because the same name means different things:
 * GitLab's `host` is an optional self-hosted address, and nothing on screen
 * said that leaving it blank is the normal answer.
 *
 * Returns undefined for anything unlisted, so a field the backend adds later
 * renders exactly as it does today rather than with an invented sentence.
 */
function fieldHelp(t, providerName, fieldName) {
  const key = `fieldHelp.${providerName}_${fieldName}`;
  return t.has(key) ? t(key) : undefined;
}
const TOKEN_PROVIDERS = new Set(["github", "gitlab", "bitbucket"]);

/**
 * One line telling you which boxes to tick, scopes as code.
 *
 * This replaces a stack of three to four grey paragraphs under a single input
 * — the backend's prose description of the scopes, a provider caveat, and a
 * reassurance about what the panel does — all the same size and colour, so
 * nothing was scannable and the one instruction sat third. Reported as
 * congested, and it was: Bitbucket carried five separate blocks.
 *
 * Falls back to the backend's own sentence for a provider we have no copy for,
 * so a fourth one still explains itself.
 */
function ScopeHint({ provider, fallback }) {
  const t = useTranslations("git.connect");
  const key = `scopeHint_${provider.name}`;

  if (!t.has(key)) {
    return fallback ? <FormDescription>{fallback}</FormDescription> : null;
  }

  return (
    <FormDescription>
      {t.rich(key, {
        // The scope is a literal string to find in a list of checkboxes, so it
        // is set as code — prose describing it is what people had to decode.
        scope: (chunks) => (
          <code className="rounded bg-muted px-1 py-0.5 font-mono text-foreground">{chunks}</code>
        ),
        option: (chunks) => <span className="font-medium text-foreground">{chunks}</span>,
      })}
    </FormDescription>
  );
}

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
  // The header chip is the TASK, not the provider: the band below carries the
  // provider at 44px, and the same logo twice in a 512px dialog reads as a
  // rendering mistake rather than as emphasis.
  const HeaderIcon = KeyRound;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={HeaderIcon}
        title={t("title", { provider: provider.title })}
        description={t("subtitle", { brand })}
        footer={
          /*
            One action in the footer.

            "Back" used to sit here doing exactly what "Change" in the band now
            does, so the dialog offered the same escape twice and gave the
            weaker of the two equal footing with the only thing you came to
            press. The band's version is better placed anyway — it is beside
            the provider it would change.
          */
          <div className="flex w-full justify-end">
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
        {/*
          The account being connected, as a band at the top of the body.

          The form had no focal point: three label-and-input pairs on a white
          sheet, and the only sign of which provider you were on was a 36px
          chip up in the dialog chrome. This is the same device the dashboard
          uses for the server's identity — one tinted, elevated tile for the
          thing the screen is actually about — and it gives "Change" a second,
          findable home beside the provider it would change.
        */}
        <div className="flex items-center gap-3 rounded-xl border border-primary/25 bg-primary/[0.06] p-3 shadow-e1 ring-1 ring-inset ring-background/60">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-card shadow-e1">
            <ProviderLogo provider={provider.name} className="size-6" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold">{provider.title}</p>
            {t.has(`hints.${provider.name}`) ? (
              <p className="truncate text-xs text-muted-foreground">
                {t(`hints.${provider.name}`)}
              </p>
            ) : null}
          </div>
          <Button type="button" variant="outline" size="sm" onClick={onBack} disabled={busy}>
            {t("change")}
          </Button>
        </div>

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
              <FormLabel required hint={t("nameHelp")}>
                {t("nameLabel")}
              </FormLabel>
              <FormControl>
                <Input
                  placeholder={t("namePlaceholder", { provider: provider.title })}
                  autoComplete="off"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t("nameLabel")} />
            </FormItem>
          )}
        />

        {credentialLast(provider.fields).map((spec) => (
          <FormField
            key={spec.name}
            control={form.control}
            name={spec.name}
            render={({ field }) => (
              <FormItem>
                {/*
                  The action sits on the label row, not in the stack below.
                  "Create a token on GitHub" was the third of four grey
                  paragraphs under the input — the one thing on the field you
                  can actually click, dressed identically to the prose around
                  it. On the label row it is the second thing read, next to the
                  field it fills.
                */}
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                  <FormLabel required={spec.required}>
                    {spec.label}
                    {spec.required ? null : (
                      <span className="ml-1 text-xs font-normal text-muted-foreground">
                        {t("optional")}
                      </span>
                    )}
                  </FormLabel>
                  {isSecret(spec) && tokenUrl ? (
                    <a
                      href={tokenUrl}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="inline-flex items-center gap-1 rounded-sm text-xs font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                      {t("createToken", { provider: provider.title })}
                      <ExternalLink className="size-3" aria-hidden />
                    </a>
                  ) : null}
                </div>
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
                    fallback={spec.help ?? provider.token_help}
                    value={field.value}
                    provider={provider}
                  />
                ) : spec.help ?? fieldHelp(t, provider.name, spec.name) ? (
                  /*
                   * The backend's own words where it has them, ours where it
                   * does not. The API sends `help` for tokens only, so the
                   * remaining fields arrived with a placeholder and nothing
                   * else — and a self-hosted URL box with an example in grey
                   * does not say that leaving it empty is what most people
                   * should do.
                   */
                  <FormDescription>
                    {spec.help ?? fieldHelp(t, provider.name, spec.name)}
                  </FormDescription>
                ) : null}
                <FormMessage field={spec.label} />
              </FormItem>
            )}
          />
        ))}

        {/*
          The Bitbucket note that used to sit here — "a repository-scoped token
          will show only that repository" — is now the second half of that
          provider's scope hint. It was saying the same thing as the backend's
          own `token_help`, two blocks apart, in the same grey.
        */}
      </FormModal>
    </Form>
  );
}

/**
 * Everything under the token input: one hint, and a warning only when earned.
 *
 * It used to be four stacked paragraphs — the backend's scope prose, the
 * create-token link, a provider caveat, and "we only read, never push". All
 * `FormDescription`, so all the same size and colour, so the one instruction
 * that mattered was third of four and nothing could be scanned.
 *
 * Where the three went: the link is on the label row; the caveats are folded
 * into each provider's single hint line, with the scopes as code; and "we only
 * read" is the dialog's subtitle, because it describes the panel rather than
 * this field.
 *
 * The scope names each provider needs are still spelled out exactly — GitHub's
 * link pre-ticks them, but Atlassian's page is a searchable list of 45
 * checkboxes with nothing marked, and GitLab's newer fine-grained tokens do
 * not offer the two the backend's help names at all. Both facts were learned
 * the hard way and neither is guessable from the provider's own UI.
 */
function TokenHelp({ fallback, value, provider }) {
  const t = useTranslations("git.connect");
  const pastedUrl = LOOKS_LIKE_URL.test(value ?? "");

  return (
    <div className="space-y-1">
      {/* Earned, not permanent: it appears only once the value already looks
          wrong, so it is the only thing under the field that ever competes
          with the hint. */}
      {pastedUrl ? <p className="text-xs text-warning">{t("looksLikeUrl")}</p> : null}
      <ScopeHint provider={provider} fallback={fallback} />
    </div>
  );
}
