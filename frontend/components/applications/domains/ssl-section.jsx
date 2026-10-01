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
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Caution } from "@/components/ui/caution";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { IssueCertDialog } from "@/components/applications/domains/issue-cert-dialog";
import { VisitSiteLink } from "@/components/applications/visit-site-link";

const POLL_MS = 3000;
// Stops after ten minutes to protect the 180/min API budget; not a failure verdict.
const POLL_LIMIT = (10 * 60 * 1000) / POLL_MS;
const isPending = (c) =>
  c && (c.status === "pending" || c.status === "issuing");
// Never retry a rate limit: the wait is a week.
const NO_RETRY = new Set(["rate_limited"]);

// Tile styles from `admin/dashboard/status-tile`: colour from the chip and accent, never a fill.
const TONES = {
  success: { chip: "bg-success/10 text-success", accent: "bg-success/45", tint: "", title: "" },
  warning: { chip: "bg-warning/10 text-warning", accent: "bg-warning/50", tint: "", title: "" },
  destructive: {
    chip: "bg-destructive/10 text-destructive",
    accent: "bg-destructive/50",
    tint: "bg-destructive/[0.02]",
    title: "text-destructive",
  },
  progress: { chip: "bg-primary/10 text-primary", accent: "bg-primary/50", tint: "", title: "" },
  idle: { chip: "bg-muted text-muted-foreground", accent: "bg-border", tint: "", title: "" },
};

/** The card's headline state: what the certificate is, in one tile. */
function Tile({ tone = "idle", icon: Icon, spin = false, title, badge, children }) {
  const { chip, accent, tint, title: titleTint } = TONES[tone] ?? TONES.idle;
  return (
    <div className={cn("relative overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm", tint)}>
      <span className={cn("absolute inset-y-0 left-0 w-[2px]", accent)} aria-hidden />
      <div className="flex items-start gap-3 py-4 pr-4 pl-5">
        <span className={cn("flex size-9 shrink-0 items-center justify-center rounded-lg", chip)}>
          <Icon className={cn("size-[18px]", spin && "animate-spin")} aria-hidden />
        </span>
        {/* min-w-48, not min-w-0: beside a shrink-0 chip a flex-1 child will
            squeeze to one word per line rather than wrap. */}
        <div className="min-w-48 flex-1 space-y-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className={cn("text-base leading-tight font-semibold", titleTint)}>{title}</h3>
            {badge}
          </div>
          {children}
        </div>
      </div>
    </div>
  );
}

/** Every note on this card is the shared `Caution` at `md`. */
const Note = (props) => <Caution size="md" {...props} />;

