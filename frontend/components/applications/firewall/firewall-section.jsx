"use client";

import { useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { ChevronDown, Lightbulb, ShieldCheck, Sliders } from "lucide-react";
import { cn } from "@/lib/utils";
import { Note } from "@/components/ui/note";
import { updateApplicationWaf } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Card, CardContent } from "@/components/ui/card";
import { ChoiceField } from "@/components/ui/choice-field";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { RuleList } from "@/components/applications/firewall/rule-list";

// Local plain-language hints per category (the API sends only a title). A
// category the backend adds later renders title-only instead of throwing.
const DESCRIBED_CATEGORIES = new Set([
  "query_string",
  "request_uri",
  "user_agent",
  "referrer",
  "cookie",
  "method",
]);


const COLLAPSIBLE_ANIMATION =
  "overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down";

function sameList(a, b) {
  return a.length === b.length && a.every((value, index) => value === b[index]);
}

// Saves everything in one call: the API is a single atomic PUT.
export function FirewallSection({ appId, application, categories: catalog, modes, canManage, detectCount = 0, detectFailed = false }) {
  const t = useTranslations("applications.firewall");
  const { refreshAndWait } = useRefresh();

  const saved = {
    enabled: application.waf_enabled ?? false,
    mode: application.waf_mode ?? "detect",
    categories: application.waf_categories ?? [],
    exceptions: application.waf_exceptions ?? [],
    blocks: application.waf_custom_rules ?? [],
  };

  const [enabled, setEnabled] = useState(saved.enabled);
  const [mode, setMode] = useState(saved.mode);
  const [active, setActive] = useState(saved.categories);
  const [exceptions, setExceptions] = useState(saved.exceptions);
  const [blocks, setBlocks] = useState(saved.blocks);
  const [saving, setSaving] = useState(false);
  // Opened by default only when the site already has exceptions or blocks.
  const [advancedOpen, setAdvancedOpen] = useState(
    saved.exceptions.length > 0 || saved.blocks.length > 0,
  );

  const isDirty =
    enabled !== saved.enabled ||
    mode !== saved.mode ||
    !sameList(active, saved.categories) ||
    !sameList(exceptions, saved.exceptions) ||
    !sameList(blocks, saved.blocks);

  // Without this a sidebar click discards the edit with no warning.
  useWatchUnsaved("app-firewall", isDirty);

  const saveReason = !canManage ? t("noPermission") : !isDirty ? t("nothingToSave") : null;

  // Badge, tint and hint describe the saved state; a local save is held until
  // the refreshed page agrees, so they do not lag the toast.
  const [justSaved, setJustSaved] = useState(null);
  if (justSaved && justSaved.enabled === saved.enabled && justSaved.mode === saved.mode) setJustSaved(null);
  const live = justSaved ?? saved;
  const blocking = live.enabled && live.mode === "enforce";
  // Watch mode is not protection, so it must not use the success colour.
  const statusVariant = blocking ? "success" : live.enabled ? "warning" : "muted";
  const statusLabel = blocking ? t("statusBlocking") : live.enabled ? t("statusWatching") : t("statusOff");
  // The log exists only once watch mode is saved and running.
  const showDetectLog = saved.enabled && saved.mode === "detect";

  function toggleCategory(value, checked) {
    setActive((prev) => {
      if (checked) return catalog.map((item) => item.value).filter((v) => prev.includes(v) || v === value);
      return prev.filter((v) => v !== value);
    });
  }

  async function save() {
    setSaving(true);
    try {
      await updateApplicationWaf(appId, {
        enabled,
        mode,
        // Never an empty array: the backend reads `categories: []` as "all six".
        categories: active.length > 0 ? active : catalog.map((item) => item.value),
        exceptions,
        custom_rules: blocks,
      });
      setJustSaved({ enabled, mode });
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  function discard() {
    setEnabled(saved.enabled);
    setMode(saved.mode);
    setActive(saved.categories);
    setExceptions(saved.exceptions);
    setBlocks(saved.blocks);
  }

  const locked = !canManage || saving;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="max-w-4xl space-y-4">
        <Note icon={ShieldCheck}>{t("explainer")}</Note>
  
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          <CardContent className="space-y-5 p-5">
            {/* A real <label> so the whole row toggles, not just the switch. */}
            <label
              className={cn(
                "flex flex-col gap-3 rounded-xl border p-4 transition-colors sm:flex-row sm:items-center sm:justify-between sm:gap-4",
                blocking && "border-success/30 bg-success/5",
                live.enabled && !blocking && "border-warning/30 bg-warning/5",
                !live.enabled && "bg-muted/40",
                locked ? "cursor-not-allowed" : "cursor-pointer",
              )}
            >
              <div className="flex min-w-0 flex-1 items-start gap-3">
                <span
                  className={cn(
                    "mt-0.5 hidden size-9 shrink-0 items-center justify-center rounded-full sm:flex",
                    blocking && "bg-success/15 text-success",
                    live.enabled && !blocking && "bg-warning/15 text-warning",
                    !live.enabled && "bg-muted-foreground/10 text-muted-foreground",
                  )}
                >
                  <ShieldCheck className="size-4" />
                </span>
                <div className="space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{t("enable")}</span>
                    <Badge variant={statusVariant}>{statusLabel}</Badge>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    {blocking ? t("blockingHint") : live.enabled ? t("watchingHint") : t("offHint")}
                  </p>
                </div>
              </div>
              <div className="flex h-5 shrink-0 items-center">
                <Switch
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={locked}
                  aria-label={t("enable")}
                />
              </div>
            </label>
  
            <Collapsible open={!enabled}>
              <CollapsibleContent className={COLLAPSIBLE_ANIMATION}>
                <Note icon={Lightbulb} title={t("whenToUseTitle")}>
                  {t("whenToUseBody")}
                </Note>
              </CollapsibleContent>
            </Collapsible>
  
            <Collapsible open={enabled}>
              <CollapsibleContent className={cn("-mx-1 px-1", COLLAPSIBLE_ANIMATION)}>
                <div className="space-y-5 border-t pt-5">
                  <div className="space-y-2">
                    <p className="text-sm font-medium">{t("modeLabel")}</p>
                    <ChoiceField
                      value={mode}
                      onChange={setMode}
                      disabled={locked}
                      name="waf-mode"
                      options={modes.map((option) => ({
                        value: option.value,
                        // Straight from the API, never a local copy.
                        label: option.title,
                        hint: option.value === "enforce" ? t("modeEnforceHint") : t("modeDetectHint"),
                        tone: option.value === "enforce" ? "warning" : undefined,
                      }))}
                    />
                    {showDetectLog ? (
                      // The caught count sits next to the mode choice so enabling
                      // blocking is an informed decision.
                      <p className="pt-1 text-xs text-muted-foreground">
                        {/* An unreadable log must not claim "Nothing caught yet". */}
                        {detectFailed
                          ? t("detectUnknown")
                          : detectCount > 0
                            ? t("detectCaught", { count: detectCount })
                            : t("detectNothingYet")}
                      </p>
                    ) : null}
                  </div>
  
                  <div className="space-y-2 border-t pt-5">
                    <p className="text-sm font-medium">{t("categoriesLabel")}</p>
                    <p className="text-xs text-muted-foreground">{t("categoriesHint")}</p>
                    <div className="divide-y rounded-xl border">
                      {catalog.map((category) => {
                        const checked = active.includes(category.value);
                        // Unticking the last one would send `categories: []`,
                        // which the API reads as ALL SIX, so it is blocked.
                        const lastOne = checked && active.length === 1;
                        return (
                          <label
                            key={category.value}
                            className={cn(
                              "flex flex-col gap-1 p-3.5 transition-colors",
                              locked || lastOne ? "cursor-not-allowed" : "cursor-pointer hover:bg-muted/40",
                            )}
                          >
                            {/* Switch pairs with the title; the hint runs full
                                width beneath so it is not squeezed on narrow screens. */}
                            <div className="flex items-center justify-between gap-4">
                              <span className="min-w-0 text-sm font-medium">{category.title}</span>
                              <ReasonTooltip reason={lastOne ? t("lastCategory") : null}>
                                <div className="flex h-5 shrink-0 items-center">
                                  <Switch
                                    checked={checked}
                                    onCheckedChange={(value) => toggleCategory(category.value, value)}
                                    disabled={locked || lastOne}
                                    aria-label={category.title}
                                  />
                                </div>
                              </ReasonTooltip>
                            </div>
                            {DESCRIBED_CATEGORIES.has(category.value) ? (
                              <span className="block text-xs leading-relaxed text-muted-foreground">
                                {t(`categoryHints.${category.value}`)}
                              </span>
                            ) : null}
                          </label>
                        );
                      })}
                    </div>
                  </div>
  
                  {/* Rule lists stay folded away; most sites never need them. */}
                  <Collapsible open={advancedOpen} onOpenChange={setAdvancedOpen} className="border-t pt-5">
                    <CollapsibleTrigger asChild>
                      <Button type="button" variant="ghost" size="sm" className="-ml-2">
                        <Sliders className="size-3.5" />
                        {t("advanced")}
                        <ChevronDown className={cn("size-3.5 transition-transform", advancedOpen && "rotate-180")} />
                      </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent className={cn("-mx-1 px-1", COLLAPSIBLE_ANIMATION)}>
                      <div className="mt-3 space-y-5">
                        <div className="space-y-2">
                          <p className="text-sm font-medium">{t("exceptionsTitle")}</p>
                          <p className="text-xs leading-relaxed text-muted-foreground">{t("exceptionsHint")}</p>
                          <RuleList
                            items={exceptions}
                            onChange={setExceptions}
                            disabled={locked}
                            placeholder={t("exceptionsPlaceholder")}
                            emptyText={t("exceptionsEmpty")}
                            // An exception skips every check for any path containing it,
                            // so "a" or "/" would switch the firewall off; the API refuses under 4.
                            minLength={4}
                          />
                        </div>
  
                        <div className="space-y-2">
                          <p className="text-sm font-medium">{t("blocksTitle")}</p>
                          <p className="text-xs leading-relaxed text-muted-foreground">{t("blocksHint")}</p>
                          <RuleList
                            items={blocks}
                            onChange={setBlocks}
                            disabled={locked}
                            placeholder={t("blocksPlaceholder")}
                            emptyText={t("blocksEmpty")}
                            warnShort
                          />
                        </div>
                      </div>
                    </CollapsibleContent>
                  </Collapsible>
                </div>
              </CollapsibleContent>
            </Collapsible>
          </CardContent>
  
          <CardSaveFooter
            saving={saving}
            dirty={isDirty}
            saveReason={saveReason}
            onSave={save}
            onDiscard={discard}
            savingNote={t("savingNote")}
          />
        </Card>
      </div>
    </DisabledReasonProvider>
  );
}
