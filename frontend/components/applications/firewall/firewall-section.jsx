"use client";

import { useState } from "react";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ChevronDown, ShieldCheck, Sliders } from "lucide-react";
import { cn } from "@/lib/utils";
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

// Our own plain-language line per category — the API sends a title and nothing
// else. Keyed by the values the backend defines, so a category it adds later
// still renders (title only) instead of throwing on a missing message.
const DESCRIBED_CATEGORIES = new Set([
  "query_string",
  "request_uri",
  "user_agent",
  "referrer",
  "cookie",
  "method",
]);

// Watch mode writes matches into the site's own document root. There is no API
// that reads it, but the Files browser can open the folder — a real route to
// the evidence rather than a dead end.

const COLLAPSIBLE_ANIMATION =
  "overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down";

function sameList(a, b) {
  return a.length === b.length && a.every((value, index) => value === b[index]);
}

/**
 * The 8G Firewall for one site.
 *
 * Six independently switchable categories rather than one on/off switch,
 * because the ruleset has a documented false-positive history (phpinfo, a
 * forum plugin's own path, a page whose name contained a banned substring) and
 * a single switch makes "fix one false positive" mean "give up all protection".
 * GridPane's production port of the same ruleset splits it the same way.
 *
 * Everything saves in one call — the API is a single atomic PUT, and separate
 * per-section Save buttons would imply an independence the endpoint does not
 * have.
 */
export function FirewallSection({ appId, application, categories: catalog, modes, canManage, detectCount = 0 }) {
  const t = useTranslations("applications.firewall");
  const router = useRouter();

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
  // Opened by default only for a site that already has tuning to show —
  // otherwise this is the part nobody needs until something breaks.
  const [advancedOpen, setAdvancedOpen] = useState(
    saved.exceptions.length > 0 || saved.blocks.length > 0,
  );

  const isDirty =
    enabled !== saved.enabled ||
    mode !== saved.mode ||
    !sameList(active, saved.categories) ||
    !sameList(exceptions, saved.exceptions) ||
    !sameList(blocks, saved.blocks);

  const saveReason = !canManage ? t("noPermission") : !isDirty ? t("nothingToSave") : null;
  const blocking = enabled && mode === "enforce";
  // Watch mode is not protection, so it must not borrow protection's colour —
  // the same rule that stopped "nothing blocked" rendering as a green shield
  // on the bot blocker.
  const statusVariant = blocking ? "success" : enabled ? "warning" : "secondary";
  const statusLabel = blocking ? t("statusBlocking") : enabled ? t("statusWatching") : t("statusOff");
  // The log only exists once the site has actually been running in watch mode —
  // linking to it off an unsaved selection would point at a file that isn't there.
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
        // Never an empty array: the backend reads `categories: []` as "all six",
        // so an empty list would silently turn everything back on. The UI
        // guarantees at least one is selected, and this is the second line of
        // that defence.
        categories: active.length > 0 ? active : catalog.map((item) => item.value),
        exceptions,
        custom_rules: blocks,
      });
      toast.success(t("saved"));
      router.refresh();
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
    <div className="max-w-4xl space-y-4">
      <div className="flex items-center gap-2.5 rounded-xl border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
        <ShieldCheck className="size-4 shrink-0" />
        <p>{t("explainer")}</p>
      </div>

      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="space-y-5 p-5">
          {/* A real <label> so the whole row toggles, not just the switch. */}
          <label
            className={cn(
              "flex flex-row items-center justify-between gap-4 rounded-xl border p-4 transition-colors",
              blocking && "border-success/30 bg-success/5",
              enabled && !blocking && "border-warning/30 bg-warning/5",
              !enabled && "bg-muted/40",
              locked ? "cursor-not-allowed" : "cursor-pointer",
            )}
          >
            <div className="flex items-start gap-3">
              <span
                className={cn(
                  "mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full",
                  blocking && "bg-success/15 text-success",
                  enabled && !blocking && "bg-warning/15 text-warning",
                  !enabled && "bg-muted-foreground/10 text-muted-foreground",
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
                  {blocking ? t("blockingHint") : enabled ? t("watchingHint") : t("offHint")}
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
              <div className="rounded-lg bg-muted/40 p-3.5 text-sm">
                <p className="mb-1 font-medium">{t("whenToUseTitle")}</p>
                <p className="text-muted-foreground">{t("whenToUseBody")}</p>
              </div>
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
                      // Straight from the API — never a local copy.
                      label: option.title,
                      hint: option.value === "enforce" ? t("modeEnforceHint") : t("modeDetectHint"),
                      tone: option.value === "enforce" ? "warning" : undefined,
                    }))}
                  />
                  {showDetectLog ? (
                    // Points down the page at the real evidence rather than
                    // out to the file browser. The number is here, next to the
                    // switch, so turning blocking on is a decision with a
                    // figure in front of it instead of a guess.
                    <p className="pt-1 text-xs text-muted-foreground">
                      {detectCount > 0
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
                      // which the API reads as ALL SIX — the opposite of what
                      // the click means. Blocked at the source, with the way
                      // out named.
                      const lastOne = checked && active.length === 1;
                      return (
                        <label
                          key={category.value}
                          className={cn(
                            "flex items-center justify-between gap-4 p-3.5 transition-colors",
                            locked || lastOne ? "cursor-not-allowed" : "cursor-pointer hover:bg-muted/40",
                          )}
                        >
                          <div className="min-w-0 space-y-0.5">
                            <span className="block text-sm font-medium">{category.title}</span>
                            {DESCRIBED_CATEGORIES.has(category.value) ? (
                              <span className="block text-xs leading-relaxed text-muted-foreground">
                                {t(`categoryHints.${category.value}`)}
                              </span>
                            ) : null}
                          </div>
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
                        </label>
                      );
                    })}
                  </div>
                </div>

                {/* Progressive disclosure: two rule lists are the answer to a
                    problem most sites never have, so they stay folded away
                    until someone goes looking for them. */}
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
  );
}
