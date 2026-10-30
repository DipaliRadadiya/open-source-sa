"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { ChevronDown, Copy, Loader2, Power, SquareTerminal, UserRound } from "lucide-react";
import Link from "@/components/ui/app-link";
import {
  disableContainerAccess,
  enableContainerAccess,
  getContainerAccess,
} from "@/lib/api/docker";
import { findSystemUser, setSystemUserSsh } from "@/lib/api/system-users";
import { containerAccessSchema, containerLoginSchema } from "@/lib/schemas/docker";
import {
  defaultContainer,
  hostSshStillOn,
  shellCommandFor,
  showsContainerAccess,
  turnOnNeeds,
} from "@/lib/applications/container-access";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { PasswordInput } from "@/components/ui/password-input";
import { Skeleton } from "@/components/ui/skeleton";
import { FormModal } from "@/components/ui/form-modal";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Caution } from "@/components/ui/caution";
import { Note } from "@/components/ui/note";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

// Throws on a response the card cannot render, so it hides like a failed read.
function readAccess(data) {
  const access = containerAccessSchema.parse(data?.data ?? data);
  if (access.owner.container_login && !access.commands) {
    throw new Error("Container login is on but the response has no commands.");
  }
  return access;
}

const linkClass = "font-medium text-primary underline-offset-4 hover:underline";

/**
 * The app's own system user is the way in: once container login is on, its SSH
 * login opens this app's container, with no sudo. A dashboard card like Backups:
 * off, one row and a Turn on button (the form is a dialog, not a wall on the
 * dashboard); on, the shell line to copy, the rest folded.
 *
 * An extra, not a status: a failed read hides the card (with a console warning)
 * rather than putting an error on the dashboard.
 */
