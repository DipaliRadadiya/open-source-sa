"use client";

import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRouter } from "next/navigation";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import {
  ArrowRight,
  Check,
  Clock,
  Copy,
  Loader2,
  Lock,
  TriangleAlert,
  X,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import {
  CLONE_IN_FLIGHT,
  cloneBlockedReason,
  cloneCarries,
  cloneDrops,
  cloneFormSchema,
  defaultCloneName,
  suggestCloneDomain,
} from "@/lib/schemas/clone";
import { createClone, fetchClone } from "@/lib/api/clone";
import {
  forgetClone,
  rememberClone,
  useRememberedClone,
} from "@/lib/clone/in-flight";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { DomainText } from "@/components/ui/domain-text";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import {
  CloneProgress,
  CloneNextSteps,
} from "@/components/applications/clone/clone-progress";

export function CloneApplicationPanel({
  application,
  siteType,
  copies = [],
  takenDomains = [],
  takenNames = [],
  canManage,
}) {
  const t = useTranslations("applications.clone");
  const router = useRouter();
  const [starting, setStarting] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [clone, setClone] = useState(null);
  const [finished, setFinished] = useState(null);

  // A clone this browser started for this site and has not seen finish.
  const remembered = useRememberedClone(application.id);

  const blocked = cloneBlockedReason(application, siteType);
  const suggestion = suggestCloneDomain(application.domain, takenDomains);
  const defaultName = defaultCloneName(application.name, takenNames);

  const form = useForm({
    resolver: zodResolver(cloneFormSchema),
    // onTouched, not onSubmit: submit stays disabled until the domain is valid,
    // so an on-submit message would never appear.
    mode: "onTouched",
    reValidateMode: "onChange",
    defaultValues: { name: "", domain: "" },
  });

  const domain = useWatch({ control: form.control, name: "domain" });
  const name = useWatch({ control: form.control, name: "name" });

  // Checked as they type against this server's sites; the API would refuse it anyway.
  const domainTaken =
    domain &&
    takenDomains.some(
      (value) => String(value).toLowerCase() === domain.trim().toLowerCase(),
    );

  // Uses the same rule as the API, so the button is never offered for a 422.
  const domainValid = cloneFormSchema.shape.domain.safeParse(
    domain ?? "",
  ).success;
  const ready = domainValid && !domainTaken;

  // Only a domain that could actually be created is shown as the destination.
  const target = ready ? domain.trim().toLowerCase() : "";

  // Pick a running clone back up after a reload or a navigation away.
  useEffect(() => {
    if (!remembered || clone) return undefined;

    let live = true;
    fetchClone(remembered)
      .then((response) => {
        const found = response.data?.clone;
        // A finished clone is shown once, then forgotten so the next visit is a fresh form.
        if (!found || !CLONE_IN_FLIGHT.includes(found.status))
          forgetClone(application.id);
        if (live && found) setClone(found);
      })
      .catch(() => forgetClone(application.id));

    return () => {
      live = false;
    };
  }, [application.id, remembered, clone]);

  async function start() {
    setConfirming(false);
    setStarting(true);
    try {
      const values = form.getValues();
      const response = await createClone(application.id, {
        domain: values.domain,
        // Omitted rather than sent empty, so the backend applies its own
        // "{source} (Clone)" default instead of storing a blank name.
        ...(values.name?.trim() ? { name: values.name.trim() } : null),
      });
      const started = response.data?.clone ?? null;
      setClone(started);
      rememberClone(application.id, started?.id);
      router.refresh();
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
      } else {
        toast.error(apiMessage(error, t("failed")));
      }
    } finally {
      setStarting(false);
    }
  }

  function reset() {
    setClone(null);
    setFinished(null);
    forgetClone(application.id);
    form.reset({ name: "", domain: "" });
  }

  // Keep the failed domain so it can be retried without retyping.
  function retry(failed) {
    setClone(null);
    setFinished(null);
    forgetClone(application.id);
    form.reset({ name: failed?.name ?? "", domain: failed?.domain ?? "" });
  }

  function settled(next) {
    setFinished(next);
    forgetClone(application.id);
  }

  // Remembered but not yet fetched: never the form, which would invite a
  // second clone of a site that is already being copied.
  if (remembered && !clone) return <Resuming />;

  if (clone) {
    const completedClone =
      finished?.status === "completed"
        ? finished
        : clone.status === "completed"
          ? clone
          : null;
    const hasNextSteps = Boolean(completedClone?.target_application_id);

    return (
      <div
        className={cn(
          completedClone
            ? "grid gap-6 lg:grid-cols-12 lg:items-stretch"
            : "space-y-6",
        )}
      >
        <div
          className={cn(
            completedClone &&
              (hasNextSteps ? "lg:col-span-7" : "lg:col-span-12"),
          )}
        >
          <CloneProgress
            clone={clone}
            sourceApplication={application}
            onDone={settled}
            onAgain={reset}
            onRetry={retry}
          />
        </div>
        {hasNextSteps ? (
          <div className="min-w-0 lg:col-span-5">
            <CloneNextSteps
              applicationId={completedClone.target_application_id}
              sourceProtected={application.basic_auth_enabled}
              sourceHasRepository={Boolean(application.repository)}
              webhook={completedClone.target_webhook}
            />
          </div>
        ) : null}
      </div>
    );
  }

  if (blocked) {
    return (
      <div className="space-y-6">
        <Blocked reason={blocked} siteType={siteType} />
        {copies.length ? <ExistingCopies copies={copies} /> : null}
      </div>
    );
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="space-y-6">
        {/* One form card (fields left, what to do first right), then what gets copied in
            two columns. Two tall side-by-side cards left one half mostly empty (7 Oct). */}
        <div className="space-y-6">
          <div className="min-w-0">
            <PanelCard>
              {/* Same card shape as the rest of the panel: title and one line, then a rule.
                  No icon chips on titles (7 Oct). */}
              <CardHeader className="border-b">
                <CardTitle as="h2">{t("create.title")}</CardTitle>
                <CardDescription>
                  {t("create.subtitle", { name: application.name })}
                </CardDescription>
              </CardHeader>

              <Form {...form}>
                <form noValidate
                  onSubmit={form.handleSubmit(() => setConfirming(true))}
                  className="flex flex-col gap-(--card-spacing)"
                >
                  <CardContent className="space-y-5">
                    <div className="@container min-w-0 space-y-5">
                    {/* Side by side: one under the other left the right half of each field
                        empty (Krishna, 7 Oct). Equal widths: an uneven split read as a mistake. */}
                    <div className="grid gap-5 @lg:grid-cols-2 @lg:items-start">
                    <FormField
                      control={form.control}
                      name="domain"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel required>{t("form.domain")}</FormLabel>
                          <FormControl>
                            <Input
                              {...field}
                              autoComplete="off"
                              spellCheck={false}
                              placeholder={
                                suggestion || t("form.domainPlaceholder")
                              }
                              className="font-mono"
                              disabled={!canManage || starting}
                            />
                          </FormControl>

                          {/* A one-click suggestion, as a line under the field (a chip
                              repeated the placeholder above it). Skips domains in use. */}
                          {canManage && suggestion && !field.value ? (
                            <p className="flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                              {t("form.suggested")}
                              <button
                                type="button"
                                onClick={() => {
                                  form.setValue("domain", suggestion, {
                                    shouldDirty: true,
                                  });
                                  // The line unmounts once the field has a value,
                                  // which would drop keyboard focus.
                                  form.setFocus("domain");
                                }}
                                className="min-w-0 rounded font-mono font-medium break-all text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                              >
                                {suggestion}
                              </button>
                            </p>
                          ) : null}

                          {domainTaken ? (
                            <p className="text-sm text-destructive">
                              {t("form.domainTaken")}
                            </p>
                          ) : null}
                          <FormMessage />
                        </FormItem>
                      )}
                    />

                    <FormField
                      control={form.control}
                      name="name"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>{t("form.name")}</FormLabel>
                          <FormControl>
                            <Input
                              {...field}
                              autoComplete="off"
                              placeholder={defaultName}
                              disabled={!canManage || starting}
                            />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    </div>
                    </div>
                    {/* What to do first, full width under the fields: beside them it left empty
                        space under the fields at 1440 and squeezed them at 1024 (7 Oct). */}
                    <BeforeNotes sourceProtected={application.basic_auth_enabled} />
                  </CardContent>

                  {/* Just the button: a source → copy line beside it cut both domains short,
                      and the field above and the confirm dialog already show the copy's domain. */}
                  <CardFooter className="flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
                    {canManage ? null : (
                      <p className="text-sm text-muted-foreground sm:mr-auto">
                        {t("form.noPermission")}
                      </p>
                    )}

                    <Button
                      type="submit"
                      disabled={!canManage || starting || !ready}
                      className="w-full shrink-0 sm:w-auto sm:min-w-40"
                    >
                      {starting ? (
                        <Loader2 className="size-4 animate-spin" />
                      ) : (
                        <Copy className="size-4" />
                      )}
                      {t("form.submit")}
                    </Button>
                  </CardFooter>
                </form>
              </Form>
            </PanelCard>
          </div>

          {/* Its own card again: inside the form card it made one crowded block (7 Oct). */}
          <ImpactCard
            siteType={siteType}
            application={application}
            sourceProtected={application.basic_auth_enabled}
          />

        </div>

        {copies.length ? <ExistingCopies copies={copies} /> : null}

        {/* Confirms the source, the copy's domain, and that the original is untouched. */}
        <ConfirmDialog
          open={confirming}
          onOpenChange={setConfirming}
          icon={Copy}
          title={t("confirm.title", { name: application.name })}
          description={t("confirm.body")}
          cancelLabel={t("confirm.cancel")}
          confirmLabel={t("form.submit")}
          pending={starting}
          onConfirm={start}
        >
          <dl className="space-y-2 rounded-lg border bg-muted/40 p-3 text-sm">
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-muted-foreground">{t("confirm.source")}</dt>
              <dd
                className="min-w-0 truncate font-medium"
                title={application.name}
              >
                {application.name}
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-muted-foreground">{t("confirm.domain")}</dt>
              {/* Wraps: the one value being confirmed must never be cut off. */}
              <dd className="min-w-0 font-mono font-medium break-all">
                {target}
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-muted-foreground">{t("confirm.name")}</dt>
              <dd className="min-w-0 truncate font-medium">
                {name?.trim() || defaultName}
              </dd>
            </div>
          </dl>
        </ConfirmDialog>
      </div>
    </DisabledReasonProvider>
  );
}

