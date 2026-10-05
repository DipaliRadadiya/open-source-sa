import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ChevronDown, Loader2, Sparkles, UserRoundPlus } from "lucide-react";
import { createSystemUserSchema, DEFAULT_SHELL } from "@/lib/schemas/system-user";
import { createSystemUser, getShellCatalog } from "@/lib/api/system-users";
import { generatePassword } from "@/lib/applications/generate-password";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { cn } from "@/lib/utils";
import { offeredShells } from "@/lib/system-users/offered-shells";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { PasswordInput } from "@/components/ui/password-input";
import { FormModal } from "@/components/ui/form-modal";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

export function CreateSystemUserDialog({ open, onOpenChange, onCreated, initialShells = [] }) {
  // Fetched on open: only the system-users page has the catalog. The page passes
  // its copy so the field never shows a raw "/bin/bash" while loading.
  const [shells, setShells] = useState(initialShells);
  useEffect(() => {
    if (!open) return undefined;
    let cancelled = false;
    getShellCatalog()
      .then((list) => {
        if (!cancelled) setShells(list);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [open]);

  const t = useTranslations("systemUsers");
  const { refreshThen } = useRefresh();
  const [moreOpen, setMoreOpen] = useState(false);

  const form = useForm({
    resolver: zodResolver(createSystemUserSchema),
    defaultValues: {
      username: "",
      public_key: "",
      shell: DEFAULT_SHELL,
      sudo: false,
      ssh_access: false,
      password: "",
    },
  });

  // Not in `defaultValues`: the form resets to those on close, so one password
  // would be reused for every user created.
  useEffect(() => {
    if (!open) return;
    form.setValue("password", generatePassword());
    // Re-running on anything else would replace a password the user typed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  async function onSubmit(values) {
    // SSH access with a no-login shell is invalid (ShellSelect enforces the other side).
    if (values.ssh_access) {
      const chosen = shells.find((entry) => entry.value === (values.shell || DEFAULT_SHELL));
      if (chosen?.allows_login === false) {
        form.setError("ssh_access", {
          message: t("sshNeedsLoginShell", { shell: chosen.title }),
        });
        scrollToFirstError();
        return;
      }
    }

    const payload = { username: values.username };
    if (values.public_key?.trim()) payload.public_key = values.public_key.trim();
    // The backend treats these as `sometimes`; "just a username" stays a single useradd.
    if (values.shell && values.shell !== DEFAULT_SHELL) payload.shell = values.shell;
    if (values.sudo) payload.sudo = true;
    if (values.ssh_access) payload.ssh_access = true;
    if (values.password) payload.password = values.password;
    try {
      const { data } = await createSystemUser(payload);
      // Creating… holds until the new row is in the list behind the dialog.
      await new Promise((resolve) => refreshThen(resolve));
      toast.success(t("toast.created"));
      onCreated?.(data?.system_user ?? data?.user ?? null);
      // Not onOpenChange: our own close skips Radix's callback, leaving "More options" expanded.
      handleOpenChange(false);
    } catch (error) {
      handleValidationError(error, form, { fallback: t("toast.createFailed") });
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  const [sudoOn, chosenShell] = useWatch({ control: form.control, name: ["sudo", "shell"] });
  const chosenShellEntry = shells.find((entry) => entry.value === (chosenShell || DEFAULT_SHELL));
  const sshViaSudo = sudoOn && chosenShellEntry?.allows_login !== false;
  // The API refuses SSH access with a no-login shell.
  const noLoginShell = chosenShellEntry?.allows_login === false;
  useEffect(() => {
    if (noLoginShell && form.getValues("ssh_access")) {
      form.setValue("ssh_access", false, { shouldDirty: true });
      form.clearErrors("ssh_access");
    }
  }, [noLoginShell, form]);

  function handleOpenChange(next) {
    if (!next) {
      form.reset();
      setMoreOpen(false);
    }
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={UserRoundPlus}
        title={t("create.title")}
        description={t("create.subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("create.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("create.username")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("create.usernamePlaceholder")}
                  autoComplete="off"
                  className="font-mono"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('create.username')} />
            </FormItem>
          )}
        />
        {/* Safe to pre-fill: recoverable (Set password shows it again), unlike site basic-auth. Optional on the API. */}
        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            // Generate follows the input in the markup, so Tab reaches the field first.
            <FormItem className="relative">
              <FormLabel hint={t("create.passwordHint")}>{t("create.password")}</FormLabel>
              <FormControl>
                <PasswordInput
                  autoComplete="new-password"
                  placeholder={t("create.passwordPlaceholder")}
                  {...field}
                />
              </FormControl>
              <Button
                type="button"
                variant="link"
                size="sm"
                className="absolute top-0 right-0 h-auto p-0 text-xs"
                onClick={() =>
                  form.setValue("password", generatePassword(), {
                    shouldDirty: true,
                    shouldValidate: true,
                  })
                }
              >
                <Sparkles className="size-3" />
                {t("create.generate")}
              </Button>
              <FormMessage field={t('create.password')} />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="public_key"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("create.publicKeyHint")}>{t("create.publicKey")}</FormLabel>
              <FormControl>
                <Textarea
                  rows={3}
                  placeholder={t("create.publicKeyPlaceholder")}
                  // No text-xs: below 16px makes iOS zoom on focus.
                  className="font-mono"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('create.publicKey')} />
            </FormItem>
          )}
        />


        {/* Shell, sudo and SSH stay folded: the common case is "just a username". */}
        <Collapsible open={moreOpen} onOpenChange={setMoreOpen}>
          <CollapsibleTrigger asChild>
            <Button type="button" variant="ghost" size="sm" className="-ml-2">
              {t("create.more")}
              <ChevronDown className={cn("size-3.5 transition-transform", moreOpen && "rotate-180")} />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="-mx-1 overflow-hidden px-1 data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
            <div className="mt-3 space-y-4">
              <FormField
                control={form.control}
                name="shell"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel hint={t("create.shellHint")}>{t("create.shell")}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full text-xs">
                          {/* Title only; see shell-select.jsx. */}
                          <SelectValue>
                            {shells.find((entry) => entry.value === field.value)?.title ??
                              field.value}
                          </SelectValue>
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {/* Until the catalog arrives the default is the single option, so the field is never empty. */}
                        {(shells.length
                          ? offeredShells(shells, field.value)
                          : [{ value: DEFAULT_SHELL, title: DEFAULT_SHELL }]
                        ).map((shell) => (
                          <SelectItem key={shell.value} value={shell.value} className="text-xs">
                            <span className="flex flex-col">
                              <span>{shell.title}</span>
                              <span className="font-mono text-xs text-muted-foreground">
                                {shell.value}
                              </span>
                            </span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {[
                { name: "sudo", label: t("create.sudo"), hint: t("create.sudoHint") },
                {
                  name: "ssh_access",
                  label: t("create.sshAccess"),
                  // Sudo users always get SSH.
                  hint: noLoginShell
                    ? t("sshNeedsLoginShell", { shell: chosenShellEntry.title })
                    : sshViaSudo
                      ? t("sshViaSudo")
                      : t("create.sshAccessHint"),
                  locked: sshViaSudo || noLoginShell,
                  lockedValue: !noLoginShell,
                },
              ].map((toggle) => (
                <FormField
                  key={toggle.name}
                  control={form.control}
                  name={toggle.name}
                  render={({ field }) => (
                    <FormItem>
                      {/* A real label so the whole row toggles, like other switches in the panel. */}
                      <label
                        className={cn(
                          "flex items-center justify-between gap-4 rounded-lg border p-3",
                          toggle.locked ? "cursor-not-allowed" : "cursor-pointer",
                        )}
                      >
                        <div className="space-y-0.5">
                          <span className="block text-sm font-medium">{toggle.label}</span>
                          <span className="block text-xs text-muted-foreground">{toggle.hint}</span>
                        </div>
                        <div className="flex h-5 shrink-0 items-center">
                          <FormControl>
                            <Switch
                              checked={toggle.locked ? toggle.lockedValue : field.value}
                              disabled={toggle.locked}
                              disabledReason={toggle.locked ? toggle.hint : undefined}
                              onCheckedChange={field.onChange}
                              aria-label={toggle.label}
                            />
                          </FormControl>
                        </div>
                      </label>
                      {/* The server refuses SSH on a no-login shell by erroring on `ssh_access`. */}
                      <FormMessage />
                    </FormItem>
                  )}
                />
              ))}

            </div>
          </CollapsibleContent>
        </Collapsible>
      </FormModal>
    </Form>
  );
}
