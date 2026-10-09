"use client";

import { useRef, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { Bot, ChevronDown, FileText, Globe, Plus, ShieldBan, ShieldCheck, ShieldHalf, X } from "lucide-react";
import { CopyButton } from "@/components/ui/copy-button";
import { cn } from "@/lib/utils";
import { Note } from "@/components/ui/note";
import { updateApplicationBotBlocker } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import {
  BOT_RULE_LIMIT,
  botRuleError,
  effectiveBlockedBots,
  hasBot,
} from "@/lib/schemas/bot-rule";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";

// Least restrictive first. Only keys the API returned render; unknown new
// keys appear after these.
const ORDER = ["allow_all", "block_training", "block_agents", "block_all"];

// Unknown policies fall back to the generic bot icon.
const ICONS = {
  allow_all: Globe,
  block_training: ShieldCheck,
  block_agents: ShieldHalf,
  block_all: ShieldBan,
};

// Blocking every AI bot also blocks ones that send visitors, so it is tinted.
const TONES = { block_all: "warning" };

// Policies with a translated group name in the expanded bot list.
const GROUP_LABELS = new Set(["block_training", "block_agents", "block_all"]);

/** Order-independent, case-insensitive list comparison. */
function sameList(a, b) {
  if (a.length !== b.length) return false;
  const key = (list) => list.map((v) => String(v).toLowerCase()).sort().join("|");
  return key(a) === key(b);
}

// robots.txt product tokens, not crawlers: no request carries them, so they are not counted as blocked.
const ROBOTS_TXT_ONLY = new Set(["google-extended", "applebot-extended"]);
const isRobotsTxtOnly = (bot) => ROBOTS_TXT_ONLY.has(String(bot).toLowerCase());
const enforceableCount = (bots) => bots.filter((bot) => !isRobotsTxtOnly(bot)).length;

// The vhost matches bot names case-insensitively, so case variants are one bot.
function dedupedPolicies(policies) {
  return Object.fromEntries(
    Object.entries(policies).map(([key, option]) => {
      const bots = effectiveBlockedBots(option?.blocked_bots ?? []);
      return [key, { ...option, blocked_bots: bots, blocked_count: enforceableCount(bots) }];
    }),
  );
}

function orderedKeys(policies) {
  const known = ORDER.filter((key) => key in policies);
  const rest = Object.keys(policies).filter((key) => !ORDER.includes(key));
  return [...known, ...rest];
}

// What each option blocks on top of the previous one, derived from the API's
// lists by order, so new policies need no change here.
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

// Bots grouped by the policy that first blocks them; same derivation as the
// card counts, so they always agree.
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

// Sorted alphabetically (case-insensitive); display order only.
function BotList({ bots }) {
  const t = useTranslations("applications.botBlocker");
  const sorted = [...bots].sort((a, b) =>
    a.localeCompare(b, undefined, { sensitivity: "base" }),
  );
  const robotsOnly = sorted.filter(isRobotsTxtOnly);

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-1.5">
        {sorted.map((bot) =>
          isRobotsTxtOnly(bot) ? (
            <Badge
              key={bot}
              variant="outline"
              className="h-auto max-w-full font-mono font-normal break-all whitespace-normal text-muted-foreground"
            >
              {bot}
              <span className="font-sans text-xs">· {t("robotsTxtOnly")}</span>
            </Badge>
          ) : (
            <Badge
              key={bot}
              variant="outline"
              /* Accent tint for visible edges; reference data, not a status. */
              className="h-auto max-w-full border-primary/20 bg-primary/5 font-mono font-normal break-all whitespace-normal text-primary"
            >
              {bot}
            </Badge>
          ),
        )}
      </div>
      {robotsOnly.length ? (
        <p className="text-xs text-muted-foreground">{t("robotsTxtOnlyNote", { names: robotsOnly.join(", ") })}</p>
      ) : null}
    </div>
  );
}

