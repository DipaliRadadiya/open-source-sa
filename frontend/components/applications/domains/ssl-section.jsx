"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { parseApiDate } from "@/lib/format/api-date";
import { toast } from "sonner";
import {
  AlertCircle,
  CheckCircle2,
  ShieldCheck,
  ShieldOff,
  ShieldAlert,
  Loader2,
  Trash2,
  RefreshCw,
  Lock,
  Globe,
  Clock3,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
  fetchCertificate,
  setForceHttps,
  deleteCertificate,
} from "@/lib/api/domains";
import { runServiceAction } from "@/lib/api/services";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Caution } from "@/components/ui/caution";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { WrappedDomain } from "@/components/ui/domain-text";
import { IssueCertDialog } from "@/components/applications/domains/issue-cert-dialog";
import { VisitSiteLink } from "@/components/applications/visit-site-link";

const POLL_MS = 3000;
const POLL_LIMIT = (10 * 60 * 1000) / POLL_MS;
const isPending = (c) =>
  c && (c.status === "pending" || c.status === "issuing");
// Don't retry a rate limit: the wait is a week.
const NO_RETRY = new Set(["rate_limited"]);

const TONES = {
  success: { chip: "bg-success-soft text-success", title: "" },
  warning: { chip: "bg-warning-soft text-warning", title: "" },
  destructive: { chip: "bg-destructive-soft text-destructive", title: "text-destructive" },
  progress: { chip: "bg-primary/10 text-primary", title: "" },
  idle: { chip: "bg-muted text-muted-foreground", title: "" },
};

function Tile({ tone = "idle", icon: Icon, spin = false, title, badge, children }) {
  const { chip, title: titleTint } = TONES[tone] ?? TONES.idle;
  return (
    <div className="flex min-w-64 flex-1 items-start gap-3">
      <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-xl", chip)}>
        <Icon className={cn("size-5", spin && "animate-spin")} aria-hidden />
      </span>
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <h2 className={cn("text-[15px] leading-tight font-semibold tracking-tight", titleTint)}>{title}</h2>
          {badge}
        </div>
        {children}
      </div>
    </div>
  );
}

const Note = (props) => <Caution size="md" {...props} />;

