"use client";

import { useId, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, ShieldCheck, TriangleAlert } from "lucide-react";
import { issueCertificate } from "@/lib/api/domains";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
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

// Only used when the server sends no catalog at all — an older backend. The
// server decides what a site can have; this is the last-resort shape, not a
// preference.
const FALLBACK_TYPES = [
  { type: "letsencrypt", available: true, recommended: true },
  { type: "self_signed", available: true },
  { type: "custom", available: true },
];

/**
 * The method to secure this site with, offered as the server sees it.
 *
 * Nothing here decides what is possible: `available` gates each option and
 * `recommended` picks the default. A site on a nip.io or internal name cannot
 * have Let's Encrypt but can absolutely have a self-signed certificate, and
 * guessing that from the domain name is how such a site ends up being told it
 * cannot have SSL at all.
 */
export function IssueCertDialog({
  appId,
  availableTypes = [],
  // What the site has right now, so the dialog can tell "secure this site" from
  // "replace what is already securing it" — two very different acts behind one
  // button. Null when there is no certificate.
  current = null,
  open,
  onOpenChange,
  onIssued,
}) {
  const t = useTranslations("applications.domains");
  const types = availableTypes.length ? availableTypes : FALLBACK_TYPES;
  // The server's recommendation, else the first thing that actually works —
  // never a fixed default, which is how the dialog came to open on Let's
  // Encrypt for sites Let's Encrypt refuses.
  const defaultType =
    types.find((entry) => entry.recommended && entry.available)?.type ??
    types.find((entry) => entry.available)?.type ??
    types[0]?.type;

  const [type, setType] = useState(defaultType);
  // This dialog holds its own state rather than react-hook-form, so it gets none
  // of FormItem's label wiring for free. Without an id every label here was
  // decorative: clicking it did nothing and a screen reader announced an
  // unlabelled control.
  const fieldId = useId();
  const [pem, setPem] = useState({ certificate: "", private_key: "", chain: "" });
  const [submitting, setSubmitting] = useState(false);
  // Per-domain reachability refusals (422 errors.domain). Their presence is what
  // unlocks the "issue anyway" (force) path — never offered up front.
  const [refusals, setRefusals] = useState([]);

  const selected = types.find((entry) => entry.type === type);

  function reset() {
    setType(defaultType);
    setPem({ certificate: "", private_key: "", chain: "" });
    setRefusals([]);
    setSubmitting(false);
  }

  function handleOpenChange(next) {
    if (!next) reset();
    onOpenChange?.(next);
  }

  async function submit(force = false) {
    setSubmitting(true);
    setRefusals([]);
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
      const domainErrors = error.response?.data?.errors?.domain;
      if (Array.isArray(domainErrors) && domainErrors.length) {
        setRefusals(domainErrors);
      } else {
        toast.error(apiMessage(error, t("ssl.issueFailed")));
      }
    } finally {
      setSubmitting(false);
    }
  }

  const canForce = type === "letsencrypt" && refusals.length > 0;

  // Reached from "Reissue" on a site that is already served over HTTPS. The
  // dialog said "Issue a certificate — secure this site over HTTPS" either way,
  // which describes the wrong job: you came here to cover a name the current
  // certificate misses, not to turn HTTPS on.
  const replacing = Boolean(current);
  // Picking a different method throws the current certificate away. That is
  // fine for a self-signed one and a real loss for an uploaded one, which
  // cannot be re-issued from here — so it is said out loud before the button.
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
          <Button
            type="button"
            disabled={submitting || selected?.available === false}
            onClick={() => submit(false)}
          >
            {submitting && <Loader2 className="size-4 animate-spin" />}
            {t("ssl.issue")}
          </Button>
        </>
      }
    >
      {/* `grid gap-2`, not `space-y-1.5`: that is what FormItem uses, and this
          dialog sits one click away from Add domain, which is built on it. Two
          modals in the same flow were spacing their labels 6px and 8px apart. */}
      <div className="grid gap-2">
        <Label htmlFor={`${fieldId}-method`}>{t("ssl.method")}</Label>
        <Select value={type} onValueChange={(v) => { setType(v); setRefusals([]); }}>
          {/* shadcn's SelectTrigger is `w-fit` by default, so a form field
              without this shrinks to its current option — and the control
              visibly changes width when the selection does. Every other form
              select in the panel is w-full; these two were the misses. */}
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

      {/* Said before the button, not after the fact: an uploaded certificate
          cannot be re-issued from this panel, so swapping method throws away
          something the user may not be able to get back. */}
      {swapsMethod ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-sm text-warning">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          <span>
            {t("ssl.replacesCurrent", {
              current: current.type_title ?? t(`ssl.method_${current.type}`),
            })}
          </span>
        </p>
      ) : null}

      {/* The server's own words about the selected method. On an available type
          this is information — self-signed works everywhere and browsers warn
          about it — so it is toned by `available`, never by having a reason at
          all. Branching on the reason would refuse a method that works. */}
      {selected?.reason ? (
        <p
          className={
            selected.available
              ? "flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-sm text-warning"
              : "flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
          }
        >
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          <span>{selected.reason}</span>
        </p>
      ) : (
        <p className="text-xs text-muted-foreground">{t(`ssl.methodHint_${type}`)}</p>
      )}

      {type === "custom" ? (
        <div className="space-y-3">
          <div className="grid gap-2">
            <Label htmlFor={`${fieldId}-certificate`}>{t("ssl.certificate")}</Label>
            <Textarea
              id={`${fieldId}-certificate`}
              rows={4}
              className="font-mono text-xs"
              placeholder="-----BEGIN CERTIFICATE-----"
              value={pem.certificate}
              onChange={(e) => setPem((p) => ({ ...p, certificate: e.target.value }))}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor={`${fieldId}-private_key`}>{t("ssl.privateKey")}</Label>
            <Textarea
              id={`${fieldId}-private_key`}
              rows={4}
              className="font-mono text-xs"
              placeholder="-----BEGIN PRIVATE KEY-----"
              value={pem.private_key}
              onChange={(e) => setPem((p) => ({ ...p, private_key: e.target.value }))}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor={`${fieldId}-chain`}>
              {t("ssl.chain")} <span className="text-muted-foreground">({t("ssl.optional")})</span>
            </Label>
            <Textarea
              id={`${fieldId}-chain`}
              rows={3}
              className="font-mono text-xs"
              placeholder="-----BEGIN CERTIFICATE-----"
              value={pem.chain}
              onChange={(e) => setPem((p) => ({ ...p, chain: e.target.value }))}
            />
          </div>
        </div>
      ) : null}

      {/* Reachability refusals — one message per domain, each a distinct fix. */}
      {refusals.length ? (
        <div className="space-y-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
          <p className="flex items-center gap-2 text-sm font-medium text-destructive">
            <TriangleAlert className="size-4" />
            {t("ssl.refusedTitle")}
          </p>
          <ul className="space-y-1 text-sm text-destructive">
            {refusals.map((msg, i) => (
              <li key={i}>{msg}</li>
            ))}
          </ul>
          {canForce ? <p className="text-xs text-destructive/80">{t("ssl.forceHint")}</p> : null}
        </div>
      ) : null}
    </FormModal>
  );
}
