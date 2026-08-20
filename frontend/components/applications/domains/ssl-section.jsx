"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ShieldCheck,
  ShieldOff,
  ShieldAlert,
  Loader2,
  Trash2,
  RefreshCw,
  Lock,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
  fetchCertificate,
  setForceHttps,
  deleteCertificate,
} from "@/lib/api/domains";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { IssueCertDialog } from "@/components/applications/domains/issue-cert-dialog";
import { VisitSiteLink } from "@/components/applications/visit-site-link";

const POLL_MS = 3000;
const isPending = (c) =>
  c && (c.status === "pending" || c.status === "issuing");
// Retrying a rate-limit is precisely what must not happen — the wait is a week.
const NO_RETRY = new Set(["rate_limited"]);

export function SslSection({
  appId,
  initialCertificate,
  certifiable = true,
  availableTypes = [],
  canManage = false,
}) {
  const t = useTranslations("applications.domains");
  const router = useRouter();

  const [cert, setCert] = useState(initialCertificate);
  const [issueOpen, setIssueOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const polling = isPending(cert);

  // Poll while issuing — ACME involves a round trip back to this server and
  // routinely outlasts the request. Stop (and re-sync SSR) once it settles.
  useEffect(() => {
    if (!polling) return undefined;
    let live = true;
    const timer = setInterval(async () => {
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
    try {
      const updated = await setForceHttps(appId, next);
      setCert(updated);
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.forceHttpsFailed")));
    } finally {
      setBusy(false);
    }
  }

  async function confirmDelete() {
    setBusy(true);
    try {
      await deleteCertificate(appId);
      setCert(null);
      setDeleteOpen(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.deleteFailed")));
    } finally {
      setBusy(false);
    }
  }

  // One Card, four bodies — the state-specific surface goes in CardContent so
  // the section header stays put no matter what the certificate is doing.
  function body() {
    // --- No certificate ---
    if (!cert) {
      return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-muted/30 p-4">
          <ShieldOff className="size-5 shrink-0 text-muted-foreground" />
          <div className="min-w-40 flex-1">
            <p className="text-sm font-medium">{t("ssl.none")}</p>
            <p className="text-sm text-muted-foreground">
              {certifiable ? t("ssl.noneBody") : t("ssl.notCertifiable")}
            </p>
          </div>
          {/* Default height, like "Add domain" on the Domains tab and "Set up
              backups" on its card: this is the section's primary action, and
              `sm` is for the inline reissue/remove chips further down. It read
              as a minor link next to a full-size button one tab away. */}
          {canManage && certifiable ? (
            <Button className="shrink-0" onClick={() => setIssueOpen(true)}>
              <Lock className="size-4" />
              {t("ssl.enable")}
            </Button>
          ) : null}
        </div>
      );
    }

    // --- Issuing ---
    if (isPending(cert)) {
      return (
        <div className="flex items-center gap-3 rounded-xl border bg-card p-4">
          <Loader2 className="size-5 shrink-0 animate-spin text-primary" />
          <div>
            <p className="text-sm font-medium">{t("ssl.issuing")}</p>
            <p className="text-sm text-muted-foreground">
              {t("ssl.issuingBody")}
            </p>
          </div>
        </div>
      );
    }

    // --- Failed ---
    if (cert.status === "failed") {
      const noRetry = NO_RETRY.has(cert.reason);
      return (
        <div className="space-y-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <div className="flex flex-wrap items-start gap-3">
            <ShieldAlert className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div className="min-w-40 flex-1">
              <p className="text-sm font-medium text-destructive">
                {t("ssl.failed")}
              </p>
              {cert.message ? (
                <p className="mt-0.5 text-sm">{cert.message}</p>
              ) : null}
              {cert.reference ? (
                <p className="mt-1 font-mono text-xs text-muted-foreground">
                  {t("ssl.reference", { reference: cert.reference })}
                </p>
              ) : null}
              {noRetry ? (
                <p className="mt-1 text-sm text-muted-foreground">
                  {t("ssl.rateLimited")}
                </p>
              ) : null}
            </div>
          </div>
          {/* Shaped like the failed-provisioning card, which is the same
              situation: an operation did not work and one action fixes it.
              Full-height buttons, the recovery one primary and last, separated
              from the error text by a rule. As small outline/ghost chips they
              read as footnotes to the error rather than the way out of it. */}
          {canManage && !noRetry ? (
            <div className="flex flex-wrap justify-end gap-2 border-t border-destructive/20 pt-3">
              {/* `destructive` (the tinted variant, as on the reboot banner),
                  not ghost: a ghost button on this card is bare foreground text
                  until you hover it, so it read as a sentence rather than a
                  control — and nothing about it said it deletes. The explicit
                  border is because the variant's own tint is destructive/10 and
                  the card underneath is destructive/5; without an edge the two
                  wash together. */}
              <Button
                variant="destructive"
                className="border-destructive/25"
                onClick={() => setDeleteOpen(true)}
              >
                <Trash2 className="size-4" />
                {t("ssl.remove")}
              </Button>
              <Button onClick={() => setIssueOpen(true)}>
                <RefreshCw className="size-4" />
                {t("ssl.reissue")}
              </Button>
            </div>
          ) : null}
        </div>
      );
    }

    // --- Active ---
    const expiryTone = cert.expired
      ? "text-destructive"
      : cert.expiring_soon
        ? "text-warning"
        : "text-muted-foreground";
    return (
      <div className="space-y-4 rounded-xl border border-success/30 bg-success/5 p-4">
        <div className="flex flex-wrap items-start gap-3">
          <ShieldCheck className="mt-0.5 size-5 shrink-0 text-success" />
          <div className="min-w-40 flex-1">
            <p className="text-sm font-medium">
              {t("ssl.active")}
              {cert.type_title ? (
                <span className="ml-1 font-normal text-muted-foreground">
                  · {cert.type_title}
                </span>
              ) : null}
            </p>
            {cert.domains?.length ? (
              <ul className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                {cert.domains.map((domain) => (
                  <li key={domain} className="flex min-w-0 items-center gap-1">
                    <span className="truncate font-mono text-xs text-muted-foreground">{domain}</span>
                    {/* https without hesitation here: being in this list is
                        what "the certificate covers it" means. */}
                    <VisitSiteLink domain={domain} secure label={t("openNamed", { domain })} className="size-5" />
                  </li>
                ))}
              </ul>
            ) : null}
            {cert.expires_at_human ? (
              <p className={cn("mt-1 text-sm", expiryTone)}>
                {cert.expired
                  ? t("ssl.expired")
                  : t(
                      cert.renewable ? "ssl.expiresRenew" : "ssl.expiresManual",
                      {
                        when: cert.expires_at_human,
                        days: cert.days_remaining ?? 0,
                      },
                    )}
              </p>
            ) : null}
          </div>
        </div>

        {/* A name added after issuance is not on the cert — the quiet failure. */}
        {cert.missing_domains?.length ? (
          <div className="rounded-lg border border-warning/30 bg-warning/5 p-3">
            <p className="flex items-start gap-2 text-sm text-warning">
              <ShieldAlert className="mt-0.5 size-4 shrink-0" />
              <span>
                {t("ssl.missingDomains", {
                  domains: cert.missing_domains.join(", "),
                })}
              </span>
            </p>
            {canManage ? (
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={() => setIssueOpen(true)}
              >
                <RefreshCw className="size-4" />
                {t("ssl.reissue")}
              </Button>
            ) : null}
          </div>
        ) : null}

        {canManage ? (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-success/20 pt-3">
            <div className="flex items-center gap-3">
              <Switch
                id="force-https"
                checked={cert.force_https}
                disabled={busy}
                onCheckedChange={onToggleForceHttps}
              />
              <Label htmlFor="force-https" className="cursor-pointer">
                <span className="text-sm font-medium">
                  {t("ssl.forceHttps")}
                </span>
                <span className="block text-xs font-normal text-muted-foreground">
                  {t("ssl.forceHttpsHint")}
                </span>
              </Label>
            </div>
            {/* Destructive, like the same action forty lines up. As a ghost it
                had no fill, no border and no colour — it read as a label, not a
                control, sitting beside a switch on the one card that decides
                whether this site serves HTTPS at all. Removing the certificate
                takes the site back to plain http. */}
            <Button
              size="sm"
              variant="destructive"
              onClick={() => setDeleteOpen(true)}
            >
              <Trash2 className="size-4" />
              {t("ssl.remove")}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">
          {t("ssl.sectionTitle")}
        </CardTitle>
        <CardDescription>{t("ssl.sectionSubtitle")}</CardDescription>
      </CardHeader>
      <CardContent>{body()}</CardContent>

      <IssueCertDialog
        appId={appId}
        availableTypes={availableTypes}
        open={issueOpen}
        onOpenChange={setIssueOpen}
        onIssued={setCert}
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