export function ContainerAccessCard({
  application,
  canSeeSystemUsers = false,
  canManageSystemUsers = false,
  canOpenSecurity = false,
  className,
}) {
  const t = useTranslations("applications.containerAccess");
  const enabled = showsContainerAccess(application);
  const [access, setAccess] = useState(null);
  const [failed, setFailed] = useState(false);
  const [offPending, setOffPending] = useState(false);
  const [turningOn, setTurningOn] = useState(false);
  // The owner's System User row (for its id) and whether the server enforces SSH access.
  const [sshUser, setSshUser] = useState(null);
  const [confirmOff, setConfirmOff] = useState(false);
  const [alsoSsh, setAlsoSsh] = useState(false);
  const [offError, setOffError] = useState(null);
  const ownerName = access?.owner.username;
  const ownerSsh = access?.owner.ssh_access;

  useEffect(() => {
    if (!enabled) return undefined;
    let cancelled = false;

    getContainerAccess(application.id)
      .then(({ data }) => {
        const next = readAccess(data);
        if (!cancelled) setAccess(next);
      })
      .catch((error) => {
        console.warn("Command-line access is unavailable for this application.", error);
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
    };
  }, [application.id, enabled]);

  useEffect(() => {
    if (!ownerName || !canSeeSystemUsers) return undefined;
    let cancelled = false;
    findSystemUser(ownerName)
      .then((found) => !cancelled && setSshUser(found))
      .catch(() => !cancelled && setSshUser(null));
    return () => {
      cancelled = true;
    };
  }, [ownerName, ownerSsh, canSeeSystemUsers]);

  if (!enabled || failed) return null;

  const enforced = sshUser?.enforced ?? null;

  // After POST or DELETE: the answer if it carries the card's data, else a re-read.
  async function settle(response) {
    try {
      setAccess(readAccess(response?.data));
    } catch {
      try {
        const { data } = await getContainerAccess(application.id);
        setAccess(readAccess(data));
      } catch (error) {
        console.warn("Command-line access is unavailable for this application.", error);
        setFailed(true);
      }
    }
  }

  // With server SSH still possible after it, Turn off asks first; otherwise one click as before.
  function requestTurnOff() {
    const afterOff = { ...access, owner: { ...access.owner, container_login: false } };
    if (hostSshStillOn(afterOff, enforced)) {
      setAlsoSsh(false);
      setOffError(null);
      setConfirmOff(true);
      return;
    }
    turnOff(false);
  }

  async function turnOff(alsoServerSsh) {
    const name = access.owner.username;
    setOffPending(true);
    let response;
    try {
      response = await disableContainerAccess(application.id);
    } catch (error) {
      const message = apiMessage(error, t("commands.turnOffFailed"));
      if (confirmOff) setOffError(message);
      else toast.error(message);
      setOffPending(false);
      return;
    }
    if (alsoServerSsh && sshUser?.user) {
      // Only after container login is off; a failure here leaves SSH exactly as it was.
      try {
        await setSystemUserSsh(sshUser.user.id, false);
        toast.success(t("commands.turnedOffBoth", { username: name }));
      } catch (error) {
        toast.error(apiMessage(error, t("commands.sshOffFailed", { username: name })));
      }
      await settle(null);
    } else {
      const afterOff = { ...access, owner: { ...access.owner, container_login: false } };
      toast.success(t(hostSshStillOn(afterOff, enforced) ? "commands.turnedOffHostSsh" : "commands.turnedOff", { username: name }));
      await settle(response);
    }
    setConfirmOff(false);
    setOffPending(false);
  }

  const username = access?.owner.username;
  const loginOn = Boolean(access?.owner.container_login);
  const systemUserHref = username
    ? `/system-users?search=${encodeURIComponent(username)}`
    : "/system-users";

  return (
    <Card className={className} aria-labelledby="container-access-heading">
      {/* The dashboard card head: title with its state, the one action on the right. */}
      <CardHeader className="items-center border-b">
        <CardTitle id="container-access-heading" as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
          {access ? (
            <span className="text-xs font-normal text-muted-foreground" data-testid="container-access-state">
              {loginOn ? t("stateOn") : t("stateOff")}
            </span>
          ) : null}
        </CardTitle>
        {access && canManageSystemUsers ? (
          <CardAction className="row-span-1 self-center">
            {loginOn ? (
              <Button type="button" variant="outline" size="sm" onClick={requestTurnOff} disabled={offPending}>
                {offPending ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
                {t("commands.turnOff")}
              </Button>
            ) : (
              <Button type="button" variant="outline" size="sm" onClick={() => setTurningOn(true)}>
                <Power className="size-4" />
                {t("turnOn.open")}
              </Button>
            )}
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent className="p-0">
        {!access ? (
          <div className="space-y-3 px-(--card-spacing)" aria-busy="true" aria-label={t("loading")}>
            <Skeleton className="h-4 w-56" />
            <Skeleton className="h-4 w-full" />
          </div>
        ) : (
          <div className="-mt-(--card-spacing) divide-y text-sm">
            <div className="flex items-center gap-3 px-(--card-spacing) py-3" data-testid="container-access-owner">
              <UserRound className="size-4 shrink-0 text-muted-foreground" />
              <span className="flex-1 font-medium">{t("systemUser")}</span>
              {canSeeSystemUsers ? (
                <Link href={systemUserHref} prefetch={false} className={`font-mono text-[13px] ${linkClass}`}>
                  {username}
                </Link>
              ) : (
                <strong className="font-mono text-[13px] font-medium">{username}</strong>
              )}
            </div>

            {hostSshStillOn(access, enforced) ? (
              <div className="px-(--card-spacing) py-3" data-testid="container-access-host-ssh">
                <Caution size="md">
                  <p>
                    {enforced === false
                      ? t.rich("hostSshNotEnforced", {
                          username,
                          link: (chunks) =>
                            canOpenSecurity ? (
                              <Link href="/settings/security" prefetch={false} className={linkClass}>
                                {chunks}
                              </Link>
                            ) : (
                              chunks
                            ),
                        })
                      : t.rich("hostSshOn", {
                          username,
                          link: (chunks) =>
                            canSeeSystemUsers ? (
                              <Link href={systemUserHref} prefetch={false} className={linkClass}>
                                {chunks}
                              </Link>
                            ) : (
                              chunks
                            ),
                        })}
                  </p>
                </Caution>
              </div>
            ) : null}

            {loginOn ? (
              <div className="px-(--card-spacing) py-4">
                <Commands access={access} canSeeSystemUsers={canSeeSystemUsers} />
              </div>
            ) : canManageSystemUsers ? (
              <p className="px-(--card-spacing) py-3 text-muted-foreground">{t("offBody", { username })}</p>
            ) : (
              <p className="px-(--card-spacing) py-3 text-muted-foreground" data-testid="container-access-read-only">
                {t("readOnly", { username })}
              </p>
            )}
          </div>
        )}
      </CardContent>

      {access && loginOn && canManageSystemUsers ? (
        <ConfirmDialog
          open={confirmOff}
          onOpenChange={setConfirmOff}
          icon={Power}
          tone="warning"
          title={t("turnOffDialog.title", { username })}
          description={t("turnOffDialog.body", { username })}
          confirmLabel={t("turnOffDialog.submit")}
          pending={offPending}
          error={offError}
          onConfirm={() => turnOff(alsoSsh)}
        >
          {enforced === false ? (
            <Note>
              {t.rich("turnOffDialog.notEnforced", {
                username,
                link: (chunks) =>
                  canOpenSecurity ? (
                    <Link href="/settings/security" prefetch={false} className={linkClass}>
                      {chunks}
                    </Link>
                  ) : (
                    chunks
                  ),
              })}
            </Note>
          ) : sshUser?.user ? (
            <div className="flex items-start gap-3 rounded-lg border p-3" data-testid="container-access-also-ssh">
              <Checkbox
                id="container-access-also-ssh"
                checked={alsoSsh}
                onCheckedChange={(value) => setAlsoSsh(value === true)}
                disabled={offPending}
                className="mt-0.5"
              />
              <div className="min-w-0 space-y-1">
                <Label htmlFor="container-access-also-ssh" className="leading-snug">
                  {t("turnOffDialog.alsoSsh", { username })}
                </Label>
                <p className="text-xs leading-relaxed text-muted-foreground">{t("turnOffDialog.alsoSshHint", { username })}</p>
              </div>
            </div>
          ) : (
            <Note>{t.rich("hostSshOn", { username, link: (chunks) => chunks })}</Note>
          )}
        </ConfirmDialog>
      ) : null}

      {access && !loginOn && canManageSystemUsers ? (
        <TurnOnDialog
          open={turningOn}
          onOpenChange={setTurningOn}
          access={access}
          applicationId={application.id}
          canOpenSecurity={canOpenSecurity}
          onEnabled={async (response) => {
            setTurningOn(false);
            await settle(response);
          }}
        />
      ) : null}
    </Card>
  );
}

function TurnOnDialog({ open, onOpenChange, access, applicationId, canOpenSecurity, onEnabled }) {
  const t = useTranslations("applications.containerAccess.turnOn");
  const tc = useTranslations("common");
  const ts = useTranslations("systemUsers.sshForm");
  const { owner } = access;
  const username = owner.username;
  const needs = turnOnNeeds(access);
  const confirm = owner.other_apps > 0;
  const askKey = owner.ssh_keys === 0;
  const askPassword = askKey && access.password_login === true;

  const form = useForm({
    resolver: zodResolver(containerLoginSchema({ needs, confirm })),
    defaultValues: { ssh_key: "", password: "", confirm_container_only: false },
  });
  const submitting = form.formState.isSubmitting;
  const rootError = form.formState.errors.root?.server?.message;

  // Each opening starts clean: a dialog opened by our own button skips onOpenChange.
  const { reset } = form;
  useEffect(() => {
    if (open) reset();
  }, [open, reset]);

  async function onSubmit(values) {
    const payload = {};
    if (values.ssh_key) payload.ssh_key = values.ssh_key;
    if (values.password) payload.password = values.password;
    if (confirm) payload.confirm_container_only = true;
    try {
      const response = await enableContainerAccess(applicationId, payload);
      toast.success(t("done", { username }));
      await onEnabled(response);
    } catch (error) {
      handleValidationError(error, form, { formError: true, fallback: t("failed") });
    }
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={(next) => !submitting && onOpenChange(next)}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={SquareTerminal}
        title={t("title", { username })}
        description={t("body", { username })}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              {tc("cancel")}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
              {t("submit")}
            </Button>
          </>
        }
      >
        {askKey ? (
          <FormField
            control={form.control}
            name="ssh_key"
            render={({ field }) => (
              <FormItem>
                <FormLabel required={needs === "key"} hint={ts("publicKeyHint")}>
                  {t("key")}
                </FormLabel>
                <FormControl>
                  <Textarea
                    rows={3}
                    spellCheck={false}
                    autoComplete="off"
                    placeholder={ts("publicKeyPlaceholder")}
                    className="font-mono text-xs"
                    {...field}
                  />
                </FormControl>
                <p className="text-xs text-muted-foreground">{t("keyRecommended")}</p>
                <FormMessage />
              </FormItem>
            )}
          />
        ) : null}
        {askPassword ? (
          <FormField
            control={form.control}
            name="password"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t("password")}</FormLabel>
                <FormControl>
                  <PasswordInput autoComplete="new-password" {...field} />
                </FormControl>
                <p className="text-xs text-muted-foreground">
                  {owner.has_password ? t("passwordReplaces", { username }) : t("passwordHint")}
                </p>
                <FormMessage />
              </FormItem>
            )}
          />
        ) : null}

        {needs === "key" ? (
          <Note>
            {t.rich("keyRequired", {
              link: (chunks) =>
                canOpenSecurity ? (
                  <Link href="/settings/security" prefetch={false} className={linkClass}>
                    {chunks}
                  </Link>
                ) : (
                  chunks
                ),
            })}
          </Note>
        ) : null}

        {confirm ? (
          <div className="space-y-3">
            <Caution size="md">
              <p>{t("otherApps", { username, count: owner.other_apps })}</p>
            </Caution>
            <FormField
              control={form.control}
              name="confirm_container_only"
              render={({ field }) => (
                <FormItem className="flex items-start gap-3">
                  <FormControl>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={(value) => field.onChange(value === true)}
                      className="mt-0.5"
                    />
                  </FormControl>
                  <div className="space-y-1">
                    <FormLabel className="font-normal">{t("confirm", { username })}</FormLabel>
                    <FormMessage />
                  </div>
                </FormItem>
              )}
            />
          </div>
        ) : null}

        {rootError ? (
          <Caution tone="destructive" size="md">
            <p role="alert">{rootError}</p>
          </Caution>
        ) : null}
      </FormModal>
    </Form>
  );
}

