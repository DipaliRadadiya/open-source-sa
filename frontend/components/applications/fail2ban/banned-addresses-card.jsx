"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Ban, Loader2, ShieldX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { banApplicationIp, unbanApplicationIp } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { isIpAddress } from "@/lib/validation/ip";
import { useRefresh } from "@/hooks/use-refresh";

// This application's own jail (FS-C45): a ban here blocks one site, not the server.
export function BannedAddressesCard({ appId, bans, canManage }) {
  const t = useTranslations("applications.fail2ban.bans");
  const { refreshAndWait } = useRefresh();
  const [ip, setIp] = useState("");
  const [fieldError, setFieldError] = useState(null);
  const [banning, setBanning] = useState(false);
  const [unbanning, setUnbanning] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const running = Boolean(bans.jail);
  const blocked = !canManage ? t("noPermission") : !running ? t("notRunning") : null;

  async function ban(event) {
    event.preventDefault();
    const value = ip.trim();
    if (!isIpAddress(value)) {
      setFieldError(t("invalidIp"));
      return;
    }
    setBanning(true);
    setFieldError(null);
    try {
      await banApplicationIp(appId, value);
      await refreshAndWait();
      toast.success(t("banned", { ip: value }));
      setIp("");
    } catch (error) {
      // The server's own address and the caller's come back as a bare 422 sentence; it
      // still belongs under the field that was typed.
      const message =
        error.response?.data?.errors?.ip?.[0] ??
        (error.response?.status === 422 ? error.response?.data?.message : null);
      if (message) setFieldError(message);
      else toast.error(apiMessage(error, t("banFailed")));
    } finally {
      setBanning(false);
    }
  }

  async function unban() {
    const value = confirm;
    setUnbanning(value);
    try {
      await unbanApplicationIp(appId, value);
      await refreshAndWait();
      toast.success(t("unbanned", { ip: value }));
      setConfirm(null);
    } catch (error) {
      toast.error(apiMessage(error, t("unbanFailed")));
    } finally {
      setUnbanning(null);
    }
  }

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <CardContent className="space-y-4 px-5 py-4">
        <div className="space-y-1">
          <h2 className="text-[15px] font-semibold tracking-tight">{t("title")}</h2>
          <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>

        {bans.failed ? (
          <p className="text-sm text-destructive">{bans.message ?? t("loadFailed")}</p>
        ) : bans.banned.length ? (
          <ul className="divide-y rounded-lg border">
            {bans.banned.map((address) => (
              <li key={address} className="flex items-center gap-3 px-3 py-2">
                <ShieldX className="size-4 shrink-0 text-destructive" aria-hidden />
                <span className="min-w-0 flex-1 truncate font-mono text-sm">{address}</span>
                <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={!canManage || unbanning === address}
                    onClick={() => setConfirm(address)}
                  >
                    {unbanning === address ? <Loader2 className="size-4 animate-spin" /> : null}
                    {t("unban")}
                  </Button>
                </ReasonTooltip>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">{running ? t("none") : t("notRunning")}</p>
        )}

        <form onSubmit={ban} className="space-y-1.5" noValidate>
          <Label htmlFor="app-ban-ip">{t("banLabel")}</Label>
          <div className="flex flex-wrap gap-2">
            <Input
              id="app-ban-ip"
              value={ip}
              onChange={(event) => {
                setIp(event.target.value);
                if (fieldError) setFieldError(null);
              }}
              placeholder="203.0.113.10"
              autoComplete="off"
              spellCheck={false}
              aria-invalid={Boolean(fieldError)}
              disabled={Boolean(blocked) || banning}
              className="min-w-48 flex-1 font-mono sm:max-w-xs"
            />
            <ReasonTooltip reason={blocked ?? (ip.trim() ? null : t("typeIp"))}>
              <Button type="submit" variant="outline" disabled={Boolean(blocked) || banning || !ip.trim()}>
                {banning ? <Loader2 className="size-4 animate-spin" /> : <Ban className="size-4" />}
                {t("ban")}
              </Button>
            </ReasonTooltip>
          </div>
          {fieldError ? <p className="text-xs text-destructive">{fieldError}</p> : null}
        </form>
      </CardContent>

      <ConfirmDialog
        open={Boolean(confirm)}
        onOpenChange={(next) => !next && !unbanning && setConfirm(null)}
        icon={ShieldX}
        tone="warning"
        title={t("unbanTitle", { ip: confirm ?? "" })}
        description={t("unbanBody")}
        cancelLabel={t("cancel")}
        confirmLabel={t("unban")}
        pending={Boolean(unbanning)}
        onConfirm={unban}
      />
    </Card>
  );
}
