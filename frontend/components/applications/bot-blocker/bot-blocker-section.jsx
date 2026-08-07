"use client";

import { useState } from "react";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Bot, ChevronDown, Globe, ShieldBan, ShieldCheck, ShieldHalf } from "lucide-react";
import { cn } from "@/lib/utils";
import { updateApplicationBotBlocker } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";

// The order the three choices are read in — least restrictive first, so the
// list runs from "changes nothing" to "blocks the most". Only keys the API
// actually returned are rendered, and anything new the backend adds later
// still appears (after these) rather than being silently dropped.
const ORDER = ["allow_all", "block_training", "block_agents", "block_all"];

// An icon per policy so the three read as distinct choices at a glance rather
// than three paragraphs. Anything the backend adds later falls back to the
// generic bot mark instead of rendering nothing.
const ICONS = {
  allow_all: Globe,
  block_training: ShieldCheck,
  block_agents: ShieldHalf,
  block_all: ShieldBan,
};

// Blocking every AI bot also blocks the ones that send real visitors — a real
// cost, so that option is tinted as one rather than presented as simply "more
// secure".
const TONES = { block_all: "warning" };

// Policies we have a plain-language group name for in the expanded bot list.
const GROUP_LABELS = new Set(["block_training", "block_agents", "block_all"]);

function orderedKeys(policies) {
  const known = ORDER.filter((key) => key in policies);
  const rest = Object.keys(policies).filter((key) => !ORDER.includes(key));
  return [...known, ...rest];
}

// What each option blocks *on top of* the one before it — the difference is
// the whole decision this screen asks for, and it is derived from the lists
// the API returned rather than kept as a second copy here. Written against the
// ordering rather than against named policies, so the backend adding a fourth
// choice (it did: `block_agents`) needs no change on this side.
function additionsByPolicy(keys, policies) {
  const additions = {};
  let previous = null;
  for (const key of keys) {
    const bots = policies[key]?.blocked_bots ?? [];
    if (previous && (policies[previous]?.blocked_count ?? 0) > 0) {
      const already = new Set(policies[previous]?.blocked_bots ?? []);
      additions[key] = bots.filter((bot) => !already.has(bot));
    }
    previous = key;
  }
  return additions;
}

// The expanded list, split by the policy that first blocks each bot — training
// scrapers, then assistants, then search crawlers. Same derivation, so the
// groups can never disagree with the counts on the cards.
function botGroups(keys, policies, selected) {
  const seen = new Set();
  const groups = [];
  for (const key of keys) {
    const bots = (policies[key]?.blocked_bots ?? []).filter((bot) => !seen.has(bot));
    for (const bot of bots) seen.add(bot);
    if (bots.length > 0) groups.push({ key, bots });
    if (key === selected) break;
  }
  return groups;
}

function BotList({ bots }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {bots.map((bot) => (
        <Badge key={bot} variant="outline" className="font-mono font-normal">
          {bot}
        </Badge>
      ))}
    </div>
  );
}

function BotGroup({ label, bots }) {
  return (
    <div className="space-y-1.5">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <BotList bots={bots} />
    </div>
  );
}

/**
 * Whole-site AI crawler control. Three policies, not an on/off switch:
 * blocking a scraper that trains a model on your content and blocking an AI
 * search crawler that sends you visitors are different decisions, and a single
 * toggle would do both at once. (Cloudflare splits them for the same reason;
 * ServerAvatar's commercial panel and xCloud ship a binary switch, which is
 * what this improves on.)
 *
 * Every label, description, count and bot name comes from GET /ai-bot-policies —
 * the backend reads the same config file to build the vhost, so rendering from
 * the response is what keeps this screen honest about what is really enforced.
 */
