import { useEffect, useId, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Check, FlaskConical, Loader2, ShieldCheck, TriangleAlert } from "lucide-react";
import {
  issueCertificate,
  startCertificateDryRun,
  fetchCertificateDryRun,
} from "@/lib/api/domains";
import { apiMessage } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { FormModal } from "@/components/ui/form-modal";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

// Same cadence as the SSL card's issuance polling.
const DRY_RUN_POLL_MS = 3000;
// Ten minutes, as for an issuance; a run still going after that lost its worker.
const DRY_RUN_POLL_LIMIT = (10 * 60 * 1000) / DRY_RUN_POLL_MS;

const FALLBACK_TYPES = [
  { type: "letsencrypt", available: true, recommended: true },
  { type: "self_signed", available: true },
  { type: "custom", available: true },
];

/**
 * Issues a certificate. The server decides what is possible: `available` gates
 * each method and `recommended` picks the default; never guess from the domain.
 */
export function IssueCertDialog({
  appId,
  availableTypes = [],
  // The current certificate, or null; distinguishes "secure" from "replace".
  current = null,
  open,
  onOpenChange,
  onIssued,
  // Let's Encrypt hit its rate limit; only that method is closed.
  rateLimited = false,
}) {
  const t = useTranslations("applications.domains");
  const types = (availableTypes.length ? availableTypes : FALLBACK_TYPES).map((entry) =>
    rateLimited && entry.type === "letsencrypt"
      ? { ...entry, available: false, reason: t("ssl.rateLimitedMethod") }
      : entry,
  );
  // The server's recommendation, else the first available method; never fixed.
  const defaultType =
    types.find((entry) => entry.recommended && entry.available)?.type ??
    types.find((entry) => entry.available)?.type ??
    types[0]?.type;

  const [chosen, setType] = useState(defaultType);
  // A choice that became unavailable falls back to the default.
  const type = types.some((entry) => entry.type === chosen && entry.available !== false)
    ? chosen
    : defaultType;
  // Not react-hook-form, so labels need explicit ids.
  const fieldId = useId();
  const [pem, setPem] = useState({ certificate: "", private_key: "", chain: "" });
  // Per-field API errors shown at their field. The backend attaches the
  // certificate/key mismatch to `private_key`.
  const [pemErrors, setPemErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  // Per-domain reachability refusals (422 errors.domain); they unlock the
  // "issue anyway" (force) path, never offered up front.
  const [refusals, setRefusals] = useState([]);
  // Dry run: reachability, then certbot against Let's Encrypt staging. Null
  // until requested.
  const [dryRun, setDryRun] = useState(null);
  const [starting, setStarting] = useState(false);
  const [stalled, setStalled] = useState(false);

  const selected = types.find((entry) => entry.type === type);
  const dryRunning = starting || (dryRun?.status === "running" && !stalled);
  // A pass needs only one passing name; list the names left off.
  const leftOff = dryRun?.status === "passed" ? (dryRun.domains ?? []).filter((entry) => !entry.ok) : [];

  function reset() {
    setType(defaultType);
    setPem({ certificate: "", private_key: "", chain: "" });
    setPemErrors({});
    setRefusals([]);
    setDryRun(null);
    setStarting(false);
    setStalled(false);
    setSubmitting(false);
  }

  function handleOpenChange(next) {
    if (!next) reset();
    onOpenChange?.(next);
  }

  // Poll only while running; the backend's `failed()` hook always writes a
  // verdict, so this ends. `live` drops replies landing after close.
  useEffect(() => {
    if (!open || dryRun?.status !== "running" || stalled) return undefined;
    let live = true;
    let ticks = 0;
    const timer = setInterval(async () => {
      if (++ticks > DRY_RUN_POLL_LIMIT) {
        clearInterval(timer);
        if (live) setStalled(true);
        return;
      }
      try {
        const next = await fetchCertificateDryRun(appId);
        if (live) setDryRun(next);
      } catch {
        // transient — keep polling
      }
    }, DRY_RUN_POLL_MS);
    return () => {
      live = false;
      clearInterval(timer);
    };
  }, [open, dryRun?.status, appId, stalled]);

  async function runDryRun() {
    setStarting(true);
    setStalled(false);
    // Clear the previous verdict so it never sits beside a new run.
    setDryRun(null);
    setRefusals([]);
    try {
      setDryRun(await startCertificateDryRun(appId));
    } catch (error) {
      toast.error(apiMessage(error, t("ssl.dryRunStartFailed")));
    } finally {
      setStarting(false);
    }
  }

  async function submit(force = false) {
    setSubmitting(true);
    setRefusals([]);
    setPemErrors({});
    const body =
      type === "custom"
        ? { type, certificate: pem.certificate, private_key: pem.private_key, chain: pem.chain || undefined }
        : force
          ? { type, force: true }
          : { type };
    try {
      const cert = await issueCertificate(appId, body);
      onIssued?.(cert);
      handleOpenChange(false);
    } catch (error) {
      const errors = error.response?.data?.errors ?? {};
      const domainErrors = errors.domain;
      const fieldErrors = Object.fromEntries(
        ["certificate", "private_key", "chain"]
          .map((field) => [field, errors[field]?.[0]])
          .filter(([, message]) => Boolean(message)),
      );

      if (Array.isArray(domainErrors) && domainErrors.length) {
        setRefusals(domainErrors);
      } else if (Object.keys(fieldErrors).length) {
        // Shown at the fields, not as a toast.
        setPemErrors(fieldErrors);
      } else {
        toast.error(apiMessage(error, t("ssl.issueFailed")));
      }
    } finally {
      setSubmitting(false);
    }
  }

  // Why Issue cannot run yet, or null (an upload needs both PEM blocks).
  const issueReason =
    type === "custom" && !(pem.certificate.trim() && pem.private_key.trim())
      ? t("ssl.uploadNeedsBoth")
      : null;

  // Force skips only the reachability check, so it is offered only when that
  // check failed (on issue or in a dry run), not for CA-stage failures.
  const dryRunBlockedOnReach =
    dryRun?.status === "failed" && dryRun?.stage === "reachability";
  const canForce =
    type === "letsencrypt" && (refusals.length > 0 || dryRunBlockedOnReach);

  // Opened via "Reissue" on a site already served over HTTPS.
  const replacing = Boolean(current);
  // A different method discards the current certificate; warn before issuing.
  const swapsMethod = replacing && current.type && current.type !== type;

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      icon={ShieldCheck}
      title={replacing ? t("ssl.reissueTitle") : t("ssl.issueTitle")}
      description={replacing ? t("ssl.reissueSubtitle") : t("ssl.issueSubtitle")}
      footer={
        <>
          <Button type="button" variant="outline" disabled={submitting} onClick={() => handleOpenChange(false)}>
            {t("cancel")}
          </Button>
          {canForce ? (
            <Button type="button" variant="outline" disabled={submitting} onClick={() => submit(true)}>
              {submitting && <Loader2 className="size-4 animate-spin" />}
              {t("ssl.forceIssue")}
            </Button>
          ) : null}
          <ReasonTooltip reason={submitting ? null : issueReason}>
            <Button
              type="button"
              disabled={submitting || selected?.available === false || Boolean(issueReason)}
              onClick={() => submit(false)}
            >
              {submitting && <Loader2 className="size-4 animate-spin" />}
              {t("ssl.issue")}
            </Button>
          </ReasonTooltip>
        </>
      }
    >
      {/* `grid gap-2` to match FormItem's label spacing. */}
      <div className="grid gap-2">
        <Label htmlFor={`${fieldId}-method`}>{t("ssl.method")}</Label>
        <Select value={type} onValueChange={(v) => { setType(v); setRefusals([]); setDryRun(null); }}>
          {/* w-full: SelectTrigger defaults to w-fit. */}
          <SelectTrigger id={`${fieldId}-method`} className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {types.map((entry) => (
              <ReasonTooltip
                key={entry.type}
                reason={!entry.available ? entry.reason : null}
              >
                <SelectItem value={entry.type} disabled={!entry.available}>
                  {entry.label ?? t(`ssl.method_${entry.type}`)}
                </SelectItem>
              </ReasonTooltip>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* An uploaded certificate cannot be re-issued here, so swapping is lossy. */}
      {swapsMethod ? (
        <Caution size="md">
          {t("ssl.replacesCurrent", {
            current: current.type_title ?? t(`ssl.method_${current.type}`),
          })}
        </Caution>
      ) : null}

      {/* Toned by `available`, not by the presence of a reason: an available
          method can still carry an informational reason. */}
      {selected?.reason ? (
        <Caution size="md" tone={selected.available ? "warning" : "destructive"}>
          {selected.reason}
        </Caution>
      ) : (
        <p className="text-xs text-muted-foreground">{t(`ssl.methodHint_${type}`)}</p>
      )}

      {/* Let's Encrypt dry run, kept out of the footer. */}
      {type === "letsencrypt" ? (
        <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="min-w-40 flex-1 text-xs text-muted-foreground">
              {t("ssl.dryRunHint")}
            </p>
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="shrink-0"
              disabled={dryRunning || submitting}
              onClick={runDryRun}
            >
              {dryRunning ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <FlaskConical className="size-4" />
              )}
              {t("ssl.dryRun")}
            </Button>
          </div>

          {/* Names the running stage; the CA stage is much slower. */}
          {dryRunning ? (
            <p className="text-sm text-muted-foreground">
              {t(`ssl.dryRunStage_${dryRun?.stage ?? "reachability"}`)}
            </p>
          ) : null}

          {stalled ? (
            <Caution size="md">{t("ssl.dryRunStalled")}</Caution>
          ) : null}

          {dryRun && dryRun.status !== "running" ? (
            <div className="space-y-2">
              <p
                className={cn(
                  "flex items-center gap-2 text-sm font-medium",
                  dryRun.status !== "passed"
                    ? "text-destructive"
                    : leftOff.length
                      ? "text-warning"
                      : "text-success",
                )}
              >
                {dryRun.status === "passed" && !leftOff.length ? (
                  <Check className="size-4" />
                ) : (
                  <TriangleAlert className="size-4" />
                )}
                {dryRun.status !== "passed"
                  ? t("ssl.dryRunFailed")
                  : leftOff.length
                    ? t("ssl.dryRunPartial", {
                        ready: dryRun.domains.length - leftOff.length,
                        total: dryRun.domains.length,
                        names: leftOff.map((entry) => entry.domain).join(", "),
                        count: leftOff.length,
                      })
                    : t("ssl.dryRunPassed")}
              </p>

              {/* Every name, passing ones included. */}
              {dryRun.domains?.length ? (
                <ul className="space-y-1.5">
                  {dryRun.domains.map((entry) => (
                    <li key={entry.domain} className="flex items-start gap-2 text-sm">
                      {entry.ok ? (
                        <Check className="mt-0.5 size-4 shrink-0 text-success" />
                      ) : (
                        <TriangleAlert className={cn("mt-0.5 size-4 shrink-0", leftOff.length ? "text-warning" : "text-destructive")} />
                      )}
                      {/* The icon carries the verdict; the text stays neutral. */}
                      <span className={entry.ok ? "text-muted-foreground" : undefined}>
                        {entry.message}
                      </span>
                    </li>
                  ))}
                </ul>
              ) : null}

              {/* Set only when the CA stage failed. */}
              {dryRun.message ? <p className="text-sm">{dryRun.message}</p> : null}
              {dryRun.reference ? (
                <p className="font-mono text-xs text-muted-foreground">
                  {t("ssl.reference", { reference: dryRun.reference })}
                </p>
              ) : null}

              {/* Explains "Issue anyway": a NAT'd server cannot reach its own
                  public address, though the real challenge may still succeed. */}
              {dryRunBlockedOnReach ? (
                <p className="text-xs text-muted-foreground">{t("ssl.forceHint")}</p>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}

      {type === "custom" ? (
        <div className="space-y-3">
          <div className="grid gap-2">
            <Label htmlFor={`${fieldId}-certificate`} hint={t("ssl.certificateHint")}>{t("ssl.certificate")}</Label>
            <Textarea
              id={`${fieldId}-certificate`}
              rows={4}
              className="font-mono text-xs"
              placeholder="-----BEGIN CERTIFICATE-----"
              value={pem.certificate}
              onChange={(e) => setPem((p) => ({ ...p, certificate: e.target.value }))}
              aria-invalid={Boolean(pemErrors.certificate)}
              aria-describedby={pemErrors.certificate ? `${fieldId}-certificate-error` : undefined}
            />
            {pemErrors.certificate ? (
              <p id={`${fieldId}-certificate-error`} className="text-sm text-destructive">
                {pemErrors.certificate}
              </p>
            ) : null}
          </div>
          <div className="grid gap-2">
            <Label htmlFor={`${fieldId}-private_key`} hint={t("ssl.privateKeyHint")}>{t("ssl.privateKey")}</Label>
            <Textarea
              id={`${fieldId}-private_key`}
              rows={4}
              className="font-mono text-xs"
              placeholder="-----BEGIN PRIVATE KEY-----"
              value={pem.private_key}
              onChange={(e) => setPem((p) => ({ ...p, private_key: e.target.value }))}
              aria-invalid={Boolean(pemErrors.private_key)}
              aria-describedby={pemErrors.private_key ? `${fieldId}-private_key-error` : undefined}
            />
            {pemErrors.private_key ? (
              <p id={`${fieldId}-private_key-error`} className="text-sm text-destructive">
                {pemErrors.private_key}
              </p>
            ) : null}
          </div>
          <div className="grid gap-2">
            <Label htmlFor={`${fieldId}-chain`} hint={t("ssl.chainHint")}>
              {t("ssl.chain")} <span className="text-muted-foreground">({t("ssl.optional")})</span>
            </Label>
            <Textarea
              id={`${fieldId}-chain`}
              rows={3}
              className="font-mono text-xs"
              placeholder="-----BEGIN CERTIFICATE-----"
              value={pem.chain}
              onChange={(e) => setPem((p) => ({ ...p, chain: e.target.value }))}
              aria-invalid={Boolean(pemErrors.chain)}
              aria-describedby={pemErrors.chain ? `${fieldId}-chain-error` : undefined}
            />
            {pemErrors.chain ? (
              <p id={`${fieldId}-chain-error`} className="text-sm text-destructive">
                {pemErrors.chain}
              </p>
            ) : null}
          </div>
        </div>
      ) : null}

      {/* Reachability refusals — one message per domain, each a distinct fix. */}
      {refusals.length ? (
        <Caution size="md" tone="destructive">
          <p className="font-medium">{t("ssl.refusedTitle")}</p>
          {/* Plain text, not red: each refusal is the fix to apply. */}
          <ul className="list-disc space-y-1 pl-4">
            {refusals.map((msg, i) => (
              <li key={i}>{msg}</li>
            ))}
          </ul>
          {canForce ? <p className="text-xs text-muted-foreground">{t("ssl.forceHint")}</p> : null}
        </Caution>
      ) : null}
    </FormModal>
  );
}
