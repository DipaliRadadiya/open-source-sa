"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, ShieldCheck, TriangleAlert } from "lucide-react";
import { issueCertificate } from "@/lib/api/domains";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { FormModal } from "@/components/ui/form-modal";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const TYPES = ["letsencrypt", "self_signed", "custom"];

export function IssueCertDialog({ appId, open, onOpenChange, onIssued }) {
  const t = useTranslations("applications.domains");
  const [type, setType] = useState("letsencrypt");
  const [pem, setPem] = useState({ certificate: "", private_key: "", chain: "" });
  const [submitting, setSubmitting] = useState(false);
  // Per-domain reachability refusals (422 errors.domain). Their presence is what
  // unlocks the "issue anyway" (force) path — never offered up front.
  const [refusals, setRefusals] = useState([]);

  function reset() {
    setType("letsencrypt");
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

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      icon={ShieldCheck}
      title={t("ssl.issueTitle")}
      description={t("ssl.issueSubtitle")}
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
          <Button type="button" disabled={submitting} onClick={() => submit(false)}>
            {submitting && <Loader2 className="size-4 animate-spin" />}
            {t("ssl.issue")}
          </Button>
        </>
      }
    >
      <div className="space-y-1.5">
        <Label>{t("ssl.method")}</Label>
        <Select value={type} onValueChange={(v) => { setType(v); setRefusals([]); }}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {TYPES.map((value) => (
              <SelectItem key={value} value={value}>
                {t(`ssl.method_${value}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">{t(`ssl.methodHint_${type}`)}</p>
      </div>

      {type === "self_signed" ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-sm text-warning">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          <span>{t("ssl.selfSignedWarning")}</span>
        </p>
      ) : null}

      {type === "custom" ? (
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label>{t("ssl.certificate")}</Label>
            <Textarea
              rows={4}
              className="font-mono text-xs"
              placeholder="-----BEGIN CERTIFICATE-----"
              value={pem.certificate}
              onChange={(e) => setPem((p) => ({ ...p, certificate: e.target.value }))}
            />
          </div>
          <div className="space-y-1.5">
            <Label>{t("ssl.privateKey")}</Label>
            <Textarea
              rows={4}
              className="font-mono text-xs"
              placeholder="-----BEGIN PRIVATE KEY-----"
              value={pem.private_key}
              onChange={(e) => setPem((p) => ({ ...p, private_key: e.target.value }))}
            />
          </div>
          <div className="space-y-1.5">
            <Label>
              {t("ssl.chain")} <span className="text-muted-foreground">({t("ssl.optional")})</span>
            </Label>
            <Textarea
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
