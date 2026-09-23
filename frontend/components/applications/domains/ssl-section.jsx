"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
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
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { IssueCertDialog } from "@/components/applications/domains/issue-cert-dialog";
import { VisitSiteLink } from "@/components/applications/visit-site-link";

const POLL_MS = 3000;
/*
 * Issuing is a round trip to Let's Encrypt and finishes in under a minute when
 * it finishes at all. This loop had no end: a certificate wedged in `issuing`
 * polled every three seconds for as long as the tab stayed open — 20 requests
 * a minute, each one a real API call against a 180/min budget, for hours.
 *
 * Ten minutes is comfortably longer than any successful issuance and short
 * enough that a stuck one stops costing anything. Giving up is not a failure
 * verdict: the card keeps showing "issuing", which is still the last thing the
 * server said, and Refresh re-reads it.
 */
const POLL_LIMIT = (10 * 60 * 1000) / POLL_MS;
const isPending = (c) =>
  c && (c.status === "pending" || c.status === "issuing");
// Retrying a rate-limit is precisely what must not happen — the wait is a week.
const NO_RETRY = new Set(["rate_limited"]);

/*
 * The panel's own tile vocabulary, lifted from `admin/dashboard/status-tile`:
 * a hairline card with `shadow-sm`, a 2px accent down the left edge and a
 * tinted icon chip. Colour arrives as a chip and an edge, never as a fill —
 * the one exception is `destructive`, whose 2% wash is below the threshold at
 * which it reads as "red box" and above the one at which it reads as nothing.
 *
 * Reusing this rather than inventing a fifth look: it is already the language
 * the rest of the product speaks, and every version of this card that invented
 * its own was rejected.
 */
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

/**
 * Every note on this card is the shared `Caution` at `md`.
 *
 * It was a private copy here for about an hour, which is exactly how the three
 * hand-rolled red blocks in the issue dialog got there. One component, so the
 * card and the dialog one click away from it cannot drift apart again.
 */
const Note = (props) => <Caution size="md" {...props} />;

