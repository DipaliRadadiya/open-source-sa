"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { APP_BAN_PRESETS, readJailValue, writeJailValue } from "@/lib/applications/fail2ban-jail-fields";
import { humanDuration } from "@/lib/fail2ban/duration";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

/**
 * The site's ban rules as plain fields, laid out like the server's Ban rules card.
 * Every change is written straight into the jail or filter text the page saves.
 */
export function BanRuleFields({
  jail,
  numbers,
  onJailChange,
  loginOffered,
  loginPath,
  loginAnswer,
  onLoginChange,
  pathProblem,
  numberProblem,
  disabled,
}) {
  const t = useTranslations("applications.fail2ban");
  const ts = useTranslations("fail2ban");
  // An empty path is only flagged once the field has been left, or on a Save attempt.
  const [pathTouched, setPathTouched] = useState(false);

  const maxretry = readJailValue(jail, "maxretry") ?? "";
  const findtime = readJailValue(jail, "findtime") ?? "";
  const bantime = readJailValue(jail, "bantime") ?? "";
  // The saved length stays a choice of its own, even "1h" written by hand.
  const presets = APP_BAN_PRESETS.map(String);
  if (bantime && !presets.includes(bantime)) presets.unshift(bantime);
  const banLabel = (seconds) => (Number(seconds) < 0 ? ts("settings.permanent") : humanDuration(ts, seconds) ?? `${seconds}`);
  const shownPathProblem = pathProblem === "invalid" || (pathProblem === "required" && pathTouched) ? pathProblem : null;

  return (
    <div className="divide-y">
      {loginOffered ? (
        <div className="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2">
          <div className="min-w-0 space-y-2">
            <Label htmlFor="fail2ban-login-path">{t("loginRule.pathLabel")}</Label>
            <Input
              id="fail2ban-login-path"
              value={loginPath}
              placeholder="/login"
              className="font-mono"
              disabled={disabled}
              aria-invalid={shownPathProblem ? true : undefined}
              aria-describedby="fail2ban-login-path-hint"
              onChange={(event) => onLoginChange(event.target.value, loginAnswer)}
              onBlur={() => setPathTouched(true)}
            />
            {/* Save's reason is only a tooltip while there are changes, so the field says it:
                as a plain hint until the field is left empty, then as an error. */}
            <p id="fail2ban-login-path-hint" className={shownPathProblem ? "text-xs text-destructive" : "text-xs text-muted-foreground"}>
              {pathProblem ? t(`loginRule.errors.${pathProblem}`) : t("loginRule.pathHint")}
            </p>
          </div>
          <div className="min-w-0 space-y-2">
            <Label htmlFor="fail2ban-login-answer">{t("loginRule.answerLabel")}</Label>
            <Select value={loginAnswer} onValueChange={(answer) => onLoginChange(loginPath, answer)} disabled={disabled}>
              <SelectTrigger id="fail2ban-login-answer" className="w-full" aria-describedby="fail2ban-login-answer-hint">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="error">{t("loginRule.answers.error")}</SelectItem>
                <SelectItem value="page">{t("loginRule.answers.page")}</SelectItem>
              </SelectContent>
            </Select>
            <p id="fail2ban-login-answer-hint" className="text-xs text-muted-foreground">{t("loginRule.answerHint")}</p>
          </div>
        </div>
      ) : null}

      {numbers ? (
        <div className="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2">
          <div className="min-w-0 space-y-2">
            <Label htmlFor="fail2ban-maxretry">{ts("settings.maxretry")}</Label>
            <Input
              id="fail2ban-maxretry"
              type="number"
              min={1}
              max={100}
              value={maxretry}
              disabled={disabled}
              aria-invalid={numberProblem === "maxretry" ? true : undefined}
              onChange={(event) => onJailChange(writeJailValue(jail, "maxretry", event.target.value))}
            />
            {numberProblem === "maxretry" ? (
              <p className="text-xs text-destructive">{t("rules.errors.maxretry")}</p>
            ) : null}
          </div>
          <div className="min-w-0 space-y-2">
            <Label htmlFor="fail2ban-findtime">{ts("settings.findtime")}</Label>
            <Input
              id="fail2ban-findtime"
              type="number"
              min={30}
              max={86400}
              value={findtime}
              disabled={disabled}
              aria-invalid={numberProblem === "findtime" ? true : undefined}
              onChange={(event) => onJailChange(writeJailValue(jail, "findtime", event.target.value))}
            />
            <p className={numberProblem === "findtime" ? "text-xs text-destructive" : "text-xs text-muted-foreground"}>
              {numberProblem === "findtime" ? t("rules.errors.findtime") : humanDuration(ts, findtime)}
            </p>
          </div>
          <div className="min-w-0 space-y-2">
            <Label htmlFor="fail2ban-bantime">{ts("settings.bantime")}</Label>
            <Select value={bantime} onValueChange={(value) => onJailChange(writeJailValue(jail, "bantime", value))} disabled={disabled}>
              <SelectTrigger id="fail2ban-bantime" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {presets.map((seconds) => (
                  <SelectItem key={seconds} value={seconds}>
                    {banLabel(seconds)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {/* The rule in one sentence, as on the server's Ban rules card. */}
          <div className="min-w-0 self-end rounded-lg bg-muted/60 px-3 py-2.5 text-sm leading-relaxed">
            {numberProblem
              ? ts("settings.previewInvalid")
              : ts.rich(Number(bantime) < 0 ? "settings.summaryPermanent" : "settings.summary", {
                  retries: maxretry,
                  window: humanDuration(ts, findtime) ?? findtime,
                  duration: banLabel(bantime),
                  strong: (chunks) => <strong className="font-semibold">{chunks}</strong>,
                })}
          </div>
        </div>
      ) : null}
    </div>
  );
}
