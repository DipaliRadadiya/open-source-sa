"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { PERMANENT_BANTIME } from "@/lib/schemas/fail2ban";
import { humanDuration } from "@/lib/fail2ban/duration";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
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
  const [pending, setPending] = useState(false);

  const isPermanent = Number(bantime) === PERMANENT_BANTIME;

  // A preset list without the current value would silently change it on save.
  const hasCurrent = presets.some((p) => String(p.seconds) === String(settings.bantime));

  const dirty =
    String(settings.bantime) !== bantime ||
    String(settings.findtime) !== findtime ||
    String(settings.maxretry) !== maxretry;

  async function save() {
    setPending(true);
    try {
      await updateFail2ban({
        bantime: Number(bantime),
        findtime: Number(findtime),
        maxretry: Number(maxretry),
        ignore_ips: settings.ignore_ips ?? [],
      });
      toast.success(t("settings.saved"));
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      toast.error(
        [apiMessage(error, t("settings.failed")), data?.reference].filter(Boolean).join(" · "),
      );
    } finally {
      setPending(false);
    }
  }

  return (
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
            type="number"
            min={2}
            value={maxretry}
            onChange={(e) => setMaxretry(e.target.value)}
            disabled={!canManage}
          />
          <p className="text-xs text-muted-foreground">{t("settings.maxretryHint")}</p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="f2b-findtime">{t("settings.findtime")}</Label>
          <Input
            id="f2b-findtime"
            type="number"
            min={30}
            value={findtime}
            onChange={(e) => setFindtime(e.target.value)}
            disabled={!canManage}
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
          <Select value={bantime} onValueChange={setBantime} disabled={!canManage}>
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

      <CardFooter className="mt-auto justify-end gap-2">
        {dirty ? (
          <Button
            variant="ghost"
            disabled={pending}
            onClick={() => {
              setBantime(String(settings.bantime));
              setFindtime(String(settings.findtime));
              setMaxretry(String(settings.maxretry));
            }}
          >
            {t("settings.reset")}
          </Button>
        ) : null}
        {/* Three different reasons this can be off, and the button says which. */}
        <ReasonTooltip
          reason={
            !canManage
              ? t("disabled.noPermission")
              : pending
                ? t("disabled.saving")
                : !dirty
                  ? t("disabled.noChanges")
                  : null
          }
        >
          <Button disabled={!canManage || !dirty || pending} onClick={save}>
            {t("settings.save")}
          </Button>
        </ReasonTooltip>
      </CardFooter>
    </Card>
  );
}
