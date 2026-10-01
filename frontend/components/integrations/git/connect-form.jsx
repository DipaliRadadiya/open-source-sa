import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ExternalLink, Info, KeyRound, Loader2, TriangleAlert } from "lucide-react";
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

/** A pasted URL is never a token; caught before a round-trip. */
const LOOKS_LIKE_URL = /^https?:\/\//i;

function isSecret(field) {
  return field.type === "password" || field.name === "token";
}

/**
 * The credential last: the "Create a token" link is built from fields like
 * `host`, which must be filled first.
 */
function credentialLast(fields) {
  return [...fields].sort((a, b) => Number(isSecret(a)) - Number(isSecret(b)));
}

// Fields with a local placeholder (the API sends none); others get no placeholder.
const PLACEHOLDER_FIELDS = new Set(["host", "workspace"]);

/**
 * Local help text for a field the API sends without `help`, keyed by provider
 * and field. Undefined for anything unlisted.
 */
function fieldHelp(t, providerName, fieldName) {
  const key = `fieldHelp.${providerName}_${fieldName}`;
  return t.has(key) ? t(key) : undefined;
}
const TOKEN_PROVIDERS = new Set(["github", "gitlab", "bitbucket"]);

/**
 * One line naming the scopes to tick, as code. Falls back to the backend's
 * sentence for providers without local copy.
 */
function ScopeHint({ provider, fallback }) {
  const t = useTranslations("git.connect");
  const key = `scopeHint_${provider.name}`;

  if (!t.has(key)) {
    return fallback ? <FormDescription>{fallback}</FormDescription> : null;
  }

  // Explains scopes broader than they sound (GitLab's `api` is full access).
  const noteKey = `scopeNote_${provider.name}`;

  return (
    <>
    <FormDescription>
      {t.rich(key, {
        scope: (chunks) => (
          <code className="rounded bg-muted px-1 py-0.5 font-mono text-foreground">{chunks}</code>
        ),
        option: (chunks) => <span className="font-medium text-foreground">{chunks}</span>,
      })}
    </FormDescription>
    {t.has(noteKey) ? (
      <div className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 p-2.5 text-xs leading-5 text-muted-foreground">
        <Info className="mt-0.5 size-3.5 shrink-0 text-warning" aria-hidden />
        <p>
          {t.rich(noteKey, {
            scope: (chunks) => (
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-foreground">{chunks}</code>
            ),
            strong: (chunks) => <span className="font-medium text-foreground">{chunks}</span>,
          })}
        </p>
      </div>
    ) : null}
    </>
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
 * The connect form for one provider, rendered from the backend's field list.
 * Mounted fresh per provider (keyed by the caller) so the generated Zod schema
 * is fixed for the life of the form.
 */

export function ConnectForm({
  provider,
  open,
  onAccountConnected,
  onBack,
  onOpenChange,
}) {
  const t = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const { refreshAndWait } = useRefresh();
  // Submission-level API errors (usually a rejected token), shown in the form.
  const [failure, setFailure] = useState(null);

  const defaults = { label: "" };
  for (const field of provider.fields) defaults[field.name] = "";

  const form = useForm({
    resolver: zodResolver(connectFormSchema(provider)),
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
      const { data } = await connectAccount(payload);
      // Refresh before the toast so the account is already listed.
      await refreshAndWait();
      toast.success(t("connected", { label: values.label }));
      // Reported for every connect; the list's next-step prompt names the
      // account and links with its id (from the response).
      onAccountConnected?.({
        id: data?.git_account?.id ?? null,
        label: values.label,
        provider: provider.name,
      });
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
  // useWatch, not form.watch(), which opts the component out of the React compiler.
  const host = useWatch({ control: form.control, name: "host" });
  const tokenUrl = createTokenUrl(provider.name, host, brand);
  // The header shows the task; the band below shows the provider.
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
          /* One action; "Change" in the band replaces a Back button. */
          <div className="flex w-full justify-end">
            {/* Labelled while busy: the API verifies the token with the provider. */}
            <Button type="submit" disabled={busy}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting
                ? t("verifying", { provider: provider.title })
                : t("submit")}
            </Button>
          </div>
        }
      >
        {/* The provider being connected, with "Change". */}
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

        {/* Local field: the label identifies the account across the panel. */}
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
                {/* The "Create a token" link sits on the label row. */}
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
                      // Trims pasted whitespace, which the provider would reject.
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
                  /* The API sends `help` for tokens only; local help covers the rest. */
                  <FormDescription>
                    {spec.help ?? fieldHelp(t, provider.name, spec.name)}
                  </FormDescription>
                ) : null}
                <FormMessage field={spec.label} />
              </FormItem>
            )}
          />
        ))}

      </FormModal>
    </Form>
  );
}

/**
 * Under the token input: the scope hint, plus a warning when the value looks
 * like a URL. Scope names are spelled out exactly, since Bitbucket's and
 * GitLab's token pages do not preselect them.
 */
function TokenHelp({ fallback, value, provider }) {
  const t = useTranslations("git.connect");
  const pastedUrl = LOOKS_LIKE_URL.test(value ?? "");

  return (
    <div className="space-y-1">
      {pastedUrl ? <p className="text-xs text-warning">{t("looksLikeUrl")}</p> : null}
      <ScopeHint provider={provider} fallback={fallback} />
    </div>
  );
}