/** Shared card shell for every card on this page, so they cannot drift apart. */
function PanelCard({ className, children }) {
  return (
    <Card
      className={cn("[--card-spacing:--spacing(5)]", PANEL_CARD, className)}
    >
      {children}
    </Card>
  );
}

// The empty form here would invite a second clone of a site already being copied.
function Resuming() {
  const t = useTranslations("applications.clone.progress");

  return (
    <PanelCard>
      <CardContent className="flex items-center gap-3">
        <Loader2
          className="size-5 shrink-0 animate-spin text-muted-foreground"
          aria-hidden
        />
        <p className="text-sm text-muted-foreground">{t("resuming")}</p>
      </CardContent>
    </PanelCard>
  );
}

// No `truncate` (it cut labels mid-word). Not-copied items use foreground text so they don't read as disabled.
function ImpactCard({ siteType, application, sourceProtected }) {
  const t = useTranslations("applications.clone.what");

  return (
    <PanelCard>
      <CardHeader className="border-b">
        <CardTitle as="h2">{t("title")}</CardTitle>
      </CardHeader>
      {/* Two lines, label then the items running across: as two columns of lists the
          card was mostly empty space (7 Oct). */}
      <CardContent className="@container space-y-3">
        <ImpactList
          ok
          title={t("carries")}
          items={cloneCarries(siteType, application).map((key) => ({
            key,
            label: t(`carriesItems.${key}`),
          }))}
        />
        <ImpactList
          title={t("drops")}
          items={cloneDrops(application).map((key) => ({
            key,
            label: t(`dropsItems.${key}`),
          }))}
          // Warn only when the source actually has protection on.
          warn={sourceProtected ? "passwordProtection" : null}
        />
      </CardContent>
    </PanelCard>
  );
}