export function SslSection({
  appId,
  initialCertificate,
  certifiable = true,
  availableTypes = [],
  canManage = false,
  webServer = null,
}) {
  const t = useTranslations("applications.domains");
  const format = useFormatter();
  const asDate = (value) => {
    const when = parseApiDate(value);
    return when ? format.dateTime(when, { day: "numeric", month: "long", year: "numeric" }) : null;
  };
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  const [cert, setCert] = useState(initialCertificate);
  const [certFrom, setCertFrom] = useState(initialCertificate);
  if (certFrom !== initialCertificate) {
    setCertFrom(initialCertificate);
    setCert(initialCertificate);
  }
  const [issueOpen, setIssueOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [savingHttps, setSavingHttps] = useState(false);
  const [reloading, setReloading] = useState(false);

  async function reloadWebServer() {
    setReloading(true);
    try {
      await runServiceAction(webServer, "reload");
      await refreshAndWait();
      toast.success(t("ssl.reloaded"));
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.reloadFailed")));
    } finally {
      setReloading(false);
    }
  }

  const polling = isPending(cert);

  useEffect(() => {
    if (!polling) return undefined;
    let live = true;
    let ticks = 0;
    const timer = setInterval(async () => {
      if (++ticks > POLL_LIMIT) {
        clearInterval(timer);
        return;
      }
      try {
        const next = await fetchCertificate(appId);
        if (live) {
          setCert(next);
          if (!isPending(next)) router.refresh();
        }
      } catch {
        // retry on the next poll
      }
    }, POLL_MS);
    return () => {
      live = false;
      clearInterval(timer);
    };
  }, [polling, appId, router]);

  async function onToggleForceHttps(next) {
    setBusy(true);
    setSavingHttps(true);
    try {
      const updated = await setForceHttps(appId, next);
      setCert(updated);
      toast.success(next ? t("ssl.forceHttpsEnabled") : t("ssl.forceHttpsDisabled"));
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.forceHttpsFailed")));
    } finally {
      setBusy(false);
      setSavingHttps(false);
    }
  }

  async function confirmDelete() {
    setBusy(true);
    try {
      await deleteCertificate(appId);
      setCert(null);
      await refreshAndWait();
      toast.success(t("ssl.removed"));
      setDeleteOpen(false);
    } catch (error) {
      if (error?.response?.status === 404) {
        toast.info(t("ssl.removedAlready"));
        setCert(null);
        setDeleteOpen(false);
        router.refresh();
        return;
      }
      toast.error(apiMessage(error, t("ssl.deleteFailed")));
    } finally {
      setBusy(false);
    }
  }

  function view() {
    if (!cert) {
      return {
        head: (
          <Tile icon={ShieldOff} title={t("ssl.none")}>
            <p className="max-w-prose text-sm text-muted-foreground">
              {certifiable ? t("ssl.noneBody") : t("ssl.notCertifiable")}
            </p>
          </Tile>
        ),
        actions:
          canManage && certifiable ? (
            <Button onClick={() => setIssueOpen(true)}>
              <Lock className="size-4" />
              {t("ssl.enable")}
            </Button>
          ) : null,
      };
    }

    if (isPending(cert)) {
      return {
        head: (
          <Tile tone="progress" icon={Loader2} spin title={t("ssl.issuing")}>
            <p className="max-w-prose text-sm text-muted-foreground">{t("ssl.issuingBody")}</p>
          </Tile>
        ),
        notes: canManage ? (
          <Note icon={Clock3}>
            <p>{t("ssl.issuingStuck")}</p>
          </Note>
        ) : null,
        actions: canManage ? (
          <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
            <Trash2 className="size-4" />
            {t("ssl.remove")}
          </Button>
        ) : null,
      };
    }

    if (cert.status === "failed") {
      const noRetry = NO_RETRY.has(cert.reason);
      const certMessage = apiMessage({ response: { data: { message: cert.message } } }, null);
      return {
        head: (
          <Tile tone="destructive" icon={ShieldAlert} title={t("ssl.failed")}>
              {certMessage ? <p className="max-w-prose text-sm">{certMessage}</p> : null}
              {cert.reference ? (
                <p className="font-mono text-xs text-muted-foreground">
                  {t("ssl.reference", { reference: cert.reference })}
                </p>
              ) : null}
          </Tile>
        ),
        notes: noRetry ? (
          <Note icon={Clock3}>
            <p>{t("ssl.rateLimited")}</p>
          </Note>
        ) : null,
        actions:
          canManage ? (
            <>
              <Button
                variant="ghost"
                className="[--destructive-ink:color-mix(in_oklch,var(--destructive),var(--foreground)_22%)] text-(--destructive-ink) hover:bg-destructive/10 hover:text-(--destructive-ink) dark:text-destructive dark:hover:text-destructive"
                onClick={() => setDeleteOpen(true)}
              >
                <Trash2 className="size-4" />
                {t("ssl.remove")}
              </Button>
              <Button onClick={() => setIssueOpen(true)}>
                <RefreshCw className="size-4" />
                {t("ssl.reissue")}
              </Button>
            </>
          ) : null,
      };
    }

    const expired = cert.expired;
    const servingStale = cert.serving_stale === true;

    const stale = new Set(cert.stale_domains ?? []);
    const names = [
      ...(cert.domains ?? [])
        .filter((domain) => !stale.has(domain))
        .map((domain) => ({ domain, state: "covered" })),
      ...(cert.missing_domains ?? []).map((domain) => ({ domain, state: "missing" })),
      ...(cert.stale_domains ?? []).map((domain) => ({ domain, state: "stale" })),
    ];
    // certbot fails the whole renewal if one name can't be validated.
    const hasCoverageGap = Boolean(cert.missing_domains?.length || cert.stale_domains?.length);
    const secured = names.filter((n) => n.state === "covered").length;
    const expiresOn = asDate(cert.expires_at);
    const healthy = !expired && !servingStale && !hasCoverageGap;
    const tone = expired ? "destructive" : healthy ? "success" : "warning";

    return {
      head: (
          <Tile
            tone={tone}
            icon={healthy ? ShieldCheck : ShieldAlert}
            title={expired ? t("ssl.expiredTitle") : t("ssl.active")}
            badge={
              cert.type_title ? (
                <Badge variant="muted" className="font-normal">
                  {cert.type_title}
                </Badge>
              ) : null
            }
          >
            {expiresOn || cert.expires_at_human ? (
              <p className="text-sm text-muted-foreground tabular-nums">
                {expired
                  ? t("ssl.expired")
                  : t(cert.renewable ? "ssl.expiresRenew" : "ssl.expiresManual", {
                      when: expiresOn ?? cert.expires_at_human,
                      days: cert.days_remaining ?? 0,
                    })}
              </p>
            ) : null}
          </Tile>
      ),
      body: (
        <div className="grid md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] md:divide-x">
          {names.length ? (
            <div className="min-w-0">
              <div className="flex items-center gap-2 px-5 pt-4 pb-1">
                <Globe className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                <h4 className="text-xs font-medium text-muted-foreground">
                  {t("ssl.namesTitle")}
                </h4>
                <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                  {t("ssl.namesCount", {
                    secured,
                    total: names.filter((n) => n.state !== "stale").length,
                  })}
                </span>
              </div>
              <ul className="px-5 pb-3">
                {names.map(({ domain, state }) => (
                  <li key={domain} className="flex flex-wrap items-center gap-x-2.5 gap-y-1 py-1.5">
                    {state === "covered" ? (
                      <CheckCircle2 className="size-4 shrink-0 text-success" aria-hidden />
                    ) : (
                      <AlertCircle className="size-4 shrink-0 text-warning" aria-hidden />
                    )}
                    <WrappedDomain domain={domain} className="max-w-[calc(100%-4.5rem)] font-mono text-sm" />
                    {state === "covered" ? (
                      <VisitSiteLink
                        domain={domain}
                        secure
                        label={t("openNamed", { domain })}
                        className="size-6 shrink-0"
                      />
                    ) : (
                      <span className="shrink-0 text-xs text-warning">
                        {t(state === "missing" ? "ssl.nameMissing" : "ssl.nameStale")}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {canManage ? (
            <div className="flex items-start gap-3 border-t px-5 py-4 md:border-t-0">
              <Label htmlFor="force-https" className="block min-w-40 flex-1 cursor-pointer">
                <span className="flex items-center gap-2 text-sm font-medium">
                  <Lock className="size-3.5 text-muted-foreground" aria-hidden />
                  {t("ssl.forceHttps")}
                </span>
                <span className="mt-0.5 block max-w-prose text-xs leading-relaxed font-normal text-muted-foreground">
                  {t("ssl.forceHttpsHint")}
                </span>
              </Label>
              <PendingSwitch
                id="force-https"
                checked={cert.force_https}
                pending={savingHttps}
                disabled={busy}
                onCheckedChange={onToggleForceHttps}
                className="mt-0.5 shrink-0"
              />
            </div>
          ) : null}
        </div>
      ),
      notes: (
        <>
          {expired && cert.force_https ? (
            <Note
              tone="destructive"
              icon={ShieldAlert}
              action={
                canManage ? (
                  <Button size="sm" disabled={busy} onClick={() => onToggleForceHttps(false)}>
                    {savingHttps ? <Loader2 className="size-4 animate-spin" /> : null}
                    {t("ssl.turnOffForceHttps")}
                  </Button>
                ) : null
              }
            >
              <p>{t("ssl.expiredForcedHttps")}</p>
            </Note>
          ) : null}

          {servingStale ? (
            <Note
              tone="destructive"
              icon={ShieldAlert}
              action={
                canManage && webServer ? (
                  <Button size="sm" onClick={reloadWebServer} disabled={reloading}>
                    {reloading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                    {t("ssl.reloadWebServer", { service: webServer })}
                  </Button>
                ) : null
              }
            >
              <p>{t("ssl.servingStale")}</p>
              {asDate(cert.served_expires_at) ? (
                <p className="text-xs text-muted-foreground tabular-nums">
                  {t("ssl.servingStaleDetail", {
                    served: asDate(cert.served_expires_at),
                    onDisk: asDate(cert.expires_at) ?? "—",
                  })}
                </p>
              ) : null}
            </Note>
          ) : null}

          {hasCoverageGap ? (
            <Note icon={AlertCircle}>
              <p>{t(cert.renewable ? "ssl.coverageGap" : "ssl.coverageGapManual")}</p>
            </Note>
          ) : null}

        </>
      ),
      actions: !canManage ? null : (
        <>
          <Button
            variant="ghost"
            className="[--destructive-ink:color-mix(in_oklch,var(--destructive),var(--foreground)_22%)] text-(--destructive-ink) hover:bg-destructive/10 hover:text-(--destructive-ink) dark:text-destructive dark:hover:text-destructive"
            onClick={() => setDeleteOpen(true)}
          >
            <Trash2 className="size-4" />
            {t("ssl.remove")}
          </Button>
          <Button
            variant={expired && cert.force_https ? "outline" : "default"}
            onClick={() => setIssueOpen(true)}
          >
            <RefreshCw className="size-4" />
            {t("ssl.reissue")}
          </Button>
        </>
      ),
    };
  }

  const { head, notes = null, body = null, actions } = view();

  return (
    <Card className="gap-0 py-0" aria-label={t("ssl.sectionTitle")}>
      <div className="flex flex-wrap items-start justify-between gap-4 p-5">
        {head}
        {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
      </div>
      {notes ? <div className="space-y-3 px-5 pb-5 empty:hidden">{notes}</div> : null}
      {body ? <div className="border-t">{body}</div> : null}

      <IssueCertDialog
        appId={appId}
        availableTypes={availableTypes}
        current={cert}
        open={issueOpen}
        onOpenChange={setIssueOpen}
        onIssued={(next) => {
          setCert(next);
          if (!isPending(next)) router.refresh();
        }}
        rateLimited={cert?.status === "failed" && NO_RETRY.has(cert.reason)}
      />
      <DeleteCertDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        pending={busy}
        onConfirm={confirmDelete}
        t={t}
      />
    </Card>
  );
}

function DeleteCertDialog({ open, onOpenChange, pending, onConfirm, t }) {
  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Trash2}
      tone="destructive"
      title={t("ssl.removeTitle")}
      description={t("ssl.removeBody")}
      cancelLabel={t("cancel")}
      confirmLabel={t("ssl.remove")}
      pending={pending}
      onConfirm={onConfirm}
    />
  );
}
