"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  CheckCircle2,
  ChevronRight,
  Clock,
  ExternalLink,
  KeyRound,
  Loader2,
  MoreHorizontal,
  Pencil,
  PlugZap,
  Plus,
  Server,
  Trash2,
  XCircle,
} from "lucide-react";
import {
  createRegistry,
  deleteRegistry,
  testRegistry,
  updateRegistry,
} from "@/lib/api/docker";
import { registryFormSchema } from "@/lib/schemas/docker";
import {
  REGISTRY_PROVIDERS,
  registryProvider,
  providerForAddress,
  issuesTemporaryTokens,
} from "@/lib/docker/registry-providers";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Caution } from "@/components/ui/caution";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { FormModal } from "@/components/ui/form-modal";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { ReasonTooltip, DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/**
 * The registry credentials this server can pull private images with, laid out like
 * Git integrations (Krishna, 21 Oct): a card head with Add, one row per credential
 * with its check and a ⋯ menu, and a two-step dialog — pick where the image is
 * hosted, then the fields that host needs.
 *
 * It never shows a token, and there is no reveal: the API does not serve one. On
 * edit the token box is empty and empty means "keep what is stored".
 */
export function RegistriesCard({ initialRegistries, canManage }) {
  const t = useTranslations("docker.registries");
  const { refreshAndWait } = useRefresh();

  const [testing, setTesting] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [dialog, setDialog] = useState(null);

  async function test(registry) {
    setTesting(registry.id);
    try {
      const { data } = await testRegistry(registry.id);
      await refreshAndWait();
      if (data?.success) toast.success(t("testPassed", { name: registry.name }));
      else toast.error(t(`errors.${data?.registry?.last_test_error ?? "unknown"}`));
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setTesting(null);
    }
  }

  async function remove(target) {
    setDeleting(target.id);
    try {
      await deleteRegistry(target.id);
      await refreshAndWait();
      toast.success(t("removed", { name: target.name }));
      setConfirm(null);
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setDeleting(null);
    }
  }

  const addButton = (
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
      <Button disabled={!canManage} onClick={() => setDialog({ registry: null })} data-registry-add>
        <Plus className="size-4" />
        {t("add")}
      </Button>
    </ReasonTooltip>
  );

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div>
            <h2 className="text-[15px] font-semibold tracking-tight">{t("listTitle")}</h2>
            <p className="text-sm text-muted-foreground">{t("hint")}</p>
          </div>
          {/* Empty, Add sits in the empty state instead of twice. */}
          {initialRegistries.length > 0 ? addButton : null}
        </div>

        <CardContent className="px-5 py-0">
          {initialRegistries.length === 0 ? (
            /* What to have ready, and that public images need none of this. */
            <div className="mx-auto flex max-w-lg flex-col items-center gap-5 py-10 text-center sm:py-12">
              <span className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
                <KeyRound className="size-6" aria-hidden />
              </span>
              <div className="space-y-2">
                <p className="text-[15px] font-semibold tracking-tight">{t("emptyTitle")}</p>
                <p className="max-w-md text-sm leading-6 text-muted-foreground">{t("emptyBody")}</p>
              </div>
              <div className="flex flex-wrap justify-center gap-2">
                {REGISTRY_PROVIDERS.map((provider) => (
                  <span
                    key={provider.id}
                    className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1.5 text-xs font-medium text-muted-foreground"
                  >
                    <RegistryLogo provider={provider.id} className="size-3.5" />
                    {t(`providers.${provider.id}.label`)}
                  </span>
                ))}
              </div>
              {addButton}
            </div>
          ) : (
            <div className="divide-y">
              {initialRegistries.map((registry) => (
                <RegistryRow
                  key={registry.id}
                  registry={registry}
                  canManage={canManage}
                  testing={testing === registry.id}
                  onTest={() => test(registry)}
                  onEdit={() => setDialog({ registry })}
                  onDelete={() => setConfirm(registry)}
                />
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Mounted only while open, so the last credential's values never flash. */}
      {dialog ? (
        <RegistryDialog
          key={dialog.registry?.id ?? "new"}
          registry={dialog.registry}
          onOpenChange={(next) => !next && setDialog(null)}
        />
      ) : null}

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && deleting === null && setConfirm(null)}
        icon={Trash2}
        tone="destructive"
        title={t("confirm.title", { name: confirm?.name ?? "" })}
        /* The applications are NOT deleted and the delete is NOT blocked by them: a
           credential that cannot be revoked while in use cannot be revoked at all. */
        description={
          confirm?.applications_count
            ? t("confirm.bodyInUse", { count: confirm.applications_count })
            : t("confirm.body")
        }
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        pending={deleting !== null}
        onConfirm={() => confirm && remove(confirm)}
      />
    </>
  );
}

/** Docker Hub's whale, GitHub and GitLab marks; a server for anything else. */
function RegistryLogo({ provider, className = "size-4" }) {
  if (provider === "dockerhub") {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src="/site-types/docker.svg" alt="" aria-hidden className={`shrink-0 ${className}`} />;
  }
  if (provider === "ghcr") return <ProviderLogo provider="github" className={className} />;
  if (provider === "gitlab") return <ProviderLogo provider="gitlab" className={className} />;
  return <Server className={`shrink-0 text-muted-foreground ${className}`} aria-hidden />;
}

function RegistryStatus({ registry }) {
  const t = useTranslations("docker.registries");

  if (registry.status === "connected") {
    return (
      <p className="inline-flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-400">
        <CheckCircle2 className="size-3.5" />
        {registry.status_title}
      </p>
    );
  }
  if (registry.status === "failed") {
    return (
      <p className="inline-flex items-center gap-1.5 text-xs text-destructive">
        <XCircle className="size-3.5 shrink-0" />
        {registry.status_title}
        {registry.last_test_error ? <span>· {t(`errors.${registry.last_test_error}`)}</span> : null}
      </p>
    );
  }
  // Not tested yet is not a fault in the credential, so muted rather than amber.
  return (
    <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
      <Clock className="size-3.5" />
      {registry.status_title}
    </p>
  );
}

function RegistryRow({ registry, canManage, testing, onTest, onEdit, onDelete }) {
  const t = useTranslations("docker.registries");
  const provider = providerForAddress(registry.registry);

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="flex flex-wrap items-start justify-between gap-3 py-3.5">
        <div className="flex min-w-64 flex-1 gap-3">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted">
            <RegistryLogo provider={provider} />
          </span>
          <div className="min-w-0 space-y-1.5">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="min-w-0 text-sm font-medium break-all">{registry.name}</span>
              <Badge variant="secondary" className="font-normal">
                {t(`providers.${provider}.label`)}
              </Badge>
              {/* Hub's credential is keyed on the legacy index URL, not what was typed;
                  the address shown is the stored one. */}
              <span className="truncate font-mono text-xs text-muted-foreground">{registry.registry}</span>
              <span className="truncate font-mono text-xs text-muted-foreground">· {registry.username}</span>
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <RegistryStatus registry={registry} />
              <span className="text-xs text-muted-foreground">
                {registry.applications_count
                  ? t("usedBySites", { count: registry.applications_count })
                  : t("usedByNone")}
              </span>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 max-sm:w-full max-sm:ps-11 sm:ms-auto sm:justify-end">
          <Button variant="outline" size="sm" disabled={!canManage || testing} aria-busy={testing} onClick={onTest}>
            {testing ? <Loader2 className="size-3.5 animate-spin" /> : <PlugZap className="size-3.5" />}
            {t("test")}
          </Button>
          <DropdownMenu>
            <Tooltip>
              <TooltipTrigger asChild>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="size-8" aria-label={t("moreActions", { name: registry.name })}>
                    <MoreHorizontal className="size-4" />
                  </Button>
                </DropdownMenuTrigger>
              </TooltipTrigger>
              <TooltipContent>{t("moreActions", { name: registry.name })}</TooltipContent>
            </Tooltip>
            <DropdownMenuContent align="end" className="w-48">
              <MenuItemHint hint={canManage ? null : t("noPermission")}>
                <DropdownMenuItem disabled={!canManage} onSelect={onEdit}>
                  <Pencil className="size-4" />
                  {t("edit")}
                </DropdownMenuItem>
              </MenuItemHint>
              <DropdownMenuSeparator />
              <MenuItemHint hint={canManage ? null : t("noPermission")}>
                <DropdownMenuItem variant="destructive" disabled={!canManage} onSelect={onDelete}>
                  <Trash2 className="size-4" />
                  {t("remove")}
                </DropdownMenuItem>
              </MenuItemHint>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </DisabledReasonProvider>
  );
}

// Empty gets the panel's "X is required"; anything else names the actual mistake.
function fieldError(error, invalid) {
  if (!error) return null;
  return error.type === "too_small" ? "requiredField" : invalid;
}

function focusAddButton(event) {
  const opener = document.querySelector("[data-registry-add]");
  if (!opener) return;
  event.preventDefault();
  opener.focus();
}

/**
 * Add: choose where the image is hosted, then that host's fields. Edit opens on the
 * fields for the stored host.
 */
function RegistryDialog({ registry, onOpenChange }) {
  const t = useTranslations("docker.registries");
  const tc = useTranslations("common");
  const { refreshAndWait } = useRefresh();
  const creating = registry === null;
  const [provider, setProvider] = useState(creating ? null : providerForAddress(registry.registry));

  if (!provider) {
    return (
      <FormModal
        open
        onOpenChange={onOpenChange}
        onCloseAutoFocus={focusAddButton}
        icon={KeyRound}
        title={t("add")}
        description={t("fields.provider")}
      >
        <div className="space-y-2">
          {REGISTRY_PROVIDERS.map((option) => (
            <button
              key={option.id}
              type="button"
              onClick={() => setProvider(option.id)}
              className="flex w-full items-center gap-3 rounded-lg border px-3 py-3 text-left transition-colors hover:bg-muted/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                <RegistryLogo provider={option.id} className="size-5" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium">{t(`providers.${option.id}.label`)}</span>
                <span className="block text-xs text-muted-foreground">
                  {option.address ? <span className="font-mono">{option.address}</span> : t("providers.other.hint")}
                </span>
              </span>
              <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            </button>
          ))}
        </div>
      </FormModal>
    );
  }

  return (
    <RegistryForm
      registry={registry}
      provider={provider}
      onBack={creating ? () => setProvider(null) : null}
      onOpenChange={onOpenChange}
      onSaved={async (name) => {
        await refreshAndWait();
        toast.success(creating ? t("created", { name }) : t("updated", { name }));
        onOpenChange(false);
      }}
      tc={tc}
    />
  );
}

function RegistryForm({ registry, provider, onBack, onOpenChange, onSaved, tc }) {
  const t = useTranslations("docker.registries");
  const creating = registry === null;
  const preset = registryProvider(provider);
  const [failure, setFailure] = useState(null);

  // On edit the token is optional: empty keeps the stored one. On create it is
  // required, since a credential with no token cannot do the one thing it is for.
  const schema = creating
    ? registryFormSchema
    : registryFormSchema.extend({ token: z.literal("").or(registryFormSchema.shape.token) });

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: {
      name: registry?.name ?? (provider === "other" ? "" : t(`providers.${provider}.label`)),
      registry: registry?.registry ?? preset.address,
      username: registry?.username ?? "",
      // Always empty, never a mask standing in for a stored value.
      token: "",
    },
  });
  const address = useWatch({ control: form.control, name: "registry" });
  const submitting = form.formState.isSubmitting;

  async function onSubmit(values) {
    setFailure(null);
    const payload = { ...values };
    // An absent token means "keep"; an empty string is not "clear it".
    if (!payload.token) delete payload.token;
    try {
      if (creating) await createRegistry(payload);
      else await updateRegistry(registry.id, payload);
      await onSaved(payload.name);
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
        return;
      }
      setFailure(apiMessage(error, t("failed")));
    }
  }

  return (
    <Form {...form}>
      <FormModal
        open
        onOpenChange={(next) => !submitting && onOpenChange(next)}
        onCloseAutoFocus={creating ? focusAddButton : undefined}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={KeyRound}
        title={creating ? t("add") : t("editTitle", { name: registry.name })}
        description={t("formDescription")}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              {tc("cancel")}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {creating ? t("add") : t("save")}
            </Button>
          </>
        }
      >
        {/* Where the image is hosted, with Change while adding. */}
        <div className="flex items-center gap-3 rounded-xl border border-primary/25 bg-primary/[0.06] p-3 shadow-e1">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-card shadow-e1">
            <RegistryLogo provider={provider} className="size-6" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold">{t(`providers.${provider}.label`)}</p>
            <p className="truncate font-mono text-xs text-muted-foreground">
              {preset.addressFixed ? preset.address : t("providers.other.hint")}
            </p>
          </div>
          {onBack ? (
            <Button type="button" variant="outline" size="sm" onClick={onBack} disabled={submitting}>
              {t("change")}
            </Button>
          ) : null}
        </div>

        {failure ? (
          <Caution tone="destructive" size="md">
            <p className="leading-relaxed">{failure}</p>
          </Caution>
        ) : null}

        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required hint={t("nameHint")}>{t("fields.name")}</FormLabel>
              <FormControl>
                <Input placeholder={t("placeholders.name")} autoComplete="off" {...field} />
              </FormControl>
              <FormMessage field={t("fields.name")}>{fieldError(form.formState.errors.name, t("invalid.name"))}</FormMessage>
            </FormItem>
          )}
        />

        {/* Fixed for the hosted providers: Docker ignores a credential stored under an
            address it does not recognise, so a typo here fails like no credential. */}
        {preset.addressFixed ? null : (
          <FormField
            control={form.control}
            name="registry"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("fields.registry")}</FormLabel>
                <FormControl>
                  <Input placeholder={t("placeholders.registry")} autoComplete="off" spellCheck={false} className="font-mono text-sm" {...field} />
                </FormControl>
                <FormDescription>{t("registryHint")}</FormDescription>
                {/* Named, not "invalid": a namespace is the common mistake. */}
                <FormMessage field={t("fields.registry")}>{fieldError(form.formState.errors.registry, t("invalid.registry"))}</FormMessage>
                {/* ECR, Artifact Registry and ACR tokens expire within hours. */}
                {issuesTemporaryTokens(address) ? <Caution size="md">{t("temporaryToken")}</Caution> : null}
              </FormItem>
            )}
          />
        )}

        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("fields.username")}</FormLabel>
              <FormControl>
                <Input placeholder={preset.usernamePlaceholder} autoComplete="off" spellCheck={false} {...field} />
              </FormControl>
              <FormDescription>{t(`providers.${provider}.usernameHint`)}</FormDescription>
              <FormMessage field={t("fields.username")}>{fieldError(form.formState.errors.username, t("invalid.username"))}</FormMessage>
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="token"
          render={({ field }) => (
            <FormItem>
              {/* Where to create the token, on the label row like Git's "Create a token". */}
              <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <FormLabel required={creating}>{t("fields.token")}</FormLabel>
                {preset.docsUrl ? (
                  <a
                    href={preset.docsUrl}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex items-center gap-1 rounded-sm text-xs font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                  >
                    {t(`providers.${provider}.linkLabel`)}
                    <ExternalLink className="size-3" aria-hidden />
                  </a>
                ) : null}
              </div>
              <FormControl>
                <PasswordInput
                  autoComplete="new-password"
                  spellCheck={false}
                  placeholder={preset.tokenPlaceholder}
                  className="font-mono text-sm"
                  {...field}
                  onChange={(event) => field.onChange(event.target.value.trim())}
                />
              </FormControl>
              <FormDescription>
                {creating ? t(`providers.${provider}.tokenHint`) : t("tokenHintEdit")}
              </FormDescription>
              <FormMessage field={t("fields.token")}>{fieldError(form.formState.errors.token, t("invalid.token"))}</FormMessage>
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
