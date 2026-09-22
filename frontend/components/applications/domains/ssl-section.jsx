"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { parseApiDate } from "@/lib/format/api-date";
import { toast } from "sonner";
import {
  MoreHorizontal,
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
import { runServiceAction } from "@/lib/api/services";
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
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
        <div className="space-y-3 rounded-xl border bg-card p-4">
          <div className="flex items-center gap-3">
            <Loader2 className="size-5 shrink-0 animate-spin text-primary" />
            <div>
              <p className="text-sm font-medium">{t("ssl.issuing")}</p>
              <p className="text-sm text-muted-foreground">
                {t("ssl.issuingBody")}
              </p>
            </div>
          </div>
          {/*
            * A way out of a state that can wedge.
            *
            * This card was a spinner and two lines, with no control at all —
            * so an issuance that never completes left the reader with nothing
            * to press, on the one screen that decides whether the application
            * serves HTTPS. Capping the poll made that worse rather than
            * better: after ten minutes the spinner is no longer even asking.
            *
            * Removing a stuck pending certificate is how you get back to a
            * card that offers Enable HTTPS again.
            */}
          {canManage ? (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
              <p className="text-xs text-muted-foreground">{t("ssl.issuingStuck")}</p>
              <Button size="sm" variant="destructive" onClick={() => setDeleteOpen(true)}>
                <Trash2 className="size-4" />
                {t("ssl.remove")}
              </Button>
            </div>
          ) : null}
        </div>
      );
    }

    // --- Failed ---
    if (cert.status === "failed") {
      const noRetry = NO_RETRY.has(cert.reason);
      // `apiMessage` reads `error.response.data.message`; this message arrives
      // on the certificate itself, so it is shaped to match rather than
      // reimplementing the key-detection here.
      const certMessage = apiMessage({ response: { data: { message: cert.message } } }, null);
      return (
        <div className="space-y-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <div className="flex flex-wrap items-start gap-3">
            <ShieldAlert className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div className="min-w-40 flex-1">
              <p className="text-sm font-medium text-destructive">
                {t("ssl.failed")}
              </p>
              {/* Through `apiMessage`, like every other API sentence in the
                  panel. Printed raw, an untranslated lookup key — the backend
                  sends `errors/ssl.issue_failed` shapes from some paths —
                  landed on the card as-is and read as the panel being broken
                  rather than the certificate. */}
              {certMessage ? (
                <p className="mt-0.5 text-sm">{certMessage}</p>
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
    // An expired certificate is not a healthy one with a footnote. Browsers
    // refuse the site outright, so the card carries the failure treatment —
    // it used to be green, headed "HTTPS is active", with the expiry in small
    // red text underneath, and the reassuring half was the loud half.
    const expired = cert.expired;
    /*
     * A green panel is a claim, and it must not be made while the site is
     * handing visitors a certificate their browser rejects.
     *
     * Only rendering this showed it: the stale-certificate alert came out as a
     * red box inside a green "HTTPS is active" frame with a healthy countdown
     * above it. The frame contradicted its own contents, and the frame is what
     * someone reads first. So a stale certificate is BROKEN for the purposes of
     * this panel's tone, even though the file on disk is perfectly valid.
     *
     * The heading still says HTTPS is active, because it is — what is wrong is
     * which certificate is being served, and the alert inside says exactly that.
     */
    const servingStale = cert.serving_stale === true;

    /*
     * Three tones, not two.
     *
     * The first attempt at this made a stale certificate use the expired tone,
     * which turned the whole panel red — red frame, red alert inside it, red
     * Remove button — and a wall of red says nothing because every part of it
     * is shouting equally. Reported as exactly that.
     *
     * So a stale certificate makes the frame NEUTRAL rather than red: the green
     * claim is withdrawn, which was the point, and the alert inside is then the
     * only coloured thing on the panel, which is where the eye should land.
     */
    /*
     * Three tones, and `bad` is now reserved for nothing.
     *
     * An expired certificate used to paint the frame red — and once the
     * "everyone is being redirected into it" alert landed inside, the card was
     * a red box holding a red box holding red text beside a red button. Five
     * reds, which is the failure this file already documented for the stale
     * case: a wall of red says nothing, because every part of it shouts
     * equally, and the part that matters cannot get louder.
     *
     * So expiry withdraws the green claim rather than shouting: neutral frame,
     * red heading, and the alert inside is then the only coloured panel — the
     * one place the eye should land, because it is the one with the way out.
     */
    const tone = expired || servingStale ? "neutral" : "good";
    /* Null for a missing or unparseable date, which falls back below. */
    const expiresOn = asDate(cert.expires_at);
    const expiryTone = expired
      ? "text-destructive"
      : cert.expiring_soon
        ? "text-warning"
        : "text-muted-foreground";
    return (
      <div
        className={cn(
          "space-y-4 rounded-xl border p-4",
          tone === "neutral" ? "border-border" : "border-success/30 bg-success/5",
        )}
      >
        <div className="flex flex-wrap items-start gap-3">
          {/* No green tick while the wrong certificate is going out, but no
              second alarm either — the alert below carries that. */}
          {expired ? (
            <ShieldAlert className="mt-0.5 size-5 shrink-0 text-destructive" />
          ) : tone === "neutral" ? (
            <ShieldAlert className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
          ) : (
            <ShieldCheck className="mt-0.5 size-5 shrink-0 text-success" />
          )}
          <div className="min-w-40 flex-1">
            <p className={cn("text-sm font-medium", expired && "text-destructive")}>
              {expired ? t("ssl.expiredTitle") : t("ssl.active")}
              {cert.type_title ? (
                <span className="ml-1 font-normal text-muted-foreground">
                  · {cert.type_title}
                </span>
              ) : null}
            </p>
            {cert.domains?.length ? (
              <>
                {/* Two mono links jammed together with no heading did not read
                    as "the names this certificate covers" — which is the whole
                    question the warnings underneath are about. */}
                <p className="mt-2 text-xs font-medium text-muted-foreground">
                  {t("ssl.coversLabel")}
                </p>
                <ul className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                  {cert.domains.map((domain) => (
                    <li key={domain} className="flex min-w-0 items-center gap-1">
                      <span className="truncate font-mono text-xs text-muted-foreground">{domain}</span>
                      {/* https without hesitation here: being in this list is
                          what "the certificate covers it" means. */}
                      <VisitSiteLink domain={domain} secure label={t("openNamed", { domain })} className="size-5" />
                    </li>
                  ))}
                </ul>
              </>
            ) : null}
            {/*
              * A date, not a second relative phrase.
              *
              * This read "Expires 1 month from now (59 days)" — the same fact
              * twice, in two units that disagree, because `expires_at_human`
              * is the backend's relative rounding and `days_remaining` is the
              * exact count. One of them had to go, and the vague one is the
              * one nobody can act on: "1 month from now" cannot be put in a
              * calendar and is wrong by a month at 59 days.
              *
              * Formatted through next-intl rather than `toLocaleDateString`,
              * so it follows the panel's locale like every other date here.
              * `expires_at_human` stays as the fallback for a payload that
              * carries no machine-readable date.
              */}
            {expiresOn || cert.expires_at_human ? (
              <p className={cn("mt-1 text-sm", expiryTone)}>
                {cert.expired
                  ? t("ssl.expired")
                  : t(
                      cert.renewable ? "ssl.expiresRenew" : "ssl.expiresManual",
                      {
                        when: expiresOn ?? cert.expires_at_human,
                        days: cert.days_remaining ?? 0,
                      },
                    )}
              </p>
            ) : null}
          </div>
        </div>

        {/*
          Expired, and every visitor is still being sent to it.
          
          The two facts were on the card separately — "HTTPS is not working"
          above, a Force HTTPS switch below still described as "Redirect every
          visitor from HTTP to HTTPS" — and nobody joined them up. Together
          they mean the application is entirely unreachable: port 80 redirects
          to 443, 443 presents an expired certificate, and the browser refuses
          the page with no way through. With the redirect OFF the same expired
          certificate is a degraded site rather than a dead one.
          
          So this is the one state where the panel should say "turn something
          off", and the switch it means is directly below.
        */}
        {expired && cert.force_https ? (
          <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
            <p className="flex items-start gap-2 text-sm text-destructive">
              <ShieldAlert className="mt-0.5 size-4 shrink-0" />
              <span>{t("ssl.expiredForcedHttps")}</span>
            </p>
            {/* The primary button on the card, not an outline chip. This is the
                fastest route back to a reachable application — one request,
                instant — and it was the faintest thing on screen while
                "Reissue", which takes a minute and can fail, was solid blue. */}
            {canManage ? (
              <Button
                size="sm"
                className="mt-2"
                disabled={busy}
                onClick={() => onToggleForceHttps(false)}
              >
                {busy ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("ssl.turnOffForceHttps")}
              </Button>
            ) : null}
          </div>
        ) : null}

        {/*
          The file renewed and the running server never picked it up.
          
          This is the one certificate state where the panel and the browser
          disagree: the countdown above says "expires in 60 days" from the file
          on disk while every visitor is handed the old one and shown a warning.
          Destructive rather than warning for that reason — it is live breakage,
          not a thing to get round to.
          
          Strictly `=== true`. The field is null when nothing managed to complete
          a handshake to look, and "we could not check" must never render as
          either a problem or a tick.
        */}
        {cert.serving_stale === true ? (
          <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
            <p className="flex items-start gap-2 text-sm text-destructive">
              <ShieldAlert className="mt-0.5 size-4 shrink-0" />
              <span>{t("ssl.servingStale")}</span>
            </p>
            {asDate(cert.served_expires_at) ? (
              <p className="mt-1 pl-6 text-xs text-muted-foreground">
                {t("ssl.servingStaleDetail", {
                  served: asDate(cert.served_expires_at),
                  onDisk: asDate(cert.expires_at) ?? "—",
                })}
              </p>
            ) : null}
            {canManage && webServer ? (
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={reloadWebServer}
                disabled={reloading}
              >
                {reloading ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <RefreshCw className="size-4" />
                )}
                {t("ssl.reloadWebServer", { service: webServer })}
              </Button>
            ) : null}
          </div>
        ) : null}

        {/*
          Names on the certificate the site no longer has.
          
          Not cosmetic and not the same as `missing_domains`: certbot fails a
          whole renewal if any one name in the lineage cannot be validated, so
          this certificate has quietly stopped renewing for the domains that are
          perfectly fine too. Nothing shows until it expires.
        */}
        {cert.stale_domains?.length ? (
          <div className="rounded-lg border border-warning/30 bg-warning/5 p-3">
            <p className="flex items-start gap-2 text-sm text-warning">
              <ShieldAlert className="mt-0.5 size-4 shrink-0" />
              <span>
                {t("ssl.staleDomains", { domains: cert.stale_domains.join(", ") })}
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
          <div
            className={cn(
              "flex flex-wrap items-center justify-between gap-3 border-t pt-3",
              tone === "neutral" ? "border-border" : "border-success/20",
            )}
          >
            {/* `items-start`, and the hint on its own line beneath the label.
                Inline, the two ran together as one long sentence squeezed
                against the buttons — every other settings row in the panel
                stacks them. */}
            <div className="flex items-start gap-3">
              <Switch
                id="force-https"
                checked={cert.force_https}
                disabled={busy}
                onCheckedChange={onToggleForceHttps}
                className="mt-0.5"
              />
              <Label htmlFor="force-https" className="block cursor-pointer">
                <span className="block text-sm font-medium">
                  {t("ssl.forceHttps")}
                </span>
                <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                  {t("ssl.forceHttpsHint")}
                </span>
              </Label>
            </div>
            {/* Destructive, like the same action forty lines up. As a ghost it
                had no fill, no border and no colour — it read as a label, not a
                control, sitting beside a switch on the one card that decides
                whether this site serves HTTPS at all. Removing the certificate
                takes the site back to plain http. */}
            <div className="flex flex-wrap items-center gap-2">
              {/*
                * Demoted to a menu on a healthy certificate.
                *
                * A renewing Let's Encrypt certificate correctly hides Reissue
                * — there is nothing to do and offering it invites spending
                * rate limit — which left a red `destructive` Remove as the
                * ONLY control on the card. The single affordance on a healthy
                * panel was "destroy this", and it was the loudest thing in a
                * green box.
                *
                * When something IS wrong the buttons come back out (below):
                * that is when removing is a real option rather than a hazard
                * sitting under the cursor.
                */}
              {expired || !cert.renewable ? (
                <Button
                  size="sm"
                  variant="destructive"
                  onClick={() => setDeleteOpen(true)}
                >
                  <Trash2 className="size-4" />
                  {t("ssl.remove")}
                </Button>
              ) : (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8">
                      <MoreHorizontal className="size-4" />
                      <span className="sr-only">{t("ssl.moreActions")}</span>
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="min-w-48">
                    <DropdownMenuItem onSelect={() => setIssueOpen(true)}>
                      <RefreshCw className="size-4" />
                      {t("ssl.reissue")}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      variant="destructive"
                      onSelect={() => setDeleteOpen(true)}
                    >
                      <Trash2 className="size-4" />
                      {t("ssl.remove")}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
              {/* The way back. Reissue lived only inside the missing-domains
                  warning, so an expired certificate whose domains were all
                  present offered nothing but Remove — delete it and start
                  again was the only route out of a site that had stopped
                  serving HTTPS. The endpoint is the same POST; it replaces an
                  existing certificate by design.

                  Offered BEFORE expiry too, for anything that will not renew
                  itself. This card already tells those certificates they must
                  be renewed by hand (`ssl.expiresManual`, keyed off the same
                  flag) and then gave them no way to do it: the only button was
                  Remove, so replacing one meant deleting it first and dropping
                  the site to plain http in between. Waiting for `expired` means
                  the one action that avoids an outage only appears once the
                  outage has started.

                  A renewing certificate still does not show it — there is
                  nothing to do, and an always-present Reissue on a healthy
                  Let's Encrypt cert is an invitation to spend rate limit. */}
              {/* Secondary while the alert above is carrying the primary.
                  Two solid blue buttons on one card compete, and the one that
                  should win is the instant fix inside the red panel — Reissue
                  takes a minute and can fail. Without that alert (expired but
                  not redirecting, or simply not renewable) Reissue IS the way
                  out and keeps the fill. */}
              {expired || !cert.renewable ? (
                <Button
                  size="sm"
                  variant={expired && cert.force_https ? "outline" : "default"}
                  onClick={() => setIssueOpen(true)}
                >
                  <RefreshCw className="size-4" />
                  {t("ssl.reissue")}
                </Button>
              ) : null}
            </div>
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
