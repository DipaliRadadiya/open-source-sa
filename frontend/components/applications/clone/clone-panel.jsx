"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
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
import {
  CLONE_DROPS,
  CLONE_IN_FLIGHT,
  cloneBlockedReason,
  cloneCarries,
  cloneFormSchema,
  suggestCloneDomain,
} from "@/lib/schemas/clone";
import { createClone, fetchClone } from "@/lib/api/clone";
import { forgetClone, rememberClone, useRememberedClone } from "@/lib/clone/in-flight";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { CloneProgress, CloneNextSteps } from "@/components/applications/clone/clone-progress";

/**
 * Duplicate this site to a new domain.
 *
 * An action screen, not a guide. What a clone is gets stated in facts — two
 * short columns and a short pre-flight list — because the reader came here to
 * make one, not to learn about them.
 *
 * The form is the page: it sits in its own column with the source→target
 * summary and the button in one band, so the thing being decided and the
 * control that commits it are never more than a glance apart. The earlier cut
 * stacked four full-width strips down a 1180px page, which left the form
 * looking like one more paragraph of a document.
 *
 * The omissions still get their own column: a list of what carries across with
 * no matching list of what does not reads as "everything", and one of the
 * omissions — password protection — is a security surprise.
 */
export function CloneApplicationPanel({
  application,
  siteType,
  copies = [],
  takenDomains = [],
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
  const defaultName = t("form.defaultName", { name: application.name });

  const form = useForm({
    resolver: zodResolver(cloneFormSchema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: { name: "", domain: "" },
  });

  const domain = useWatch({ control: form.control, name: "domain" });
  const name = useWatch({ control: form.control, name: "name" });

  // Checked as they type against sites already on this server. The API would
  // refuse it anyway, but finding out before you commit beats finding out
  // after — and the list is already loaded for the copies section below.
  const domainTaken =
    domain && takenDomains.some((value) => String(value).toLowerCase() === domain.trim().toLowerCase());

  // The submit stays disabled until the domain would actually pass, judged by
  // the same rule the API uses rather than by "is there any text here" —
  // offering a button that is going to come back 422 is a worse answer than
  // withholding it.
  const domainValid = cloneFormSchema.shape.domain.safeParse(domain ?? "").success;
  const ready = domainValid && !domainTaken;

  // Only a domain that could actually be created is shown as the destination:
  // echoing back one this server has already refused would be the screen
  // agreeing with a plan it is about to reject.
  const target = ready ? domain.trim().toLowerCase() : "";

  // Pick a running clone back up after a reload or a navigation away.
  useEffect(() => {
    if (!remembered || clone) return undefined;

    let live = true;
    fetchClone(remembered)
      .then((response) => {
        const found = response.data?.clone;
        // A finished clone is still shown once — whoever started it may never
        // have seen it land — and forgotten immediately, so the next visit to
        // this page is a fresh form rather than yesterday's result.
        if (!found || !CLONE_IN_FLIGHT.includes(found.status)) forgetClone(application.id);
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

  function settled(next) {
    setFinished(next);
    forgetClone(application.id);
  }

  // Remembered but not yet fetched: never the form, which would invite a
  // second clone of a site that is already being copied.
  if (remembered && !clone) return <Resuming />;

  if (clone) {
    return (
      <div className="space-y-6">
        <CloneProgress clone={clone} onDone={settled} onAgain={reset} />
        {finished?.status === "completed" && finished.target_application_id ? (
          <CloneNextSteps
            applicationId={finished.target_application_id}
            sourceProtected={application.basic_auth_enabled}
          />
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
    <div className="space-y-6">
      {/* Two cards of near-equal weight, left to stretch to each other rather
          than sit at their natural heights — the rail used to run 270px past
          the form and read as a column someone forgot to finish. The
          pre-flight list moved out to its own full-width band below for the
          same reason. */}
      <div className="grid gap-6 lg:grid-cols-12">
        <Card className="gap-0 overflow-hidden py-0 shadow-sm lg:col-span-7">
          <div className="flex items-start gap-3 border-b px-5 py-4">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Copy className="size-5" />
            </span>
            <div className="min-w-0">
              <h2 className="font-semibold tracking-tight">{t("create.title")}</h2>
              <p className="text-sm text-muted-foreground">
                {t("create.subtitle", { name: application.name })}
              </p>
            </div>
          </div>

          <Form {...form}>
            <form onSubmit={form.handleSubmit(() => setConfirming(true))}>
              <div className="space-y-5 px-5 py-6">
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
                          placeholder={suggestion || t("form.domainPlaceholder")}
                          className="font-mono"
                          disabled={!canManage || starting}
                        />
                      </FormControl>

                      {/* A chip, not a sentence: it is a value to take, and one
                          tap is the whole interaction. Skips domains already in
                          use, so it never offers a rejection. */}
                      {suggestion && !field.value ? (
                        <button
                          type="button"
                          onClick={() => form.setValue("domain", suggestion, { shouldDirty: true })}
                          className="inline-flex w-fit items-center gap-1.5 rounded-full border bg-muted/60 px-2.5 py-1 font-mono text-xs transition-colors hover:bg-muted"
                        >
                          <Copy className="size-3 shrink-0 text-muted-foreground" />
                          {suggestion}
                        </button>
                      ) : null}

                      {domainTaken ? (
                        <p className="text-sm text-destructive">{t("form.domainTaken")}</p>
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

              {/* What is about to happen, in the same band as the control that
                  does it: source on the left, the copy's domain filling in as
                  it is typed. */}
              <div className="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                {canManage ? (
                  <p className="flex min-w-0 items-center gap-2 text-sm">
                    <span className="truncate font-medium">{application.name}</span>
                    <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    {target ? (
                      <span className="truncate font-mono font-medium">{target}</span>
                    ) : (
                      <span className="rounded-md border border-dashed px-2 py-0.5 font-mono text-xs text-muted-foreground">
                        {t("create.placeholder")}
                      </span>
                    )}
                  </p>
                ) : (
                  <p className="text-sm text-muted-foreground">{t("form.noPermission")}</p>
                )}

                <Button
                  type="submit"
                  disabled={!canManage || starting || !ready}
                  className="w-full sm:w-auto sm:min-w-40"
                >
                  {starting ? <Loader2 className="size-4 animate-spin" /> : <Copy className="size-4" />}
                  {t("form.submit")}
                </Button>
              </div>
            </form>
          </Form>
        </Card>

        <ImpactCard siteType={siteType} className="lg:col-span-5" />
      </div>

      <BeforeCard sourceProtected={application.basic_auth_enabled} />

      {copies.length ? <ExistingCopies copies={copies} /> : null}

      {/* Asked once, at the moment of commitment, carrying the three facts that
          decide it: which site is being copied, what the copy will answer to,
          and that the original is untouched. */}
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
            <dd className="min-w-0 truncate font-medium">{application.name}</dd>
          </div>
          <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{t("confirm.domain")}</dt>
            <dd className="min-w-0 truncate font-mono font-medium">{target}</dd>
          </div>
          <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{t("confirm.name")}</dt>
            <dd className="min-w-0 truncate font-medium">{name?.trim() || defaultName}</dd>
          </div>
        </dl>
      </ConfirmDialog>
    </div>
  );
}

/**
 * Shown for the moment it takes to look up a clone this browser remembers.
 *
 * Rendering the empty form here instead would invite someone to start a second
 * clone of a site that is already being copied.
 */
function Resuming() {
  const t = useTranslations("applications.clone.progress");

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <CardContent className="flex items-center gap-3 px-5 py-5">
        <Loader2 className="size-5 shrink-0 animate-spin text-muted-foreground" aria-hidden />
        <p className="text-sm text-muted-foreground">{t("resuming")}</p>
      </CardContent>
    </Card>
  );
}

/** Will copy / Will not copy, as two short lists rather than a tag cloud. */
function ImpactCard({ siteType, className }) {
  const t = useTranslations("applications.clone.what");

  return (
    <Card className={cn("gap-0 overflow-hidden py-0 shadow-sm", className)}>
      <div className="border-b px-5 py-3.5">
        <h2 className="text-sm font-semibold tracking-tight">{t("title")}</h2>
      </div>
      <CardContent className="divide-y p-0">
        <ImpactList
          ok
          icon={Check}
          title={t("carries")}
          items={cloneCarries(siteType).map((key) => t(`carriesItems.${key}`))}
        />
        <ImpactList
          icon={X}
          title={t("drops")}
          items={CLONE_DROPS.map((key) => t(`dropsItems.${key}`))}
        />
      </CardContent>
    </Card>
  );
}

function ImpactList({ ok = false, icon: Icon, title, items }) {
  return (
    <div className="px-5 py-4">
      <p className="flex items-center gap-1.5 text-sm font-semibold">
        <Icon className={cn("size-4", ok ? "text-success" : "text-muted-foreground")} />
        {title}
      </p>
      <ul
        className={cn(
          "mt-2.5 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm",
          ok ? "text-foreground" : "text-muted-foreground",
        )}
      >
        {items.map((item) => (
          <li key={item} className="truncate">
            {item}
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * The pre-flight list.
 *
 * One card rather than a stack of amber banners: two full-width warning bars
 * shouted equally loudly and the second one stopped being read. The tone lives
 * in the icon, and the list only carries things that change what someone does
 * next — DNS before a certificate, protection that does not come across, and
 * how long this takes.
 *
 * Sits across the foot of the page as columns, which is what lets the form and
 * the copied/not-copied card above it be the same height.
 */
function BeforeCard({ sourceProtected }) {
  const t = useTranslations("applications.clone");

  const items = [
    { key: "dns", icon: TriangleAlert, tone: "text-warning" },
    ...(sourceProtected ? [{ key: "password", icon: Lock, tone: "text-warning" }] : []),
    { key: "time", icon: Clock, tone: "text-muted-foreground" },
  ];

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="border-b px-5 py-3.5">
        <h2 className="text-sm font-semibold tracking-tight">{t("before.title")}</h2>
      </div>
      <CardContent className="p-0">
        <ul
          className={cn(
            "grid divide-y sm:divide-x sm:divide-y-0",
            items.length === 3 ? "sm:grid-cols-3" : "sm:grid-cols-2",
          )}
        >
          {items.map(({ key, icon: Icon, tone }) => (
            <li key={key} className="flex items-start gap-2.5 px-5 py-3.5 text-sm">
              <Icon className={cn("mt-0.5 size-4 shrink-0", tone)} aria-hidden />
              <span>{t(`warnings.${key}`)}</span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

/**
 * Why the form is not here.
 *
 * Shown instead of it, never as a disabled submit: being told after typing a
 * domain that this site was never cloneable is the worst order to learn it in.
 */
function Blocked({ reason, siteType }) {
  const t = useTranslations("applications.clone");

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <CardContent className="px-5 py-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/15">
            <TriangleAlert className="size-6 text-warning" />
          </span>
          <div className="min-w-0 space-y-1">
            <p className="font-medium">{t(`blocked.${reason}.title`)}</p>
            <p className="text-sm text-muted-foreground">
              {reason === "noRecipe"
                ? t("blocked.noRecipe.body", { type: siteType?.title ?? "" })
                : t(`blocked.${reason}.body`)}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * Copies already made from this site.
 *
 * No endpoint needed — every application carries
 * `cloned_from_application_id`, so the answer is already in the applications
 * list. Without this the page had no memory: you could clone the same site
 * twice over and the screen would look identical both times.
 *
 * The date earns its place because names are not unique.
 */
function ExistingCopies({ copies }) {
  const t = useTranslations("applications.clone.copies");

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="border-b px-5 py-3.5">
        <h2 className="text-sm font-semibold tracking-tight">
          {t("title", { count: copies.length })}
        </h2>
      </div>

      <CardContent className="p-0">
        <ul className="divide-y">
          {copies.map((copy) => (
            <li key={copy.id}>
              <Link
                href={`/applications/${copy.id}`}
                className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{copy.name}</p>
                  <p className="truncate font-mono text-xs text-muted-foreground">{copy.domain}</p>
                </div>
                {copy.created_at_human ? (
                  <span className="hidden shrink-0 text-xs tabular-nums text-muted-foreground sm:inline">
                    {t("made", { when: copy.created_at_human })}
                  </span>
                ) : null}
                {copy.status !== "active" ? (
                  <Badge
                    variant={copy.status === "failed" ? "destructive" : "outline"}
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
    </Card>
  );
}