function ImpactList({ ok = false, title, items, warn = null }) {
  return (
    <div className="flex flex-col gap-x-6 gap-y-2 @md:flex-row @md:items-baseline">
      <p className="shrink-0 text-xs font-semibold text-muted-foreground @md:w-32">
        {title}
      </p>
      <ul className="flex flex-wrap gap-x-5 gap-y-2">
        {items.map(({ key, label }) => {
          const warned = key === warn;
          return (
            <li key={key} className="flex items-center gap-1.5 text-sm">
              {ok ? (
                <Check className="size-4 shrink-0 text-success" aria-hidden />
              ) : (
                <X
                  className={cn(
                    "size-4 shrink-0",
                    warned ? "text-warning" : "text-muted-foreground",
                  )}
                  aria-hidden
                />
              )}
              <span className={cn(warned && "font-medium text-warning")}>
                {label}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function BeforeNotes({ sourceProtected }) {
  const t = useTranslations("applications.clone");

  const items = [
    { key: "dns", icon: TriangleAlert, tone: "warning" },
    ...(sourceProtected
      ? [{ key: "password", icon: Lock, tone: "warning" }]
      : []),
    { key: "time", icon: Clock, tone: "muted" },
  ];

  return (
    <div className="h-fit rounded-xl border bg-muted/30 p-4">
      <p className="text-sm font-semibold">{t("before.title")}</p>
      {/* One per line: as columns each sentence wrapped three times. */}
      <ul className="mt-3 space-y-3">
        {items.map(({ key, icon: Icon, tone }) => (
          <li key={key} className="flex items-start gap-2.5 text-sm">
            <span
              className={cn(
                "flex size-7 shrink-0 items-center justify-center rounded-lg",
                tone === "warning" ? "bg-warning/10 text-warning" : "bg-muted text-muted-foreground",
              )}
            >
              <Icon className="size-3.5" aria-hidden />
            </span>
            <span className="pt-0.5 text-muted-foreground">{t(`warnings.${key}`)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Why the site can't be cloned; replaces the form rather than disabling submit. */
function Blocked({ reason, siteType }) {
  const t = useTranslations("applications.clone");

  return (
    <PanelCard>
      <CardContent className="flex items-start gap-3.5">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/10 text-warning ring-1 ring-inset ring-warning/20">
          <TriangleAlert className="size-5.5" />
        </span>
        <div className="min-w-0 space-y-1">
          <p className="font-medium">{t(`blocked.${reason}.title`)}</p>
          <p className="text-sm text-muted-foreground">
            {reason === "noRecipe"
              ? t("blocked.noRecipe.body", { type: siteType?.title ?? "" })
              : t(`blocked.${reason}.body`)}
          </p>
        </div>
      </CardContent>
    </PanelCard>
  );
}

/** Shown to an administrator on a site type that has no clone feature. */
export function CloneTypeNotSupported({ typeTitle }) {
  const t = useTranslations("applications.clone.blocked.typeNotSupported");

  return (
    <PanelCard>
      <CardContent className="flex items-start gap-3.5">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/10 text-warning ring-1 ring-inset ring-warning/20">
          <TriangleAlert className="size-5.5" />
        </span>
        <div className="min-w-0 space-y-1">
          <p className="font-medium">{t("title")}</p>
          <p className="text-sm text-muted-foreground">{t("body", { type: typeTitle })}</p>
        </div>
      </CardContent>
    </PanelCard>
  );
}

// From each application's `cloned_from_application_id`. Dated because names are not unique.
function ExistingCopies({ copies }) {
  const t = useTranslations("applications.clone.copies");

  return (
    <PanelCard>
      <CardHeader className="items-center border-b">
        <CardTitle as="h2">{t("title", { count: copies.length })}</CardTitle>
        <CardAction className="row-span-1 self-center">
          <RefreshButton className="size-8" />
        </CardAction>
      </CardHeader>

      <CardContent className="-mt-(--card-spacing) p-0!">
        <ul className="divide-y">
          {copies.map((copy) => (
            <li key={copy.id}>
              <Link
                href={`/applications/${copy.id}`}
                prefetch={false}
                className="flex items-center gap-3 px-(--card-spacing) py-3.5 transition-colors hover:bg-muted/40"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{copy.name}</p>
                  <DomainText
                    domain={copy.domain}
                    className="font-mono text-xs text-muted-foreground"
                  />
                </div>
                {copy.created_at_human ? (
                  <span className="hidden shrink-0 text-xs tabular-nums text-muted-foreground sm:inline">
                    {t("made", { when: copy.created_at_human })}
                  </span>
                ) : null}
                {copy.status !== "active" ? (
                  <Badge
                    variant={
                      copy.status === "failed" ? "destructive" : "muted"
                    }
                    className="shrink-0 font-normal"
                  >
                    {copy.status_title ?? copy.status}
                  </Badge>
                ) : null}
                <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
              </Link>
            </li>
          ))}
        </ul>
      </CardContent>
    </PanelCard>
  );
}
