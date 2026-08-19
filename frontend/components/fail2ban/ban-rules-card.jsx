"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { PERMANENT_BANTIME } from "@/lib/schemas/fail2ban";
import { humanDuration } from "@/lib/fail2ban/duration";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { apiMessage } from "@/lib/api/error-message";

/**
 * The three numbers that define a ban, on their own.
 *
 * Split from the ignore list because they answer different questions — "how
 * strict is this?" versus "who is exempt?" — and one long settings card made
 * both harder to find.
 *
 * Ban time comes from the presets the API supplies: "1 hour" is a decision,
 * "3600" is arithmetic. A preset of -1 means permanent.
 *
 * Every save sends the whole settings object, including the ignore list, since
 * the backend rewrites the file as a unit — a partial payload would wipe what
 * the other card owns.
 */
export function BanRulesCard({ settings, presets, canManage }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();

  const [bantime, setBantime] = useState(String(settings.bantime));
  const [findtime, setFindtime] = useState(String(settings.findtime));
  const [maxretry, setMaxretry] = useState(String(settings.maxretry));
  const [saving, setSaving] = useState(false);
  // The write is only half the wait: the page still has to re-read fail2ban
  // before the card shows what was saved. A transition keeps one pending signal
  // across both, so the spinner stops when the screen is actually right rather
  // than when the request happens to return.
  const [refreshing, startRefresh] = useTransition();
  const pending = saving || refreshing;

  const isPermanent = Number(bantime) === PERMANENT_BANTIME;

  // A preset list without the current value would silently change it on save.
  const hasCurrent = presets.some((p) => String(p.seconds) === String(settings.bantime));

  const dirty =
    String(settings.bantime) !== bantime ||
    String(settings.findtime) !== findtime ||
    String(settings.maxretry) !== maxretry;

  async function save() {
    setSaving(true);
    try {
      await updateFail2ban({
        bantime: Number(bantime),
        findtime: Number(findtime),
        maxretry: Number(maxretry),
        ignore_ips: settings.ignore_ips ?? [],
      });
      toast.success(t("settings.saved"));
      startRefresh(() => router.refresh());
    } catch (error) {
      toast.error(
        apiMessage(error, t("settings.failed")),
      );
    } finally {
      setSaving(false);
    }
  }

  function discard() {
    setBantime(String(settings.bantime));
    setFindtime(String(settings.findtime));
    setMaxretry(String(settings.maxretry));
  }

  const saveReason = !canManage
    ? t("disabled.noPermission")
    : !dirty
      ? t("disabled.noChanges")
      : null;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Card className="h-full">
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t("settings.title")}</CardTitle>
          <CardDescription>{t("settings.description")}</CardDescription>
        </CardHeader>
  
        {/* One per row. In half a page three fields across would each be too
            narrow for the hint under them, and stacked this card comes out about
            the height of the ignore list beside it — which is what lets the two
            sit together at all. They still read in order as a sentence: N
            failures, within M seconds, costs you X. */}
        <CardContent className="space-y-4">
          {/* Three separate numbers are three separate facts; the thing anyone
              actually wants to know is what they add up to. This says it as a
              sentence, from the values in the form — so it also previews an edit
              before you commit it. */}
          <p className="rounded-lg bg-muted/60 px-3 py-2.5 text-sm leading-relaxed">
            {t.rich(isPermanent ? "settings.summaryPermanent" : "settings.summary", {
              retries: maxretry || "—",
              window: humanDuration(t, findtime) ?? findtime,
              duration: humanDuration(t, bantime) ?? bantime,
              strong: (chunks) => <strong className="font-semibold">{chunks}</strong>,
            })}
          </p>
  
          <div className="space-y-2">
            <Label htmlFor="f2b-maxretry">{t("settings.maxretry")}</Label>
            <Input
              id="f2b-maxretry"
              placeholder="5"
              type="number"
              min={2}
              value={maxretry}
              onChange={(e) => setMaxretry(e.target.value)}
              // Locked mid-save: the refresh that follows would overwrite an
              // edit made while the request was in the air, without saying so.
              disabled={!canManage || pending}
            />
            <p className="text-xs text-muted-foreground">{t("settings.maxretryHint")}</p>
          </div>
  
          <div className="space-y-2">
            <Label htmlFor="f2b-findtime">{t("settings.findtime")}</Label>
            <Input
              id="f2b-findtime"
              placeholder="600"
              type="number"
              min={30}
              value={findtime}
              onChange={(e) => setFindtime(e.target.value)}
              disabled={!canManage || pending}
            />
            {/* The field must stay in seconds — that is what the file stores —
                so the words go beside it rather than replacing it. */}
            <p className="text-xs text-muted-foreground">
              {humanDuration(t, findtime) ? `${humanDuration(t, findtime)} — ` : null}
              {t("settings.findtimeHint")}
            </p>
          </div>
  
          <div className="space-y-2">
            <Label htmlFor="f2b-bantime">{t("settings.bantime")}</Label>
            <Select value={bantime} onValueChange={setBantime} disabled={!canManage || pending}>
              <SelectTrigger id="f2b-bantime" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {!hasCurrent ? (
                  <SelectItem value={String(settings.bantime)}>
                    {t("settings.currentSeconds", { seconds: settings.bantime })}
                  </SelectItem>
                ) : null}
                {presets.map((p) => (
                  <SelectItem key={p.key} value={String(p.seconds)}>
                    {p.seconds === PERMANENT_BANTIME ? t("settings.permanent") : p.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{t("settings.bantimeHint")}</p>
          </div>
        </CardContent>
  
        {/* The shared footer, like every other settings card in the panel. This
            one used to hand-roll its own, which is how it ended up the only
            save on the page with no spinner and no "Saving…" — a fail2ban
            reload takes seconds, so a button that only greys out reads as a
            hang. */}
        <div className="mt-auto">
          <CardSaveFooter
            saving={pending}
            dirty={dirty}
            saveReason={saveReason}
            onSave={save}
            onDiscard={discard}
            saveLabel={t("settings.save")}
            savingNote={t("settings.savingNote")}
          />
        </div>
      </Card>
    </DisabledReasonProvider>
  );
}