export function SslSection({
  appId,
  initialCertificate,
  certifiable = true,
  availableTypes = [],
  canManage = false,
  // The web server's service key; null when capabilities cannot be read, in
  // which case the stale alert falls back to a link.
  webServer = null,
}) {
  const t = useTranslations("applications.domains");
  const format = useFormatter();
  // Use `parseApiDate`, NOT `new Date`: the API sends `20-11-2026 04:34:36` (day first, no timezone).
  // Null when unparseable; callers hide the line.
  const asDate = (value) => {
    const when = parseApiDate(value);
    return when ? format.dateTime(when, { day: "numeric", month: "long", year: "numeric" }) : null;
  };
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  const [cert, setCert] = useState(initialCertificate);
  // Re-synced whenever the page re-reads the certificate.
  const [certFrom, setCertFrom] = useState(initialCertificate);
  if (certFrom !== initialCertificate) {
    setCertFrom(initialCertificate);
    setCert(initialCertificate);
  }
  const [issueOpen, setIssueOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  // The switch's own request, separate from `busy`.
  const [savingHttps, setSavingHttps] = useState(false);
  const [reloading, setReloading] = useState(false);

  // Reload (not restart) the web server to pick up the certificate on disk:
  // no dropped connections, and allowed on the protected unit.
  async function reloadWebServer() {
    setReloading(true);
    try {
      await runServiceAction(webServer, "reload");
      // The banner clears only once the server reports the new certificate.
      await refreshAndWait();
      toast.success(t("ssl.reloaded"));
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.reloadFailed")));
    } finally {
      setReloading(false);
    }
  }

  const polling = isPending(cert);

  // Poll while issuing; stop and re-sync SSR once it settles.
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
        // transient — keep polling
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
      // Names the new state rather than a generic "Saved".
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
      // Already removed: treat as success.
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

  // Each of the four states returns its body and its actions; actions render
  // in one CardFooter.
  function view() {
    // --- No certificate ---
    if (!cert) {
      return {
        content: (
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

    // --- Issuing ---
    if (isPending(cert)) {
      return {
        content: (
          <>
            <Tile tone="progress" icon={Loader2} spin title={t("ssl.issuing")}>
              <p className="max-w-prose text-sm text-muted-foreground">{t("ssl.issuingBody")}</p>
            </Tile>
            {/* A way out of an issuance that never completes. */}
            {canManage ? (
              <Note icon={Clock3}>
                <p>{t("ssl.issuingStuck")}</p>
              </Note>
            ) : null}
          </>
        ),
        actions: canManage ? (
          <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
            <Trash2 className="size-4" />
            {t("ssl.remove")}
          </Button>
        ) : null,
      };
    }

    // --- Failed ---
    if (cert.status === "failed") {
      const noRetry = NO_RETRY.has(cert.reason);
      // Wrapped in an error shape so `apiMessage` can translate it.
      const certMessage = apiMessage({ response: { data: { message: cert.message } } }, null);
      return {
        content: (
          <>
            <Tile tone="destructive" icon={ShieldAlert} title={t("ssl.failed")}>
              {/* Via `apiMessage`: some paths send an untranslated key. */}
              {certMessage ? <p className="max-w-prose text-sm">{certMessage}</p> : null}
              {cert.reference ? (
                <p className="font-mono text-xs text-muted-foreground">
                  {t("ssl.reference", { reference: cert.reference })}
                </p>
              ) : null}
            </Tile>
            {noRetry ? (
              <Note icon={Clock3}>
                <p>{t("ssl.rateLimited")}</p>
              </Note>
            ) : null}
          </>
        ),
        // Remove and Reissue stay while rate-limited: only Let's Encrypt is closed.
        actions:
          canManage ? (
            <>
              {/* Deeper red in light mode for contrast on the hover tint. */}
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

    // --- Active ---
    const expired = cert.expired;
    const servingStale = cert.serving_stale === true;

    // One list: `domains` (covered), `missing_domains` (application names not
    // on it), `stale_domains` (on it but dropped by the application).
    const names = [
      ...(cert.domains ?? []).map((domain) => ({ domain, state: "covered" })),
      ...(cert.missing_domains ?? []).map((domain) => ({ domain, state: "missing" })),
      ...(cert.stale_domains ?? []).map((domain) => ({ domain, state: "stale" })),
    ];
    // certbot validates every name in a lineage and fails the WHOLE renewal if
    // one cannot be validated, so a gap is not cosmetic.
    const hasCoverageGap = Boolean(cert.missing_domains?.length || cert.stale_domains?.length);
    const secured = names.filter((n) => n.state === "covered").length;
    const expiresOn = asDate(cert.expires_at);
    const healthy = !expired && !servingStale && !hasCoverageGap;
    const tone = expired ? "destructive" : healthy ? "success" : "warning";

    return {
      content: (
        <>
          {/* The status tile: the only element with a status colour. */}
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

          {/* The names, with a count. */}
          {names.length ? (
            <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
              <div className="flex items-center gap-2 border-b border-border/60 bg-muted/30 px-4 py-2.5">
                <Globe className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                <h4 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {t("ssl.namesTitle")}
                </h4>
                <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                  {t("ssl.namesCount", { secured, total: names.length })}
                </span>
              </div>
              <ul className="divide-y divide-border/60">
                {names.map(({ domain, state }) => (
                  // Left-grouped, not justify-between, to avoid a wide gap.
                  <li key={domain} className="flex flex-wrap items-center gap-x-2.5 gap-y-1 px-4 py-2.5">
                    {state === "covered" ? (
                      <CheckCircle2 className="size-4 shrink-0 text-success" aria-hidden />
                    ) : (
                      <AlertCircle className="size-4 shrink-0 text-warning" aria-hidden />
                    )}
                    {/* Wraps rather than truncates; the max-width leaves room
                        for the visit link on the last line. */}
                    <span className="max-w-[calc(100%-4.5rem)] font-mono text-sm break-all">{domain}</span>
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

          {/* Problems, as notes. */}
          {expired && cert.force_https ? (
            <Note tone="destructive" icon={ShieldAlert}>
              <p>{t("ssl.expiredForcedHttps")}</p>
              {canManage ? (
                <Button size="sm" disabled={busy} onClick={() => onToggleForceHttps(false)}>
                  {savingHttps ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("ssl.turnOffForceHttps")}
                </Button>
              ) : null}
            </Note>
          ) : null}

          {servingStale ? (
            <Note tone="destructive" icon={ShieldAlert}>
              <p>{t("ssl.servingStale")}</p>
              {asDate(cert.served_expires_at) ? (
                <p className="text-xs text-muted-foreground tabular-nums">
                  {t("ssl.servingStaleDetail", {
                    served: asDate(cert.served_expires_at),
                    onDisk: asDate(cert.expires_at) ?? "—",
                  })}
                </p>
              ) : null}
              {canManage && webServer ? (
                /* Solid: it is the fix for what the note reports. */
                <Button size="sm" className="w-fit" onClick={reloadWebServer} disabled={reloading}>
                  {reloading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                  {t("ssl.reloadWebServer", { service: webServer })}
                </Button>
              ) : null}
            </Note>
          ) : null}

          {hasCoverageGap ? (
            <Note icon={AlertCircle}>
              <p>{t("ssl.coverageGap")}</p>
            </Note>
          ) : null}

          {/* The Force HTTPS setting, in its own tile. */}
          {canManage ? (
            <div className="flex items-start gap-3 rounded-xl border border-border/60 bg-card p-4 shadow-sm">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Lock className="size-[18px]" aria-hidden />
              </span>
              <Label htmlFor="force-https" className="block min-w-48 flex-1 cursor-pointer">
                <span className="block text-sm font-medium">{t("ssl.forceHttps")}</span>
                <span className="mt-0.5 block max-w-prose text-xs leading-relaxed font-normal text-muted-foreground">
                  {t("ssl.forceHttpsHint")}
                </span>
              </Label>
              {/* Shows a spinner while the change applies. */}
              <PendingSwitch
                id="force-https"
                checked={cert.force_https}
                pending={savingHttps}
                disabled={busy}
                onCheckedChange={onToggleForceHttps}
                className="mt-1 shrink-0"
              />
            </div>
          ) : null}
        </>
      ),
      // Both actions visible in every state.
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

  const { content, actions } = view();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">
          {t("ssl.sectionTitle")}
        </CardTitle>
        <CardDescription>{t("ssl.sectionSubtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">{content}</CardContent>
      {actions ? <CardFooter className="justify-end gap-2">{actions}</CardFooter> : null}

      <IssueCertDialog
        appId={appId}
        availableTypes={availableTypes}
        current={cert}
        open={issueOpen}
        onOpenChange={setIssueOpen}
        onIssued={(next) => {
          setCert(next);
          // An upload is active at once with no poll to settle, so refresh
          // here to update the Domains tab and the tab icon.
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
