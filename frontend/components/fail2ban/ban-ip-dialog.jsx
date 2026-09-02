import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Plus, ShieldBan, TriangleAlert } from "lucide-react";
import { banIp } from "@/lib/api/fail2ban";
import { isIpAddress } from "@/lib/validation/ip";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormModal } from "@/components/ui/form-modal";
import { useNavTransition } from "@/components/data-table/nav-transition";
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
 *
 * On FormModal like every other form in the panel. It used to build its own
 * shell, and was the only dialog anywhere with no icon in its header — the one
 * that looked wrong at a glance without anyone being able to say why.
 */
export function BanIpDialog({ jails = [], canManage, yourIp = null }) {
  const t = useTranslations("fail2ban");
  // Refreshing through the list's own transition rather than the router
  // directly: the ban lands on the server long before the page has re-read it,
  // and this is what makes the list say so instead of standing still. The
  // fallback keeps the dialog usable outside a provider, same as RefreshButton.
  const nav = useNavTransition();
  const router = useRouter();
  const [, startLocal] = useTransition();
  const refresh = nav ? nav.refresh : () => startLocal(() => router.refresh());
  const [open, setOpen] = useState(false);
  const [ip, setIp] = useState("");
  // The chosen jail, or null while nothing has been chosen. A plain
  // `useState(jails[0]?.name ?? "")` ran once, at mount: if the list arrived
  // empty it stayed "" for good, and Submit — which is disabled on `!jail` —
  // could never be pressed again however many jails turned up later.
  const [chosen, setChosen] = useState(null);
  const jail = chosen ?? jails[0]?.name ?? "";
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  const isSelf = Boolean(yourIp) && ip.trim() === yourIp;

  function handleOpenChange(next) {
    if (pending) return;
    setOpen(next);
    if (!next) setError(null);
  }

  async function submit(event) {
    event.preventDefault();
    if (!isIpAddress(ip.trim())) {
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
      refresh();
    } catch (err) {
      // 422 is usually "that address is on the ignore list" — the ban would be
      // dropped at the next reload, so the server's reason is the useful text.
      // This read `apiMessage(error, …)` — the state variable, which is null at
      // this point — so every failure showed the generic fallback and the
      // server's own sentence was thrown away.
      setError(apiMessage(err, t("ban.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
        <Button disabled={!canManage} onClick={() => setOpen(true)}>
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
            <Button type="submit" disabled={pending || !ip.trim() || !jail}>
              {pending && <Loader2 className="size-4 animate-spin" />}
              {t("ban.submit")}
            </Button>
          </>
        }
      >
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

          {/* The one ban you cannot undo from this screen. Every other control
              on this page guards against locking yourself out — the ignore
              list warns when your address is missing, confirms before you
              remove it, and a lockout-risk jail asks you to acknowledge — but
              the dialog that bans an address by hand knew nothing about it.

              Worded as "the address this panel was reached from", not "your
              address": `your_ip` is whatever the API saw make the request, and
              this page is server-rendered, so on a real install it is the
              panel's own server. Claiming it is definitely you would be the
              same overclaim the jails card refuses to make. */}
          {isSelf ? (
            <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2.5 text-xs leading-relaxed text-warning">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {t("ban.selfWarning")}
            </p>
          ) : null}
        </div>

        <div className="space-y-2">
          <Label htmlFor="ban-jail">{t("ban.jailLabel")}</Label>
          <Select value={jail} onValueChange={setChosen}>
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
      </FormModal>
    </>
  );
}
