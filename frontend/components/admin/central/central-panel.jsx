"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
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

/**
 * Connect this server to a central panel, and the two ways to undo it.
 *
 * Three states on one page: not connected, the key shown once, connected.
 *
 * The sharp edge is that "connect" and "regenerate" are the SAME endpoint.
 * Pressing it while a connection is live rotates the token and the old one
 * stops working on the next request — so the second press is a breaking change
 * and is the only one put behind a confirmation.
 */
export function CentralPanel({ status }) {
  const t = useTranslations("central");
  const router = useRouter();

  const [token, setToken] = useState(null);
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const [acknowledged, setAcknowledged] = useState(false);

  const connected = Boolean(status?.enabled);

  async function generate() {
    setPending("generate");
    try {
      const { data } = await enableCentral();
      const parsed = centralEnableResponseSchema.safeParse(data);
      if (!parsed.success) throw new Error("shape");

      // Held in state and nowhere else: never a URL, never storage, never a
      // log line. When this component unmounts the value is gone for good,
      // which is exactly what the backend promises.
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
      setToken(null);
      setConfirming(null);
      toast.success(t("disconnected"));
      router.refresh();
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
            {/* Not `title` — that is the page heading directly above, and the
                two stacked read as the same sentence printed twice. */}
            <CardTitle className="flex items-center gap-2 text-base font-semibold">
              {t("cardTitle")}
              {connected ? (
                <Badge variant="success" className="font-normal">
                  {t("connected")}
                </Badge>
              ) : null}
            </CardTitle>
            <CardDescription>{t("subtitle")}</CardDescription>
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* What the key actually grants, stated before it exists. It is not
            scoped and cannot be: CentralUser is created with is_admin and the
            Administrator role, so the token is an administrator on every
            endpoint. Stripe is the only surveyed product that says this
            plainly about its own keys, and it is the single most important
            sentence on the page. */}
        <div className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <ShieldAlert className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
          <div className="min-w-0 space-y-1">
            <p className="text-sm font-medium text-destructive">{t("power.title")}</p>
            <p className="text-sm text-muted-foreground">{t("power.body")}</p>
            {/* The reassuring half, and true: actions arrive under a separate
                machine account, so the log can tell the vendor apart from you. */}
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
              {/* Closes the loop the shown-once rule opens. Once the reveal is
                  dismissed this mask is all anyone ever sees again, and the
                  obvious next question — "what if I lost it?" — has an answer
                  with a consequence attached. */}
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
                variant="outline"
                size="sm"
                className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
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
            {/* Said before the press, not only after it. Nobody should meet the
                shown-once rule for the first time while looking at the secret. */}
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

      {/* Regenerating is the destructive one, and the damage is on a screen the
          reader cannot see: the old token dies on the next request, so Central
          breaks the instant this is confirmed and stays broken until the new
          key is pasted there. */}
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
