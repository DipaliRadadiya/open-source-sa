"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ChevronDown, Loader2, Sparkles, UserRoundPlus } from "lucide-react";
import { createSystemUserSchema, DEFAULT_SHELL } from "@/lib/schemas/system-user";
import { createSystemUser, getShellCatalog } from "@/lib/api/system-users";
import { generatePassword } from "@/lib/applications/generate-password";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { cn } from "@/lib/utils";
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

export function CreateSystemUserDialog({ open, onOpenChange, onCreated }) {
  // Fetched when the dialog opens rather than passed in: it is opened from the
  // system-users page AND from the application form, and only one of those has
  // the catalog to hand.
  const [shells, setShells] = useState([]);
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
  const router = useRouter();
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

  // A fresh password each time the dialog opens, filled in rather than left
  // blank. Set here rather than in `defaultValues` for two reasons: the form is
  // reset back to those defaults on close, so one generated at mount would be
  // reused for every user created in this session; and generating during render
  // is a side effect in a place React does not allow one.
  useEffect(() => {
    if (!open) return;
    form.setValue("password", generatePassword());
    // form is stable; re-running on anything else would replace a password the
    // user had already typed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  async function onSubmit(values) {
    // Caught here rather than by the server, because the catalog says which
    // shells refuse login and the pair is nonsense either way: SSH access on
    // an account whose shell hangs up on you. Same rule ShellSelect enforces
    // from the other direction.
    if (values.ssh_access) {
      const chosen = shells.find((entry) => entry.value === (values.shell || DEFAULT_SHELL));
      if (chosen?.allows_login === false) {
        form.setError("ssh_access", {
          message: t("create.sshNeedsLoginShell", { shell: chosen.title }),
        });
        scrollToFirstError();
        return;
      }
    }

    const payload = { username: values.username };
    if (values.public_key?.trim()) payload.public_key = values.public_key.trim();
    // Only send what was actually chosen. The backend treats every one of
    // these as `sometimes`, so an untouched field must be absent rather than
    // sent as its default — that keeps "just a username" a single useradd.
    if (values.shell && values.shell !== DEFAULT_SHELL) payload.shell = values.shell;
    if (values.sudo) payload.sudo = true;
    if (values.ssh_access) payload.ssh_access = true;
    if (values.password) payload.password = values.password;
    try {
      const { data } = await createSystemUser(payload);
      toast.success(t("toast.created"));
      onCreated?.(data?.system_user ?? data?.user ?? null);
      // handleOpenChange, not onOpenChange: closing by our own code path skips
      // Radix's callback, so "More options" stayed expanded into the next open
      // — a dialog that is supposed to start as three fields.
      handleOpenChange(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

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
              <FormMessage />
            </FormItem>
          )}
        />
        {/* On the main form, and filled in already.
            It used to be folded away with the rest, on the reasoning that a
            new user has no SSH access and a no-login shell, so a password can
            do nothing. True on day one — but several later operations need
            one, and people were creating a user, discovering that, and coming
            back to set it. A generated value costs nothing to leave alone and
            removes the second trip.
            Safe to fill in because this password is recoverable: the backend
            stores it and the user's own Set password dialog shows it again.
            (Contrast the site basic-auth one, which is genuinely readable only
            once and so must never be pre-filled.) Clearing it still works —
            `password` is optional on the API. */}
        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            // Generate is positioned by the label but comes after the input in
            // the markup, so Tab reaches the field first.
            <FormItem className="relative">
              <FormLabel>{t("create.password")}</FormLabel>
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
              <p className="text-xs text-muted-foreground">{t("create.passwordHint")}</p>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="public_key"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t("create.publicKey")}</FormLabel>
              <FormControl>
                <Textarea
                  rows={3}
                  placeholder={t("create.publicKeyPlaceholder")}
                  // font-mono only: the size comes from Textarea, like every
                  // other field. An explicit text-xs here made this the one
                  // field in the form at a different size, and on a phone it
                  // also opts out of the 16px that stops iOS zooming the page
                  // when the field is focused.
                  className="font-mono"
                  {...field}
                />
              </FormControl>
              <p className="text-xs text-muted-foreground">
                {t("create.publicKeyHint")}
              </p>
              <FormMessage />
            </FormItem>
          )}
        />


        {/* Shell, sudo and SSH login stay folded: the common case is still
            "just a username", and they would turn a three-field dialog into
            six. */}
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
                    <FormLabel>{t("create.shell")}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full text-xs">
                          {/* Title only — see shell-select.jsx. */}
                          <SelectValue>
                            {shells.find((entry) => entry.value === field.value)?.title ??
                              field.value}
                          </SelectValue>
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {/* Title first, path underneath: "No login" is what the
                            choice means, `/usr/sbin/nologin` is only how it is
                            spelled. Until the catalog arrives the default is
                            the single option, so the field is never empty. */}
                        {(shells.length
                          ? shells
                          : [{ value: DEFAULT_SHELL, title: DEFAULT_SHELL }]
                        ).map((shell) => (
                          <SelectItem key={shell.value} value={shell.value} className="text-xs">
                            <span className="flex flex-col">
                              <span>{shell.title}</span>
                              <span className="font-mono text-[11px] text-muted-foreground">
                                {shell.value}
                              </span>
                            </span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">{t("create.shellHint")}</p>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {[
                { name: "sudo", label: t("create.sudo"), hint: t("create.sudoHint") },
                { name: "ssh_access", label: t("create.sshAccess"), hint: t("create.sshAccessHint") },
              ].map((toggle) => (
                <FormField
                  key={toggle.name}
                  control={form.control}
                  name={toggle.name}
                  render={({ field }) => (
                    <FormItem>
                      {/* A real label, so the whole row toggles — same as the
                          firewall and password-protection switches. */}
                      <label className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
                        <div className="space-y-0.5">
                          <span className="block text-sm font-medium">{toggle.label}</span>
                          <span className="block text-xs text-muted-foreground">{toggle.hint}</span>
                        </div>
                        <div className="flex h-5 shrink-0 items-center">
                          <FormControl>
                            <Switch
                              checked={field.value}
                              onCheckedChange={field.onChange}
                              aria-label={toggle.label}
                            />
                          </FormControl>
                        </div>
                      </label>
                      {/* These two were the only fields in the dialog with no
                          message slot. The server refuses SSH on a no-login
                          shell by erroring on `ssh_access` — a real form field,
                          so the handler set it inline — and it rendered
                          nowhere: Create did nothing, said nothing. */}
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