function Commands({ access, canSeeSystemUsers }) {
  const t = useTranslations("applications.containerAccess.commands");
  const [container, setContainer] = useState("");
  const { containers, commands, owner } = access;
  const selected = containers.find((item) => item.name === container) ?? defaultContainer(containers);

  return (
    <div className="space-y-4">
      <CommandLine
        label={t("shell")}
        command={shellCommandFor(access, selected)}
        testId="container-access-shell"
        aside={
          containers.length > 1 ? (
            <div className="flex items-center gap-2">
              <Label htmlFor="container-access-container" className="text-xs text-muted-foreground">
                {t("container")}
              </Label>
              <Select value={selected?.name ?? ""} onValueChange={setContainer}>
                <SelectTrigger id="container-access-container" size="sm" className="min-w-36">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {containers.map((item) => (
                    <SelectItem key={item.name} value={item.name}>
                      {item.service}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          ) : null
        }
      />

      {/* The app's own commands stay open: a recipe's first sign-in can depend on one. */}
      {access.cli_examples.length > 0 ? (
        <section className="space-y-3" aria-labelledby="container-access-app-commands">
          <h3 id="container-access-app-commands" className="text-sm font-medium">
            {t("appCommands")}
          </h3>
          {access.cli_examples.map((example) => (
            <CommandLine
              key={`${example.label}\n${example.command}`}
              label={example.label}
              command={example.command}
              testId="container-access-example"
              quiet
            />
          ))}
        </section>
      ) : null}

      <Collapsible className="border-t pt-3">
        <CollapsibleTrigger asChild>
          <Button type="button" variant="ghost" size="sm" className="group -ms-2 text-muted-foreground">
            <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
            {t("more")}
          </Button>
        </CollapsibleTrigger>
        <CollapsibleContent className="space-y-4 pt-3">
          <CommandLine label={t("exec")} command={commands.exec_example} testId="container-access-exec" />
          <CommandLine label={t("logs")} command={commands.logs} testId="container-access-logs" />
          <p className="text-xs leading-5 text-muted-foreground">
            {t.rich("note", {
              username: owner.username,
              link: (chunks) =>
                canSeeSystemUsers ? (
                  <Link href="/system-users" prefetch={false} className={linkClass}>
                    {chunks}
                  </Link>
                ) : (
                  chunks
                ),
            })}
          </p>
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
}

function CommandLine({ label, command, testId, aside = null, quiet = false }) {
  const t = useTranslations("applications.containerAccess");
  const tc = useTranslations("common");

  async function copy() {
    try {
      await navigator.clipboard.writeText(command);
      toast.success(t("copied"));
    } catch {
      toast.error(tc("copyFailed"));
    }
  }

  return (
    <div className="min-w-0 space-y-1.5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className={quiet ? "text-sm text-muted-foreground" : "text-sm font-medium"}>{label}</p>
        {aside}
      </div>
      {/* The line and its Copy in one well, like a database's connection string. Wraps
          instead of scrolling so the whole line is visible at 390px. */}
      <div className="flex items-start gap-2 rounded-lg border bg-muted/40 py-1.5 pr-1.5 pl-3">
        <pre
          data-testid={testId}
          className="min-w-0 flex-1 py-1.5 font-mono text-xs leading-5 wrap-anywhere whitespace-pre-wrap"
        >
          {command}
        </pre>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={copy}
          className="shrink-0"
          aria-label={t("copyLabel", { label })}
        >
          <Copy className="size-4" />
          <span className="max-sm:sr-only">{tc("copy")}</span>
        </Button>
      </div>
    </div>
  );
}