export function BotBlockerSection({ appId, policies, currentPolicy, canManage }) {
  const t = useTranslations("applications.botBlocker");
  const router = useRouter();
  const [policy, setPolicy] = useState(currentPolicy);
  const [saving, setSaving] = useState(false);
  const [showBots, setShowBots] = useState(false);

  const keys = orderedKeys(policies);
  const selected = policies[policy] ?? null;
  const savedPolicy = policies[currentPolicy] ?? null;
  const isDirty = policy !== currentPolicy;
  const additions = additionsByPolicy(keys, policies);
  // Otherwise 31 bare user-agent names with nothing to tell them apart.
  const groups = botGroups(keys, policies, policy);
  // Nothing blocked is not a protected state — it is the default for every new
  // site, so it must not be dressed in the same green as one that blocks.
  const isProtected = (savedPolicy?.blocked_count ?? 0) > 0;
  const saveReason = !canManage ? t("noPermission") : !isDirty ? t("nothingToSave") : null;

  async function save() {
    setSaving(true);
    try {
      await updateApplicationBotBlocker(appId, policy);
      toast.success(t("saved"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="max-w-4xl space-y-4">
      {/* One line, not a paragraph: "AI bot" still needs a definition before
          the three options mean anything, but the options themselves carry the
          detail — repeating it up here turned the screen into a document. */}
      <div className="flex items-center gap-2.5 rounded-xl border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
        <Bot className="size-4 shrink-0" />
        <p>{t("explainer")}</p>
      </div>

      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="space-y-5 p-5">
          {/* Selectable cards rather than a bare radio list: three options
              each carrying a sentence of consequence and a count read as a
              wall of text when they are only rows. A card each gives the icon,
              the claim and the number their own place, and makes the chosen
              one obvious from across the screen — while still being a real
              RadioGroup underneath, so keyboard and screen readers get the
              standard one-of-three semantics. */}
          <div className="space-y-3">
            <p className="text-sm font-medium">{t("chooseLabel")}</p>
            <RadioGroup
              value={policy}
              onValueChange={setPolicy}
              disabled={!canManage || saving}
              name="ai-bot-policy"
              className="gap-3"
            >
              {keys.map((key) => {
                const option = policies[key];
                const Icon = ICONS[key] ?? Bot;
                const checked = key === policy;
                const warn = TONES[key] === "warning";
                return (
                  <label
                    key={key}
                    htmlFor={`ai-bot-${key}`}
                    className={cn(
                      "flex items-start gap-3 rounded-xl border p-4 transition-colors",
                      !canManage || saving ? "cursor-not-allowed opacity-70" : "cursor-pointer",
                      checked && warn && "border-warning/50 bg-warning/5",
                      checked && !warn && "border-primary/50 bg-primary/5",
                      !checked && "hover:bg-muted/40",
                    )}
                  >
                    {/* Radio and icon share one `items-center` row so they
                        stay centred on each other whatever their sizes are —
                        a 16px control next to a 36px circle, each with its own
                        margin, left the radio 8px high. */}
                    <span className="flex shrink-0 items-center gap-3">
                      <RadioGroupItem value={key} id={`ai-bot-${key}`} />
                      <span
                        className={cn(
                          "flex size-9 items-center justify-center rounded-full",
                          checked && warn && "bg-warning/15 text-warning",
                          checked && !warn && "bg-primary/10 text-primary",
                          !checked && "bg-muted-foreground/10 text-muted-foreground",
                        )}
                      >
                        <Icon className="size-4" />
                      </span>
                    </span>
                    <span className="min-w-0 flex-1 space-y-1">
                      <span className="flex flex-wrap items-center gap-2">
                        {/* Straight from the API — never a local copy. */}
                        <span className={cn("text-sm", checked ? "font-semibold" : "font-medium")}>
                          {option.title}
                        </span>
                        {/* The count sits on every option, not just the
                            selected one, so the three can be compared without
                            clicking through them. */}
                        <Badge variant={option.blocked_count ? (warn ? "warning" : "secondary") : "outline"}>
                          {t("blockedCount", { count: option.blocked_count })}
                        </Badge>
                        {/* Which option is really in force, and which one you
                            have merely clicked, said on the options themselves —
                            a separate "currently active" row above repeated the
                            selected card's own title and count word for word. */}
                        {key === currentPolicy ? (
                          <Badge variant={isProtected ? "success" : "secondary"}>
                            {t("activeNow")}
                          </Badge>
                        ) : null}
                        {checked && isDirty ? (
                          <Badge variant="warning">{t("unsaved")}</Badge>
                        ) : null}
                      </span>
                      <span className="block text-xs leading-relaxed text-muted-foreground">
                        {option.description}
                      </span>
                      {/* "Also blocks the crawlers that send you visitors" is
                          abstract until they have names. These are exactly the
                          bots this option adds over the one above it. */}
                      {additions[key]?.length > 0 ? (
                        <span
                          className={cn(
                            "block text-xs leading-relaxed",
                            warn ? "text-warning" : "text-muted-foreground",
                          )}
                        >
                          {t("alsoBlocks", { bots: additions[key].join(", ") })}
                        </span>
                      ) : null}
                    </span>
                  </label>
                );
              })}
            </RadioGroup>
          </div>

          {/* The choice stops being a black box: how many bots it blocks, and
              which ones, on demand. 30 names unprompted is noise, so the list
              is collapsed by default. */}
          {selected && selected.blocked_count > 0 ? (
            <Collapsible open={showBots} onOpenChange={setShowBots}>
              <CollapsibleTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                  <ChevronDown className={cn("size-3.5 transition-transform", showBots && "rotate-180")} />
                  {showBots
                    ? t("hideBots")
                    : t("showBots", { count: selected.blocked_count })}
                </Button>
              </CollapsibleTrigger>
              <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                <div className="mt-3 space-y-3 rounded-lg border bg-muted/30 p-3">
                  {groups.length > 1 ? (
                    groups.map((group) => (
                      <BotGroup
                        key={group.key}
                        // Named per policy, falling back to that policy's own
                        // title if the backend introduces one we have no word
                        // for yet.
                        label={GROUP_LABELS.has(group.key)
                          ? t(`groupLabels.${group.key}`)
                          : (policies[group.key]?.title ?? group.key)}
                        bots={group.bots}
                      />
                    ))
                  ) : (
                    <BotList bots={selected.blocked_bots} />
                  )}
                </div>
              </CollapsibleContent>
            </Collapsible>
          ) : null}

          {/* People assume this writes robots.txt. It does not, and the
              difference matters: robots.txt is a request, this is enforced. */}
          <p className="text-xs text-muted-foreground">{t("howItWorks")}</p>
        </CardContent>

        {/* The unsaved marker lives on the chosen card here, not in the
            footer — the footer's own is suppressed by passing `dirty` only
            for the buttons it gates. */}
        <CardSaveFooter
          saving={saving}
          dirty={isDirty}
          saveReason={saveReason}
          onSave={save}
          onDiscard={() => setPolicy(currentPolicy)}
          savingNote={t("savingNote")}
          showUnsaved={false}
        />
      </Card>
    </div>
  );
}
