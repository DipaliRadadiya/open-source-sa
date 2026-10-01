import { useState } from "react";
import { useTranslations } from "next-intl";
import { jailLabel } from "@/lib/fail2ban/jail-label";
import { toast } from "sonner";
import { Loader2, Plus, ShieldBan, TriangleAlert } from "lucide-react";
import { banIp } from "@/lib/api/fail2ban";
import { isIpAddress } from "@/lib/validation/ip";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormModal } from "@/components/ui/form-modal";
import { useRefresh } from "@/hooks/use-refresh";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { apiMessage } from "@/lib/api/error-message";

/** Bans one address by hand, in a jail the user chooses (the API requires one). */
export function BanIpDialog({ jails = [], canManage, yourIp = null, serverIp = null }) {
  const t = useTranslations("fail2ban");
  // Refreshes through the list's transition so the list dims until it re-reads.
  const { refreshAndWait } = useRefresh();
  const [open, setOpen] = useState(false);
  const [ip, setIp] = useState("");
  // Null until chosen, so the default follows the jail list as it changes.
  const [chosen, setChosen] = useState(null);
  // Only enabled jails: the API refuses a ban in any other.
  const activeJails = jails.filter((j) => j.enabled);
  const jail = chosen ?? activeJails[0]?.name ?? "";
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  const isSelf = Boolean(yourIp) && ip.trim() === yourIp;

  // Reset on both open and close: the trigger's `setOpen(true)` bypasses
  // onOpenChange, so clearing on close alone is not enough.
  function resetFields() {
    setIp("");
    setError(null);
  }

  function handleOpenChange(next) {
    if (pending) return;
    setOpen(next);
    if (!next) resetFields();
  }

  function openDialog() {
    resetFields();
    setOpen(true);
  }

  async function submit(event) {
    event.preventDefault();
    if (!isIpAddress(ip.trim())) {
      setError(t("ban.invalidIp"));
      return;
    }
    // The API refuses 127.0.0.1 but not the server's public IP, whose ban
    // locks everyone out of the panel.
    if (serverIp && ip.trim() === serverIp) {
      setError(t("ban.ownServer"));
      return;
    }
    setPending(true);
    setError(null);
    try {
      await banIp(ip.trim(), jail);
      await refreshAndWait();
      toast.success(t("ban.banned", { ip: ip.trim() }));
      setOpen(false);
      resetFields();
    } catch (err) {
      // 422 is usually "on the ignore list"; the server's reason is the useful text.
      setError(apiMessage(err, t("ban.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
        <Button disabled={!canManage} onClick={openDialog}>
          <Plus className="size-4" />
          {t("ban.action")}
        </Button>
      </ReasonTooltip>

      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={submit}
        icon={ShieldBan}
        title={t("ban.title")}
        description={t("ban.description")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={pending}
            >
              {t("ban.cancel")}
            </Button>
            <Button
              type="submit"
              disabled={pending || !ip.trim() || !jail || isSelf}
              disabledReason={isSelf ? t("ban.selfWarning") : null}
            >
              {pending && <Loader2 className="size-4 animate-spin" />}
              {t("ban.submit")}
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          <Label htmlFor="ban-ip" hint={t("ban.ipLabelHint")}>{t("ban.ipLabel")}</Label>
          <Input
            id="ban-ip"
            value={ip}
            onChange={(e) => {
              setIp(e.target.value);
              if (error) setError(null);
            }}
            placeholder="198.51.100.9"
            autoComplete="off"
            spellCheck={false}
            className="font-mono"
            required
          />
          {/* The API takes a single address, not a range. */}
          <p className="text-xs text-muted-foreground">{t("ban.ipHint")}</p>

          {/* Warns when the address is the user's own (from the browser); the
              API refuses that ban. */}
          {isSelf ? (
            <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2.5 text-xs leading-relaxed text-warning">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {t("ban.selfWarning")}
            </p>
          ) : null}
        </div>

        <div className="space-y-2">
          <Label htmlFor="ban-jail" hint={t("ban.jailLabelHint")}>{t("ban.jailLabel")}</Label>
          <Select value={jail} onValueChange={setChosen}>
            <SelectTrigger id="ban-jail" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {activeJails.map((j) => (
                <SelectItem key={j.name} value={j.name}>
                  {jailLabel(t, j)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
      </FormModal>
    </>
  );
}