// Names are checked against the backend's rules before adding (`bot` would match Googlebot).
function RuleEditor({ kind, icon: Icon, bots, refused = {}, disabled, onAdd, onRemove }) {
  const t = useTranslations("applications.botBlocker.exceptions");
  const [draft, setDraft] = useState("");
  const [error, setError] = useState(null);
  const listRef = useRef(null);
  const inputRef = useRef(null);

  // The removed chip's button is gone, so focus is moved explicitly.
  function remove(bot, index) {
    onRemove(bot);
    requestAnimationFrame(() => {
      const buttons = listRef.current?.querySelectorAll("button[data-chip-remove]") ?? [];
      (buttons[index] ?? buttons[index - 1] ?? inputRef.current)?.focus();
    });
  }

  const blocking = kind === "blocked";

  function add() {
    const value = draft.trim();
    if (!value) return;

    const problem = botRuleError(value) ?? onAdd(value);
    if (problem) {
      setError(problem);
      return;
    }

    setDraft("");
    setError(null);
  }

  return (
    <div className="min-w-0 space-y-2 rounded-lg border p-3">
      <p className="flex items-center gap-1.5 text-sm font-medium">
        <Icon className={cn("size-4", blocking ? "text-destructive" : "text-success")} />
        {t(`${kind}.label`)}
      </p>
      <p className="text-xs text-muted-foreground">{t(`${kind}.description`)}</p>

      {bots.length > 0 ? (
        <div ref={listRef} className="flex flex-wrap gap-1.5 pt-0.5">
          {bots.map((bot, index) => (
            <span
              key={bot}
              className={cn(
                "inline-flex max-w-full items-center gap-1 rounded-md border bg-muted/40 py-0.5 pl-2 pr-1 font-mono text-xs break-all",
                refused[bot] && "border-destructive/60 bg-destructive/5 text-destructive",
              )}
            >
              {bot}
              <button
                type="button"
                data-chip-remove
                onClick={() => remove(bot, index)}
                disabled={disabled}
                aria-label={t("remove", { bot })}
                className="rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
              >
                <X className="size-3" />
              </button>
            </span>
          ))}
        </div>
      ) : (
        <p className="pt-0.5 text-xs text-muted-foreground">{t("none")}</p>
      )}

      {/* Server refusals, shown next to the entry they name. */}
      {Object.entries(refused).map(([bot, message]) => (
        <p key={bot} className="text-xs break-all text-destructive">
          {bot}: {message}
        </p>
      ))}

      <div className="flex gap-2 pt-1">
        <Input
          ref={inputRef}
          value={draft}
          onChange={(event) => {
            setDraft(event.target.value);
            if (error) setError(null);
          }}
          onKeyDown={(event) => {
            if (event.key !== "Enter") return;
            // Guards against a stray submit.
            event.preventDefault();
            add();
          }}
          placeholder={t("placeholder")}
          spellCheck={false}
          autoComplete="off"
          disabled={disabled}
          aria-invalid={Boolean(error)}
          className="h-8 font-mono text-xs"
        />
        {/* Disabled when empty, matching the firewall's control. */}
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={add}
          disabled={disabled || !draft.trim()}
        >
          <Plus className="size-3.5" />
          {t("add")}
        </Button>
      </div>

      {error ? <p className="text-xs text-destructive">{t(`errors.${error}`)}</p> : null}
    </div>
  );
}

// Google and Apple train on what their search crawlers fetch, so blocking by name
// cannot stop it; robots.txt can. Text and lines come from the API.
function RobotsTxtLines({ note, lines }) {
  const t = useTranslations("applications.botBlocker.robotsTxt");
  return (
    <Note icon={FileText} title={t("title")}>
      <div className="space-y-2">
        {note ? <p>{note}</p> : null}
        <div className="flex items-start gap-2 rounded-md border bg-background p-2">
          <pre className="min-w-0 flex-1 overflow-x-auto font-mono text-xs leading-relaxed text-foreground">
            {lines.trimEnd()}
          </pre>
          <CopyButton value={lines} label={t("copy")} className="size-7 shrink-0" />
        </div>
      </div>
    </Note>
  );
}

function BotGroup({ label, bots }) {
  return (
    <div className="space-y-2">
      {/* Strong heading with a count so groups stand out from the chips. */}
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        {label}
        <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium tabular-nums text-muted-foreground">
          {bots.length}
        </span>
      </p>
      <BotList bots={bots} />
    </div>
  );
}