export function SslSection({
  appId,
  initialCertificate,
  certifiable = true,
  availableTypes = [],
  canManage = false,
  // The panel's own web server, which is also its service key — the catalog is
  // matched on exactly this value server-side. Null for a role that cannot read
  // capabilities, which is why the stale alert falls back to a link.
  webServer = null,
}) {
  const t = useTranslations("applications.domains");
  const format = useFormatter();
  /*
   * Every date this card prints goes through here.
   *
   * ⚠️ `parseApiDate`, NOT `new Date`. This API sends `20-11-2026 04:34:36` —
   * day first, no timezone — which `new Date` cannot parse at all. The first
   * version of this used `new Date`, passed against a stub that happened to
   * send ISO, and silently fell back to `expires_at_human` on every real
   * certificate. The fix did nothing on the panel it was written for.
   *
   * `expires_at` is an ISO timestamp and `served_expires_at` is a plain date,
   * so the stale-certificate line read "Being served: expires 2026-08-01 · On
   * disk: expires 2026-11-20T12:00:00+00:00" — two dates in one sentence, one
   * of them a machine timestamp complete with timezone offset. Formatting is
   * not the caller's job to remember.
   *
   * Returns null rather than a fallback string so a caller can decide what an
   * unparseable date means; every one of them currently hides the line.
   */
  const asDate = (value) => {
    const when = parseApiDate(value);
    return when ? format.dateTime(when, { day: "numeric", month: "long", year: "numeric" }) : null;
  };
  const router = useRouter();

  const [cert, setCert] = useState(initialCertificate);
  const [issueOpen, setIssueOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [reloading, setReloading] = useState(false);

  /*
   * Reload the web server so it picks up the certificate already on disk.
   *
   * `reload` rather than `restart`: it re-reads configuration and certificates
   * without dropping connections, and it stays available on the web server even
   * though that unit is protected — the panel would go down with a stop.
   */
  async function reloadWebServer() {
    setReloading(true);
    try {
      await runServiceAction(webServer, "reload");
      toast.success(t("ssl.reloaded"));
      // The served certificate is re-read server-side, so the banner only
      // clears once the server agrees — not because we asked it to.
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.reloadFailed")));
    } finally {
      setReloading(false);
    }
  }

  const polling = isPending(cert);

  // Poll while issuing — ACME involves a round trip back to this server and
  // routinely outlasts the request. Stop (and re-sync SSR) once it settles.
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
    try {
      const updated = await setForceHttps(appId, next);
      setCert(updated);
      // Named per direction, not a generic "Saved". The switch moves under the
      // cursor either way, so the useful confirmation is which state it landed
      // in — and turning this ON changes what every visitor gets.
      toast.success(next ? t("ssl.forceHttpsEnabled") : t("ssl.forceHttpsDisabled"));
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
      // Every other action on this screen says what it did. This one dropped
      // the application back to plain HTTP in silence — the single most
      // consequential thing the card can do.
      toast.success(t("ssl.removed"));
      setCert(null);
      setDeleteOpen(false);
      router.refresh();
    } catch (error) {
      // Already gone. The certificate is not there, which is what was asked
      // for; a red toast over a closed dialog invites a retry that cannot work.
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

  /*
   * One card, four states — and each state returns its SURFACE and its ACTIONS
   * together.
   *
   * The actions used to be rendered inside each body, which meant every state
   * spelled out its own `canManage` branch and its own button row, in four
   * different shapes. They now land in one CardFooter, which is where every
   * other card in the panel puts them.
   */
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
            {/*
              * A way out of a state that can wedge.
              *
              * This card was a spinner and two lines with no control at all, so
              * an issuance that never completes left the reader with nothing to
              * press on the one screen that decides whether the application
              * serves HTTPS. Capping the poll made that worse, not better:
              * after ten minutes the spinner is no longer even asking.
              */}
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
      // `apiMessage` reads `error.response.data.message`; this message arrives
      // on the certificate itself, so it is shaped to match rather than
      // reimplementing the key-detection here.
      const certMessage = apiMessage({ response: { data: { message: cert.message } } }, null);
      return {
        content: (
          <>
            <Tile tone="destructive" icon={ShieldAlert} title={t("ssl.failed")}>
              {/* Through `apiMessage`, like every other API sentence in the
                  panel. Printed raw, an untranslated lookup key — the backend
                  sends `errors/ssl.issue_failed` shapes from some paths —
                  landed on the card as-is and read as the panel being broken
                  rather than the certificate. */}
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
        actions:
          canManage && !noRetry ? (
            <>
              {/* Red text, the ink deepened in light mode: plain destructive
                  on the hover tint measured 3.82:1 (the Files Trash fix). */}
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

    /*
     * Every name this certificate has an opinion about, in one list.
     * `domains` are on it, `missing_domains` are the application's names it
     * does not carry, `stale_domains` are names it carries that the
     * application has dropped — one question, so one list.
     */
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
    // Declared before anything reads it: an earlier version put this below the
    // tone that depends on it, which built and linted and then crashed on two
    // of the three states at render time.
    const healthy = !expired && !servingStale && !hasCoverageGap;
    const tone = expired ? "destructive" : healthy ? "success" : "warning";

    return {
      content: (
        <>
          {/* THE ANSWER — one tile, the only one with a status colour. */}
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
            {/* `tabular-nums` because a day count is data. */}
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

          {/* THE NAMES — a bounded list with its own header and a count, so
              "which of my domains are actually secured" is answered by
              scanning one column rather than reading prose. */}
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
                  // Left-grouped on purpose. `justify-between` on a 940px card
                  // throws the name and its status to opposite edges with a
                  // void between them; the group ends where its content ends.
                  <li key={domain} className="flex flex-wrap items-center gap-x-2.5 gap-y-1 px-4 py-2.5">
                    {state === "covered" ? (
                      <CheckCircle2 className="size-4 shrink-0 text-success" aria-hidden />
                    ) : (
                      <AlertCircle className="size-4 shrink-0 text-warning" aria-hidden />
                    )}
                    {/* Wraps rather than truncates. `truncate` here hid the
                        second half of every name at 390px — and the name is
                        the one thing the row exists to tell you.
                        The max-width keeps ~46px free on the last line so the
                        open-site link stays beside the name instead of
                        wrapping onto a line of its own. No effect on a desktop
                        row, where the cap is 800px and a hostname is 250. */}
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

          {/* WHAT IS WRONG — as notes, not banners. */}
          {expired && cert.force_https ? (
            <Note tone="destructive" icon={ShieldAlert}>
              <p>{t("ssl.expiredForcedHttps")}</p>
              {canManage ? (
                <Button size="sm" disabled={busy} onClick={() => onToggleForceHttps(false)}>
                  {busy ? <Loader2 className="size-4 animate-spin" /> : null}
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
                <Button size="sm" variant="outline" onClick={reloadWebServer} disabled={reloading}>
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

          {/* THE SETTING — its own tile, because it is a control the reader
              changes rather than a fact the card is reporting. */}
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
              <Switch
                id="force-https"
                checked={cert.force_https}
                disabled={busy}
                onCheckedChange={onToggleForceHttps}
                className="mt-1 shrink-0"
              />
            </div>
          ) : null}
        </>
      ),
      // Both actions out in the open, in every state — a healthy certificate
      // used to hide them behind a "Certificate options" menu.
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
