"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Copy, Loader2, Power, UserRound } from "lucide-react";
import Link from "@/components/ui/app-link";
import {
  disableContainerAccess,
  enableContainerAccess,
  getContainerAccess,
} from "@/lib/api/docker";
import { containerAccessSchema, containerLoginSchema } from "@/lib/schemas/docker";
import {
  defaultContainer,
  shellCommandFor,
  showsContainerAccess,
  turnOnNeeds,
} from "@/lib/applications/container-access";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { PasswordInput } from "@/components/ui/password-input";
import { Skeleton } from "@/components/ui/skeleton";
import { SkForm } from "@/components/ui/skeleton-kit";
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
 * login opens this app's container, with no sudo. Off, the card is one step to
 * turn it on; on, it is the lines to copy.
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

  if (!enabled || failed) return null;

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

  // The "on" state's one action, so it sits in the header like the other cards' buttons.
  async function turnOff() {
    setOffPending(true);
    try {
      const response = await disableContainerAccess(application.id);
      toast.success(t("commands.turnedOff", { username: access.owner.username }));
      await settle(response);
    } catch (error) {
      toast.error(apiMessage(error, t("commands.turnOffFailed")));
    } finally {
      setOffPending(false);
    }
  }

  const username = access?.owner.username;
  const loginOn = Boolean(access?.owner.container_login);
  const systemUserHref = username
    ? `/system-users?search=${encodeURIComponent(username)}`
    : "/system-users";

  return (
    <Card className={className} aria-labelledby="container-access-heading">
      {/* The application page's card head: plain title, its line, the action on the right. */}
      <CardHeader className="border-b">
        <CardTitle id="container-access-heading" as="h2">
          {t("title")}
        </CardTitle>
        <CardDescription>{t("description")}</CardDescription>
        {loginOn && canManageSystemUsers ? (
          <CardAction className="self-center">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={turnOff}
              disabled={offPending}
            >
              {offPending ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
              {t("commands.turnOff")}
            </Button>
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent>
        {!access ? (
          // Shaped like what loads: the owner line, then labels over wells in two columns.
          <div className="space-y-5" aria-busy="true" aria-label={t("loading")}>
            <Skeleton className="h-4 w-56" />
            <SkForm fields={2} />
          </div>
        ) : (
          <div className="space-y-5">
            <p className="flex items-center gap-2 text-sm" data-testid="container-access-owner">
              <UserRound className="size-4 shrink-0 text-muted-foreground" />
              <span className="min-w-0 wrap-anywhere">
                {t.rich("owner", {
                  username,
                  user: (chunks) =>
                    canSeeSystemUsers ? (
                      <Link href={systemUserHref} prefetch={false} className={linkClass}>
                        {chunks}
                      </Link>
                    ) : (
                      <strong className="font-medium">{chunks}</strong>
                    ),
                })}
              </span>
            </p>

            {loginOn ? (
              <Commands access={access} canSeeSystemUsers={canSeeSystemUsers} />
            ) : canManageSystemUsers ? (
              <TurnOn
                access={access}
                applicationId={application.id}
                canOpenSecurity={canOpenSecurity}
                onEnabled={settle}
              />
            ) : (
              <p className="text-sm text-muted-foreground" data-testid="container-access-read-only">
                {t("readOnly", { username })}
              </p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function TurnOn({ access, applicationId, canOpenSecurity, onEnabled }) {
  const t = useTranslations("applications.containerAccess.turnOn");
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
      <form
        noValidate
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        // Flat under a hairline, not a box inside the card.
        className="space-y-4 border-t pt-5"
        aria-labelledby="container-login-heading"
      >
        <div className="space-y-1">
          <h3 id="container-login-heading" className="text-sm font-medium">
            {t("title", { username })}
          </h3>
          <p className="text-sm text-muted-foreground">{t("body", { username })}</p>
        </div>

        {askKey ? (
          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              control={form.control}
              name="ssh_key"
              render={({ field }) => (
                <FormItem className={askPassword ? undefined : "md:col-span-2"}>
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
          </div>
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

        <Button type="submit" disabled={submitting}>
          {submitting ? <Loader2 className="size-4 animate-spin" /> : <Power className="size-4" />}
          {t("submit")}
        </Button>
      </form>
    </Form>
  );
}

function Commands({ access, canSeeSystemUsers }) {
  const t = useTranslations("applications.containerAccess.commands");
  const [container, setContainer] = useState("");
  const { containers, commands, owner } = access;
  const selected = containers.find((item) => item.name === container) ?? defaultContainer(containers);

  return (
    <div className="space-y-5">
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
      <div className="grid gap-5 md:grid-cols-2">
        <CommandLine label={t("exec")} command={commands.exec_example} testId="container-access-exec" />
        <CommandLine label={t("logs")} command={commands.logs} testId="container-access-logs" />
      </div>

      {access.cli_examples.length > 0 ? (
        <section className="space-y-3 border-t pt-5" aria-labelledby="container-access-app-commands">
          <h3 id="container-access-app-commands" className="text-sm font-medium">
            {t("appCommands")}
          </h3>
          <div className="grid gap-4 md:grid-cols-2">
            {access.cli_examples.map((example) => (
              <CommandLine
                key={`${example.label}\n${example.command}`}
                label={example.label}
                command={example.command}
                testId="container-access-example"
                quiet
              />
            ))}
          </div>
        </section>
      ) : null}

      {/* A footnote under a hairline; Turn off is in the card's header. */}
      <p className="border-t pt-4 text-xs leading-5 text-muted-foreground">
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
