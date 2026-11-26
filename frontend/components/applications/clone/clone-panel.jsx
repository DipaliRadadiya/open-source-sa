"use client";

import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRouter } from "next/navigation";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { ArrowRight, Check, Copy, Loader2, Lock, TriangleAlert, X, ListChecks, CopyCheck } from "lucide-react";
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
import { HoverHint } from "@/components/ui/hover-hint";

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

  const remembered = useRememberedClone(application.id);

  const blocked = cloneBlockedReason(application, siteType);
  const suggestion = suggestCloneDomain(application.domain, takenDomains);
  const defaultName = defaultCloneName(application.name, takenNames);

  const form = useForm({
    resolver: zodResolver(cloneFormSchema),
    // onTouched: submit stays disabled until valid, so an onSubmit message would never show.
    mode: "onTouched",
    reValidateMode: "onChange",
    defaultValues: { name: "", domain: canManage ? suggestion : "" },
  });

  const domain = useWatch({ control: form.control, name: "domain" });
  const name = useWatch({ control: form.control, name: "name" });

  const domainTaken =
    domain &&
    takenDomains.some(
      (value) => String(value).toLowerCase() === domain.trim().toLowerCase(),
    );

  const domainValid = cloneFormSchema.shape.domain.safeParse(
    domain ?? "",
  ).success;
  const ready = domainValid && !domainTaken;

  const target = ready ? domain.trim().toLowerCase() : "";

  useEffect(() => {
    if (!remembered || clone) return undefined;

    let live = true;
    fetchClone(remembered)
      .then((response) => {
        const found = response.data?.clone;
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
    form.reset({ name: "", domain: canManage ? suggestion : "" });
  }

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

  // Never show the form while a remembered clone is loading; it would invite a duplicate.
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
    <DisabledReasonProvider denied={!canManage}>
      <div className="space-y-6">
        <div className="space-y-6">
          <div className="min-w-0">
            <PanelCard>
              <CardHeader className="border-b">
                <CardTitle icon={Copy} as="h2">{t("create.title")}</CardTitle>
              </CardHeader>

              <Form {...form}>
                <form noValidate
                  onSubmit={form.handleSubmit(() => setConfirming(true))}
                  className="flex flex-col gap-(--card-spacing)"
                >
                  <CardContent className="space-y-5">
                    <div className="@container min-w-0 space-y-5">
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
                                t("form.domainPlaceholder")
                              }
                              className="font-mono"
                              disabled={!canManage || starting}
                            />
                          </FormControl>

                          {canManage && suggestion && !field.value ? (
                            <p className="flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                              {t("form.suggested")}
                              <button
                                type="button"
                                onClick={() => {
                                  form.setValue("domain", suggestion, {
                                    shouldDirty: true,
                                  });
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
                    <BeforeNotes sourceProtected={application.basic_auth_enabled} />
                  </CardContent>

                  {canManage ? (
                  <CardFooter className="flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
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
                  ) : null}
                </form>
              </Form>
            </PanelCard>
          </div>

          <ImpactCard
            siteType={siteType}
            application={application}
            sourceProtected={application.basic_auth_enabled}
          />

        </div>

        {copies.length ? <ExistingCopies copies={copies} /> : null}

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
              <HoverHint content={application.name}><dd
                className="min-w-0 truncate font-medium"
              >
                {application.name}
              </dd></HoverHint>
            </div>
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-muted-foreground">{t("confirm.domain")}</dt>
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

function PanelCard({ className, children }) {
  return (
    <Card
      className={cn("[--card-spacing:--spacing(5)]", PANEL_CARD, className)}
    >
      {children}
    </Card>
  );
}

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

function ImpactCard({ siteType, application, sourceProtected }) {
  const t = useTranslations("applications.clone.what");

  return (
    <PanelCard>
      <CardHeader className="border-b">
        <CardTitle icon={ListChecks} as="h2">{t("title")}</CardTitle>
      </CardHeader>
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
  ];

  return (
    <div className="h-fit rounded-xl border bg-muted/30 p-4">
      <p className="text-sm font-semibold">{t("before.title")}</p>
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

function ExistingCopies({ copies }) {
  const t = useTranslations("applications.clone.copies");

  return (
    <PanelCard>
      <CardHeader className="items-center border-b">
        <CardTitle icon={CopyCheck} as="h2">{t("title", { count: copies.length })}</CardTitle>
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
