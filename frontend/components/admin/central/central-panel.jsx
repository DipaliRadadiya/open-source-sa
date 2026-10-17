"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useNow, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Check, Loader2, Link2Off, PlugZap, RefreshCw, ShieldAlert } from "lucide-react";
import { disableCentral, enableCentral } from "@/lib/api/central";
import { centralEnableResponseSchema } from "@/lib/schemas/central";
import { apiMessage } from "@/lib/api/error-message";
import { KeyReveal } from "@/components/admin/central/key-reveal";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Section, InfoRow } from "@/components/settings/setting-row";
import { Note } from "@/components/ui/note";
import { useBranding } from "@/hooks/use-branding";
import { cn } from "@/lib/utils";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { AutoRefresh } from "@/components/ui/auto-refresh";

// Connect and regenerate share one endpoint; pressing it while connected
// rotates the token and breaks the old one, so only that press is confirmed.
export function CentralPanel({ status }) {
  const t = useTranslations("central");
  const { name: brand } = useBranding();
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  const [token, setToken] = useState(null);
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const [acknowledged, setAcknowledged] = useState(false);

  const format = useFormatter();
  const now = useNow({ updateInterval: 30000 });

  // A key existing is not Central having it: until Central first uses the key the
  // screen waits rather than claiming a connection.
  const connected = Boolean(status?.enabled);
  const linked = connected && Boolean(status?.connected);
  const lastUsed = linked && status?.last_used_at ? new Date(status.last_used_at) : null;
  // Clock skew must not read as "in 5 seconds".
  const lastUsedAgo =
    lastUsed && !Number.isNaN(lastUsed.getTime())
      ? format.relativeTime(Math.min(lastUsed.getTime(), now.getTime()), now)
      : null;

  async function generate() {
    setPending("generate");
    try {
      const { data } = await enableCentral();
      const parsed = centralEnableResponseSchema.safeParse(data);
      if (!parsed.success) throw new Error("shape");

      // Held only in state: never in a URL, storage or logs. It is gone once
      // this component unmounts, as the backend promises.
      setToken(parsed.data.central_token);
      setAcknowledged(false);
      setConfirming(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("errors.generateFailed")));
    } finally {
      setPending(null);
    }
  }

  async function disconnect() {
    setPending("disconnect");
    try {
      await disableCentral();
      await refreshAndWait();
      setToken(null);
      setConfirming(null);
      toast.success(t("disconnected"));
    } catch (error) {
      toast.error(apiMessage(error, t("errors.disconnectFailed")));
    } finally {
      setPending(null);
    }
  }

  // Where the connection has got to: key made, Central has used it.
  const steps = [
    { key: "generate", done: connected, current: !connected, text: t("steps.generateText") },
    {
      key: "paste",
      done: linked,
      current: connected && !linked,
      text: connected && !linked ? t("steps.pasteWaiting") : t("steps.pasteText"),
    },
    {
      key: "managed",
      done: linked,
      current: false,
      text: linked && lastUsedAgo ? t("state.lastUsed", { ago: lastUsedAgo }) : t("steps.managedText"),
    },
  ];

  return (
    <>
      {/* Picks up Central's first use without a reload. */}
      {connected && !linked ? <AutoRefresh intervalMs={15000} stopAfterMs={900000} /> : null}
      <Section
        icon={PlugZap}
        title={t("cardTitle")}
        description={t("subtitle")}
        badge={
          linked ? (
            <Badge variant="success" className="font-normal">{t("state.connected")}</Badge>
          ) : connected ? (
            <Badge variant="warning" className="font-normal">{t("state.waiting")}</Badge>
          ) : (
            <Badge variant="muted" className="font-normal">{t("state.off")}</Badge>
          )
        }
        // The shown-once rule sits beside the button that creates the key.
        changedBy={!connected && !token ? t("actions.generateHint") : null}
        actions={
          token ? null : connected ? (
            <>
              <Button variant="outline" disabled={pending !== null} onClick={() => setConfirming("regenerate")}>
                {pending === "generate" ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <RefreshCw className="size-4" aria-hidden />}
                {t("actions.regenerate")}
              </Button>
              <Button variant="destructive" disabled={pending !== null} onClick={() => setConfirming("disconnect")}>
                <Link2Off className="size-4" aria-hidden />
                {t("actions.disconnect")}
              </Button>
            </>
          ) : (
            <Button disabled={pending !== null} onClick={() => setConfirming("generate")}>
              <PlugZap className="size-4" aria-hidden />
              {t("actions.generate")}
            </Button>
          )
        }
      >
        <ol className="grid gap-3 sm:grid-cols-3">
          {steps.map((step, index) => (
            <li
              key={step.key}
              className={cn(
                "flex items-start gap-3 rounded-lg border p-3",
                step.current && "border-primary/40 bg-primary/5",
              )}
            >
              <span
                className={cn(
                  "flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums",
                  step.done ? "border-success bg-success text-white" : step.current ? "border-primary text-primary" : "text-muted-foreground",
                )}
                aria-hidden
              >
                {step.done ? <Check className="size-4" /> : index + 1}
              </span>
              <div className="min-w-0 space-y-0.5">
                <p className="text-sm font-medium">
                  {t(`steps.${step.key}`, { brand })}
                  <span className="sr-only"> — {step.done ? t("steps.srDone") : step.current ? t("steps.srCurrent") : t("steps.srNext")}</span>
                </p>
                {/* Server and browser read the clock a moment apart. */}
                <p className="text-xs text-muted-foreground" suppressHydrationWarning>{step.text}</p>
              </div>
            </li>
          ))}
        </ol>

        {token ? (
          <KeyReveal token={token} onDone={() => setToken(null)} />
        ) : connected ? (
          <InfoRow label={t("current.label")} hint={t("current.lost")} className="col-span-full">
            <div className="rounded-lg border bg-muted/40 px-3 py-2">
              <code className="font-mono text-xs break-all">{status.token}</code>
            </div>
          </InfoRow>
        ) : null}

        {/* The token is not scoped: CentralUser is created with is_admin and the
            Administrator role. Stated calmly, not as an error: it is a fact to know. */}
        <Note icon={ShieldAlert} title={t("power.title")}>
          <p>{t("power.body")}</p>
          <p className="mt-1">{t("power.attribution")}</p>
        </Note>
      </Section>

      <ConfirmDialog
        open={confirming === "generate"}
        onOpenChange={(next) => {
          if (!next && pending === null) {
            setAcknowledged(false);
            setConfirming(null);
          }
        }}
        icon={ShieldAlert}
        tone="warning"
        title={t("confirmGenerate.title")}
        description={t("confirmGenerate.description", { brand })}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmGenerate.submit")}
        confirmDisabled={!acknowledged}
        pending={pending === "generate"}
        onConfirm={generate}
      >
        <div className="flex items-start gap-3 rounded-lg border bg-muted/30 p-3">
          <Checkbox
            id="central-key-acknowledgement"
            checked={acknowledged}
            onCheckedChange={(checked) => setAcknowledged(checked === true)}
          />
          <label
            htmlFor="central-key-acknowledgement"
            className="cursor-pointer text-sm leading-5 text-foreground"
          >
            {t("confirmGenerate.acknowledgement", { brand })}
          </label>
        </div>
      </ConfirmDialog>

      {/* Regenerating breaks Central immediately: the old token dies on the
          next request until the new key is pasted there. */}
      <ConfirmDialog
        open={confirming === "regenerate"}
        onOpenChange={(next) => !next && pending === null && setConfirming(null)}
        icon={RefreshCw}
        tone="warning"
        title={t("confirmRegenerate.title")}
        description={t("confirmRegenerate.description")}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmRegenerate.submit")}
        confirmVariant="default"
        pending={pending === "generate"}
        onConfirm={generate}
      />

      <ConfirmDialog
        open={confirming === "disconnect"}
        onOpenChange={(next) => !next && pending === null && setConfirming(null)}
        icon={Link2Off}
        tone="destructive"
        title={t("confirmDisconnect.title")}
        description={t("confirmDisconnect.description")}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmDisconnect.submit")}
        pending={pending === "disconnect"}
        onConfirm={disconnect}
      />
    </>
  );
}
