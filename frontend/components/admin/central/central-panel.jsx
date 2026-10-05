"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useNow, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Link2Off, PlugZap, RefreshCw, ShieldAlert } from "lucide-react";
import { disableCentral, enableCentral } from "@/lib/api/central";
import { centralEnableResponseSchema } from "@/lib/schemas/central";
import { apiMessage } from "@/lib/api/error-message";
import { KeyReveal } from "@/components/admin/central/key-reveal";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { AutoRefresh } from "@/components/ui/auto-refresh";

// Connect and regenerate share one endpoint; pressing it while connected
// rotates the token and breaks the old one, so only that press is confirmed.
export function CentralPanel({ status }) {
  const t = useTranslations("central");
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

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-48 space-y-1">
            {/* Not `title`: that duplicates the page heading directly above. */}
            <CardTitle className="flex items-center gap-2 text-base font-semibold">
              {t("cardTitle")}
              {linked ? (
                <Badge variant="success" className="font-normal">
                  {t("state.connected")}
                </Badge>
              ) : connected ? (
                <Badge variant="warning" className="font-normal">
                  {t("state.waiting")}
                </Badge>
              ) : null}
            </CardTitle>
            <CardDescription>{t("subtitle")}</CardDescription>
            {linked && lastUsedAgo ? (
              // Server and browser read the clock a moment apart; same as the cron "next run".
              <p className="text-xs text-muted-foreground" suppressHydrationWarning>
                {t("state.lastUsed", { ago: lastUsedAgo })}
              </p>
            ) : connected && !linked ? (
              <p className="text-xs text-muted-foreground">{t("state.waitingHint")}</p>
            ) : null}
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Picks up Central's first use without a reload. */}
        {connected && !linked ? <AutoRefresh intervalMs={15000} stopAfterMs={900000} /> : null}
        {/* The token is not scoped: CentralUser is created with is_admin and
            the Administrator role, so it grants admin on every endpoint. */}
        <div className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <ShieldAlert className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
          <div className="min-w-0 space-y-1">
            <p className="text-sm font-medium text-destructive">{t("power.title")}</p>
            <p className="text-sm text-muted-foreground">{t("power.body")}</p>
            {/* Actions arrive under a separate machine account, so the log can
                tell them apart from the user's. */}
            <p className="text-sm text-muted-foreground">{t("power.attribution")}</p>
          </div>
        </div>

        {token ? (
          <KeyReveal token={token} onDone={() => setToken(null)} />
        ) : connected ? (
          <>
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-muted-foreground">{t("current.label")}</p>
              <div className="rounded-lg border bg-muted/40 px-3 py-2">
                <code className="font-mono text-xs break-all">{status.token}</code>
              </div>
              <p className="text-xs text-muted-foreground">{t("current.lost")}</p>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={pending !== null}
                onClick={() => setConfirming("regenerate")}
              >
                {pending === "generate" ? (
                  <Loader2 className="size-3.5 animate-spin" aria-hidden />
                ) : (
                  <RefreshCw className="size-3.5" aria-hidden />
                )}
                {t("actions.regenerate")}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                disabled={pending !== null}
                onClick={() => setConfirming("disconnect")}
              >
                <Link2Off className="size-3.5" aria-hidden />
                {t("actions.disconnect")}
              </Button>
            </div>
          </>
        ) : (
          <div className="space-y-2">
            <Button disabled={pending !== null} onClick={() => setConfirming("generate")}>
              <PlugZap className="size-4" aria-hidden />
              {t("actions.generate")}
            </Button>
            {/* States the shown-once rule before the key is generated. */}
            <p className="text-xs text-muted-foreground">{t("actions.generateHint")}</p>
          </div>
        )}
      </CardContent>

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
        description={t("confirmGenerate.description")}
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
            {t("confirmGenerate.acknowledgement")}
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
    </Card>
  );
}