// Labels, counts and bot names all come from GET /ai-bot-policies.
export function BotBlockerSection({
  appId,
  policies: sentPolicies,
  robotsTxt = null,
  currentPolicy,
  currentBlocked = [],
  currentAllowed = [],
  canManage,
}) {
  const t = useTranslations("applications.botBlocker");
  const { refreshAndWait } = useRefresh();
  const policies = dedupedPolicies(sentPolicies);
  // What the last save wrote, held until the refreshed props agree, so the
  // card is not "unsaved" in between.
  const [justSaved, setJustSaved] = useState(null);
  if (
    justSaved &&
    justSaved.policy === currentPolicy &&
    sameList(justSaved.blocked, currentBlocked) &&
    sameList(justSaved.allowed, currentAllowed)
  ) {
    setJustSaved(null);
  }
  const base = justSaved ?? { policy: currentPolicy, blocked: currentBlocked, allowed: currentAllowed };
  const [refused, setRefused] = useState({ blocked: {}, allowed: {} });
  // Remounts both editors on Discard to clear half-typed names and errors.
  const [editorKey, setEditorKey] = useState(0);
  const [policy, setPolicy] = useState(currentPolicy);
  const [blocked, setBlocked] = useState(currentBlocked);
  const [allowed, setAllowed] = useState(currentAllowed);
  const [saving, setSaving] = useState(false);
  const [showBots, setShowBots] = useState(false);

  const keys = orderedKeys(policies);
  const selected = policies[policy] ?? null;
  const savedPolicy = policies[base.policy] ?? null;
  const isDirty =
    policy !== base.policy ||
    !sameList(blocked, base.blocked) ||
    !sameList(allowed, base.allowed);
  const additions = additionsByPolicy(keys, policies);
  const groups = botGroups(keys, policies, policy);

  // What the vhost will enforce once saved, including this site's own rules.
  const effective = effectiveBlockedBots(selected?.blocked_bots ?? [], blocked, allowed);
  const savedEffective = effectiveBlockedBots(
    savedPolicy?.blocked_bots ?? [],
    base.blocked,
    base.allowed,
  );

  // Must agree with the count on the button: exemptions are removed from the
  // policy groups and site additions get their own group.
  const shownGroups = [
    ...groups
      .map((group) => ({ ...group, bots: group.bots.filter((bot) => !hasBot(allowed, bot)) }))
      .filter((group) => group.bots.length > 0),
    ...(blocked.some((bot) => !hasBot(allowed, bot))
      ? [{ key: "custom", bots: blocked.filter((bot) => !hasBot(allowed, bot)) }]
      : []),
  ];

  // Allows that nothing would block anyway.
  const idleAllows = allowed.filter(
    (bot) => !hasBot(selected?.blocked_bots ?? [], bot) && !hasBot(blocked, bot),
  );

  // Nothing blocked is the default, not a protected state.
  const isProtected = savedEffective.length > 0;
  // Warns before a client-side navigation drops the edit (components/ui/unsaved-guard.jsx).
  useWatchUnsaved("app-bot-blocker", isDirty);

  const saveReason = !canManage ? t("noPermission") : !isDirty ? t("nothingToSave") : null;

  // A bot lives in one list only: the backend lets an allow win over a block.
  function addRule(kind, value) {
    const [list, setList, other, setOther] =
      kind === "blocked" ? [blocked, setBlocked, allowed, setAllowed] : [allowed, setAllowed, blocked, setBlocked];

    if (hasBot(list, value)) return "duplicate";
    if (list.length >= BOT_RULE_LIMIT) return "limit";

    setList([...list, value.trim()]);
    if (hasBot(other, value)) {
      setOther(other.filter((bot) => bot.toLowerCase() !== value.trim().toLowerCase()));
    }
    return null;
  }

  function removeRule(kind, value) {
    const setList = kind === "blocked" ? setBlocked : setAllowed;
    setList((list) => list.filter((bot) => bot !== value));
    setRefused((current) => {
      const rest = { ...current[kind] };
      delete rest[value];
      return { ...current, [kind]: rest };
    });
  }

  async function save() {
    setSaving(true);
    setRefused({ blocked: {}, allowed: {} });
    try {
      await updateApplicationBotBlocker(appId, { policy, blocked, allowed });
      setJustSaved({ policy, blocked, allowed });
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      const errors = error.response?.status === 422 ? error.response.data?.errors ?? {} : {};
      const next = { blocked: {}, allowed: {} };
      for (const [field, messages] of Object.entries(errors)) {
        const match = field.match(/^(blocked|allowed)\.(\d+)$/);
        const bot = match ? (match[1] === "blocked" ? blocked : allowed)[Number(match[2])] : null;
        if (bot) next[match[1]][bot] = messages?.[0] ?? "";
      }
      setRefused(next);
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="space-y-4">
        <Note icon={Bot}>{t("explainer")}</Note>
  
        <Card className="gap-0 overflow-hidden py-0">
          <CardContent className="space-y-5 p-5">
            {/* Selectable cards over a real RadioGroup, keeping keyboard and
                screen-reader semantics. */}
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
                      {/* One `items-center` row keeps radio and icon aligned. */}
                      <span className="flex shrink-0 items-center gap-3">
                        <RadioGroupItem value={key} id={`ai-bot-${key}`} />
                        <span
                          className={cn(
                            "hidden size-9 items-center justify-center rounded-full sm:flex",
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
                          {/* From the API, never a local copy. */}
                          <span className={cn("text-sm", checked ? "font-semibold" : "font-medium")}>
                            {option.title}
                          </span>
                          {/* Count on every option so they can be compared. */}
                          <Badge variant={option.blocked_count ? (warn ? "warning" : "muted") : "outline"}>
                            {t("blockedCount", { count: option.blocked_count })}
                          </Badge>
                          {/* Marks the option currently in force. */}
                          {key === base.policy ? (
                            <Badge variant={isProtected ? "success" : "muted"}>
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
                        {/* Names the bots this option adds over the previous one. */}
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
  
            {/* Follows the chosen option, not the saved one: it is advice for the
                owner's robots.txt, independent of Save. */}
            {selected?.robots_txt_recommended && robotsTxt?.lines ? (
              <RobotsTxtLines note={robotsTxt.note} lines={robotsTxt.lines} />
            ) : null}

            {/* Per-site exceptions share the policy's Save: the backend resolves
                all three in one request. */}
            <div className="space-y-3 border-t pt-5">
              <div>
                <p className="text-sm font-medium">{t("exceptions.title")}</p>
                <p className="text-xs text-muted-foreground">{t("exceptions.hint")}</p>
              </div>
  
              <div className="grid gap-4 sm:grid-cols-2">
                <RuleEditor
                  key={`blocked-${editorKey}`}
                  kind="blocked"
                  icon={ShieldBan}
                  bots={blocked}
                  refused={refused.blocked}
                  disabled={!canManage || saving}
                  onAdd={(value) => addRule("blocked", value)}
                  onRemove={(value) => removeRule("blocked", value)}
                />
                <RuleEditor
                  key={`allowed-${editorKey}`}
                  kind="allowed"
                  icon={ShieldCheck}
                  bots={allowed}
                  refused={refused.allowed}
                  disabled={!canManage || saving}
                  onAdd={(value) => addRule("allowed", value)}
                  onRemove={(value) => removeRule("allowed", value)}
                />
              </div>
  
              {/* Flags allows that have no effect. */}
              {idleAllows.length > 0 ? (
                <p className="text-xs text-muted-foreground">
                  {t("exceptions.noEffect", { bots: idleAllows.join(", ") })}
                </p>
              ) : null}
            </div>
  
            {/* The blocked bots, collapsed by default. */}
            {effective.length > 0 ? (
              <Collapsible open={showBots} onOpenChange={setShowBots}>
                <CollapsibleTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-auto max-w-full py-1.5 text-left whitespace-normal"
                  >
                    <ChevronDown className={cn("size-3.5 shrink-0 transition-transform", showBots && "rotate-180")} />
                    {showBots ? t("hideBots") : t("showBots", { count: enforceableCount(effective) })}
                  </Button>
                </CollapsibleTrigger>
                <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                  <div className="mt-3 space-y-4 rounded-lg border bg-muted/30 p-3">
                    {shownGroups.length > 1 ? (
                      shownGroups.map((group) => (
                        <BotGroup
                          key={group.key}
                          // Falls back to the policy's own title for unknown keys.
                          label={
                            group.key === "custom" || GROUP_LABELS.has(group.key)
                              ? t(`groupLabels.${group.key}`)
                              : (policies[group.key]?.title ?? group.key)
                          }
                          bots={group.bots}
                        />
                      ))
                    ) : (
                      <BotList bots={shownGroups[0]?.bots ?? []} />
                    )}
                  </div>
                </CollapsibleContent>
              </Collapsible>
            ) : null}
  
            {/* Clarifies this is enforced by the server, not a robots.txt request. */}
            <p className="text-xs text-muted-foreground">{t("howItWorks")}</p>
          </CardContent>
  
          {/* The unsaved marker is on the chosen card; `dirty` here only gates
              the buttons. */}
          <CardSaveFooter
            saving={saving}
            dirty={isDirty}
            saveReason={saveReason}
            onSave={save}
            onDiscard={() => {
              setPolicy(base.policy);
              setBlocked(base.blocked);
              setAllowed(base.allowed);
              setRefused({ blocked: {}, allowed: {} });
              setEditorKey((key) => key + 1);
            }}
            savingNote={t("savingNote")}
            showUnsaved={false}
          />
        </Card>
      </div>
    </DisabledReasonProvider>
  );
}
