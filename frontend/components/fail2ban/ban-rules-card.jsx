"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
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

// Ban time -1 means permanent. Every save sends the whole settings object,
// since the backend rewrites the file as a unit.
export function BanRulesCard({ settings, presets, canManage }) {
  const t = useTranslations("fail2ban");
  const { refreshAndWait } = useRefresh();

  const [bantime, setBantime] = useState(String(settings.bantime));
  const [findtime, setFindtime] = useState(String(settings.findtime));
  const [maxretry, setMaxretry] = useState(String(settings.maxretry));
  const [saving, setSaving] = useState(false);
  const pending = saving;

  const isPermanent = Number(bantime) === PERMANENT_BANTIME;

  // A preset list without the current value would silently change it on save.
  const hasCurrent = presets.some((p) => String(p.seconds) === String(settings.bantime));

  // Mirrors UpdateFail2banRequest's bounds so errors show on the field.
  const inRange = (value, min, max) => /^\d+$/.test(value) && Number(value) >= min && Number(value) <= max;
  const maxretryError = inRange(maxretry, 2, 100) ? null : t("settings.maxretryRange");
  const findtimeError = inRange(findtime, 30, 86400) ? null : t("settings.findtimeRange");

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
      await refreshAndWait();
      toast.success(t("settings.saved"));
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
      : maxretryError || findtimeError;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Card className="h-full">
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t("settings.title")}</CardTitle>
          <CardDescription>{t("settings.description")}</CardDescription>
        </CardHeader>
  
        {/* One field per row: three across is too narrow for their hints. */}
        <CardContent className="space-y-4">
          {/* The rules as one sentence, from the form values, so it previews edits. */}
          <p className="rounded-lg bg-muted/60 px-3 py-2.5 text-sm leading-relaxed">
            {t.rich(isPermanent ? "settings.summaryPermanent" : "settings.summary", {
              retries: maxretry || "—",
              window: humanDuration(t, findtime) ?? findtime,
              duration: humanDuration(t, bantime) ?? bantime,
              strong: (chunks) => <strong className="font-semibold">{chunks}</strong>,
            })}
          </p>
  
          <div className="space-y-2">
            <Label htmlFor="f2b-maxretry" hint={t("settings.maxretryHint")}>{t("settings.maxretry")}</Label>
            <Input
              id="f2b-maxretry"
              placeholder="5"
              type="number"
              // The API's bounds.
              min={2}
              max={100}
              value={maxretry}
              aria-invalid={maxretryError ? true : undefined}
              aria-describedby={maxretryError ? "f2b-maxretry-error" : undefined}
              onChange={(e) => setMaxretry(e.target.value)}
              // Locked mid-save: the following refresh would overwrite an edit.
              disabled={!canManage || pending}
            />
            {maxretryError ? (
              <p id="f2b-maxretry-error" className="text-xs text-destructive">{maxretryError}</p>
            ) : null}
          </div>
  
          <div className="space-y-2">
            <Label htmlFor="f2b-findtime">{t("settings.findtime")}</Label>
            <Input
              id="f2b-findtime"
              placeholder="600"
              type="number"
              min={30}
              max={86400}
              value={findtime}
              aria-invalid={findtimeError ? true : undefined}
              aria-describedby={findtimeError ? "f2b-findtime-error" : undefined}
              onChange={(e) => setFindtime(e.target.value)}
              disabled={!canManage || pending}
            />
            {/* The field stays in seconds (what the file stores); words go beside it. */}
            {findtimeError ? (
              <p id="f2b-findtime-error" className="text-xs text-destructive">{findtimeError}</p>
            ) : null}
            <p className="text-xs text-muted-foreground">
              {humanDuration(t, findtime) ? `${humanDuration(t, findtime)} — ` : null}
              {t("settings.findtimeHint")}
            </p>
          </div>
  
          <div className="space-y-2">
            <Label htmlFor="f2b-bantime" hint={t("settings.bantimeHint")}>{t("settings.bantime")}</Label>
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
          </div>
        </CardContent>
  
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
