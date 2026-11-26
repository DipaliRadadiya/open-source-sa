import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ExternalLink, KeyRound, Loader2 } from "lucide-react";
import { Caution } from "@/components/ui/caution";
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

const LOOKS_LIKE_URL = /^https?:\/\//i;

function isSecret(field) {
  return field.type === "password" || field.name === "token";
}

function credentialLast(fields) {
  return [...fields].sort((a, b) => Number(isSecret(a)) - Number(isSecret(b)));
}

const PLACEHOLDER_FIELDS = new Set(["host", "workspace"]);

function fieldHelp(t, providerName, fieldName) {
  const key = `fieldHelp.${providerName}_${fieldName}`;
  return t.has(key) ? t(key) : undefined;
}
const TOKEN_PROVIDERS = new Set(["github", "gitlab", "bitbucket"]);

function ScopeHint({ provider, fallback }) {
  const t = useTranslations("git.connect");
  const key = `scopeHint_${provider.name}`;

  if (!t.has(key)) {
    return fallback ? <FormDescription>{fallback}</FormDescription> : null;
  }

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
      <Caution size="md" className="mt-3">
        <p className="leading-relaxed">
          {t.rich(noteKey, {
            scope: (chunks) => (
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">{chunks}</code>
            ),
            strong: (chunks) => <span className="font-medium">{chunks}</span>,
          })}
        </p>
      </Caution>
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

export function ConnectForm({
  provider,
  open,
  onAccountConnected,
  onBack,
  onOpenChange,
  onCloseAutoFocus,
}) {
  const t = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const { refreshAndWait } = useRefresh();
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
      await refreshAndWait();
      const label = data?.git_account?.label ?? values.label;
      if (data?.existing) toast.info(data.message ?? t("alreadyConnected", { label }));
      else toast.success(t("connected", { label: values.label }));
      onAccountConnected?.({
        id: data?.git_account?.id ?? null,
        label,
        provider: provider.name,
      });
      onOpenChange?.(false);
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
        return;
      }
      const rejected = t("rejected", { provider: provider.title });
      setFailure(
        error.response?.status === 422 ? rejected : apiMessage(error, t("failed")),
      );
    }
  }

  const submitting = form.formState.isSubmitting;
  const busy = submitting;
  const host = useWatch({ control: form.control, name: "host" });
  const tokenUrl = createTokenUrl(provider.name, host, brand);
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
        onCloseAutoFocus={onCloseAutoFocus}
        footer={
          <div className="flex w-full justify-end">
            <Button type="submit" disabled={busy}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting
                ? t("verifying", { provider: provider.title })
                : t("submit")}
            </Button>
          </div>
        }
      >
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
          <Caution tone="destructive" size="md">
            <p className="leading-relaxed">{failure}</p>
          </Caution>
        ) : null}

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

function TokenHelp({ fallback, value, provider }) {
  const t = useTranslations("git.connect");
  const pastedUrl = LOOKS_LIKE_URL.test(value ?? "");

  return (
    <div className="space-y-1.5">
      {pastedUrl ? <p className="text-xs text-warning">{t("looksLikeUrl")}</p> : null}
      <ScopeHint provider={provider} fallback={fallback} />
    </div>
  );
}
