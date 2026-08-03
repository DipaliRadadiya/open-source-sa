"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { banIp } from "@/lib/api/fail2ban";
import { isIpAddress } from "@/lib/validation/ip";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Ban one address by hand.
 *
 * A ban belongs to a jail, so the jail is asked for rather than guessed — the
 * API needs it, and "banned, but from what?" is not a question the UI should
 * leave open.
 */
export function BanIpDialog({ jails = [], canManage }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [ip, setIp] = useState("");
  const [jail, setJail] = useState(jails[0]?.name ?? "");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  async function submit(event) {
    event.preventDefault();
    // The endpoint takes one address, not a range. Saying so here beats a round
    // trip to be told the same thing in less friendly words.
    if (!isIpAddress(ip)) {
      setError(t("ban.invalidIp"));
      return;
    }
    setPending(true);
    setError(null);
    try {
      await banIp(ip.trim(), jail);
      toast.success(t("ban.banned", { ip: ip.trim() }));
      setOpen(false);
      setIp("");
      router.refresh();
    } catch (err) {
      const data = err.response?.data;
      // 422 is usually "that address is on the ignore list" — the ban would be
      // dropped at the next reload, so the server's reason is the useful text.
      setError(apiMessage(error, t("ban.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (pending) return;
        setOpen(next);
        if (!next) setError(null);
      }}
    >
      <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
        <DialogTrigger asChild>
          <Button disabled={!canManage}>
            <Plus className="size-4" />
            {t("ban.action")}
          </Button>
        </DialogTrigger>
      </ReasonTooltip>
      <DialogContent>
        <form onSubmit={submit}>
          <DialogHeader>
            <DialogTitle>{t("ban.title")}</DialogTitle>
            <DialogDescription className="pt-1">{t("ban.description")}</DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="ban-ip">{t("ban.ipLabel")}</Label>
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
              {/* The API takes a single address, not a range — say so here
                  rather than letting the server reject it. */}
              <p className="text-xs text-muted-foreground">{t("ban.ipHint")}</p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="ban-jail">{t("ban.jailLabel")}</Label>
              <Select value={jail} onValueChange={setJail}>
                <SelectTrigger id="ban-jail" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {jails.map((j) => (
                    <SelectItem key={j.name} value={j.name}>
                      {j.label}
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
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
              disabled={pending}
            >
              {t("ban.cancel")}
            </Button>
            <Button type="submit" disabled={pending || !ip.trim() || !jail}>
              {t("ban.submit")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
