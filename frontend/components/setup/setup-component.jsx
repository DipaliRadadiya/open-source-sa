"use client";

import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { CircleAlert, Download, Loader2, RotateCw } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { DatabaseOptions } from "@/components/setup/database-options";
import { componentMeta } from "@/components/setup/component-meta";

// PHP and Node install per-version, right here on the setup checklist.
const RUNTIME_KEYS = new Set(["php", "node"]);

function VersionInstall({ versions, action, disabled, disabledReason, onInstall }) {
  const t = useTranslations("setup");
  const options = useMemo(
    () => versions.map((v) => ({ value: v.version, label: v.version, hint: v.lifecycle?.status })),
    [versions],
  );
  const defaultVersion =
    versions.find((v) => v.lifecycle?.status === "lts")?.version ?? options[0]?.value ?? "";
  const [version, setVersion] = useState(defaultVersion);

  // A note, not body copy. At `text-sm` this sat at the same weight as the
  // component's own description and the card read as two paragraphs about
  // nothing.
  if (!options.length) {
    return <p className="mt-2 text-xs leading-relaxed text-muted-foreground">{t("noVersions")}</p>;
  }

  return (
    <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
      <div className="w-full sm:w-56">
        <Combobox
          options={options}
          value={version}
          onChange={setVersion}
          placeholder={t("chooseVersion")}
          searchPlaceholder={t("chooseVersion")}
        />
      </div>
      {/* Like the database chooser: this picker is gone the moment its own
          install starts, so a spinner here can only ever have meant "something
          else is installing". Off with a reason instead. */}
      <Button
        className="shrink-0"
        disabled={!version || disabled}
        disabledReason={disabled ? disabledReason : !version ? t("chooseVersionFirst") : null}
        onClick={() => onInstall(action, { version })}
      >
        <Download className="size-4" />
        {t("install")}
      </Button>
    </div>
  );
}

// Identity chip: what the component IS. State is carried by the pill, not here.
function IconChip({ meta, small = false }) {
  const { Icon, tint, chip } = meta;
  return (
    <span className={cn("flex shrink-0 items-center justify-center rounded-xl", small ? "size-9" : "size-10", chip)}>
      <Icon className={cn(small ? "size-4" : "size-5", tint)} aria-hidden />
    </span>
  );
}

// One pill that states where this component stands, in words (never colour
// alone).
function StatusPill({ state, recommended, detail }) {
  const t = useTranslations("setup");
  // The detail sits BESIDE the badge, not inside it. A badge is `shrink-0` and
  // does not wrap, so "Installed · in use for the panel cache" was a fixed-width
  // block that pushed 41px off a 320px screen in Hindi. It is also a sentence,
  // and a status token should be one word — kept apart, the token stays short
  // and the explanation wraps like the prose it is.
  if (state === "installed") {
    return (
      <>
        <Badge variant="success" className="font-normal">
          {t("pillInstalled")}
        </Badge>
        {detail ? (
          <span className="min-w-0 text-xs text-muted-foreground">{detail}</span>
        ) : null}
      </>
    );
  }
  if (state === "installing") {
    return (
      <Badge variant="secondary" className="gap-1.5 font-normal text-primary">
        <Loader2 className="size-3 animate-spin" />
        {t("pillInstalling")}
      </Badge>
    );
  }
  if (state === "unavailable") {
    return <Badge variant="secondary" className="font-normal">{t("pillUnavailable")}</Badge>;
  }
  if (state === "failed") {
    return <Badge variant="destructive" className="font-normal">{t("pillFailed")}</Badge>;
  }
  // Not-recommended, not-installed components carry no badge — an "Optional"
  // tag reads as "you can skip this", which is wrong for pieces the panel needs.
  return recommended ? (
    <Badge variant="warning" className="font-normal">{t("recommended")}</Badge>
  ) : null;
}

/**
 * One setup component, rendered from its API state. Identity is the icon, state
 * is the pill, and the next action is the button — three separate reads so the
 * row is scannable. Failure UI is gated strictly on `state === "failed"`.
 */
export function SetupComponent({ component, versions = [], busy = false, locked = false, tier = "secondary", onInstall }) {
  const t = useTranslations("setup");
  const { state, action, options } = component;
  const isRuntime = RUNTIME_KEYS.has(component.key) && Boolean(action);
  const hasOptions = !isRuntime && options.length > 0;
  const runtimeVersions =
    isRuntime && options.length
      ? options.map((o) => ({ version: o.value, lifecycle: { status: o.hint } }))
      : versions;
  const installed = state === "installed";
  const failed = state === "failed";
  const installing = state === "installing" || busy;
  // Two different things, kept apart on purpose. `installing` is this
  // component's own progress and owns the spinner; `blocked` is apt's
  // one-at-a-time lock held by a *different* component and owns nothing but a
  // disabled state and a sentence. Folding them into one flag is what put a
  // spinner on "Install MariaDB" while fail2ban was the thing installing.
  const blocked = busy || locked;
  const blockedReason = locked ? t("lockedByOtherInstall") : null;
  // Nothing to install and nothing to choose: a runtime the server reported no
  // versions for. That is a neutral fact, not a fault, so the card sits back
  // like a finished one rather than staying at full weight in the to-do list
  // with an empty right-hand side where its action should be.
  const unavailable = isRuntime && runtimeVersions.length === 0 && !installed && !installing;
  // A card whose whole body is a title and a sentence, with one button: the
  // button belongs beside them, not pinned to the top of a two-line block.
  const simple = !hasOptions && !isRuntime;
  const meta = componentMeta(component.key);

  /**
   * A finished component, as a line rather than a card.
   *
   * Everything a full card carries — a 40px chip, a description, room for an
   * action — exists to help you decide something. There is nothing left to
   * decide here, and rendering five equal boxes of which two were already done
   * made the page a wall with no centre of gravity. Same information, a third
   * of the height, so the things still waiting on you are the heaviest thing
   * on screen.
   */
  const primary = tier === "primary";

  if (tier === "compact") {
    return (
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
        <span
          className={cn(
            "flex size-7 shrink-0 items-center justify-center rounded-lg",
            meta.chip,
          )}
        >
          <meta.Icon className={cn("size-3.5", meta.tint)} aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-0.5">
          <p className="text-sm font-medium">{component.title}</p>
          {/* Still says what the thing is for. "Redis · Installed" answers
              whether it is there, never why anyone wanted it — and on a setup
              page the second question is the one a first-time reader has. One
              line, clamped, so the row stays a row. */}
          {component.description ? (
            <p className="line-clamp-1 text-xs text-muted-foreground">{component.description}</p>
          ) : null}
        </div>
        {/* Status and version share one right-hand column across every row, so
            the eye reads a single edge down the list instead of hunting for the
            badge somewhere after each name. It wraps rather than shrink-0 —
            `detail` is a free-text sentence on Redis, not a version number. */}
        <div className="flex min-w-0 flex-wrap items-center justify-end gap-x-2 gap-y-1">
          <StatusPill state="installed" detail={component.detail} />
        </div>
      </div>
    );
  }

  return (
    <div
      aria-busy={installing}
      // One card, two weights: waiting (plain) and done/unavailable (sunk).
      //
      // Failure is NOT a third weight. It is said by the badge and by the red
      // message box inside the card, and outlining the whole card in red on top
      // of those made one recoverable install the loudest thing on the page.
      //
      // The recommended tint is gone. A primary-tinted border is how this panel
      // says "selected" everywhere else, and here it meant "recommended" — which
      // the badge beside the title already says, in words. Two signals for one
      // fact, one of them borrowed from a different vocabulary.
      //
      // Failure keeps the outline but loses the wash. The card carries its own
      // bordered reason box below the title; tinting the whole card as well made
      // "you can try again" the loudest thing on the page.
      className={cn(
        "rounded-2xl border transition-colors",
        // Primary carries the page: a real surface, and its identity row banded
        // off from its controls (below) so the card has an anatomy rather than
        // being a box with things in it. Secondary is the same anatomy at a
        // lower voice — flatter, no shadow — so "this needs a decision" and
        // "this can wait" are told apart before either is read.
        primary ? "overflow-hidden bg-card shadow-sm" : "bg-card/50 p-4",
        !primary && installed && "bg-muted/30 shadow-none",
        !primary && unavailable && "bg-muted/20 shadow-none",
      )}
    >
      <div
        className={cn(
          "flex gap-4",
          simple ? "items-center" : "items-start",
          // The header band: same row, given its own tinted strip and a rule
          // against the body beneath it.
          primary && "border-b bg-muted/30 px-5 py-4",
        )}
      >
        <IconChip meta={meta} small={!primary} />

        <div className="min-w-0 flex-1">
          {/* Title + description stay a tight unit; the interactive blocks below
              are deliberately outside this group so `space-y` can't squeeze them. */}
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className={cn("font-medium leading-tight", primary && "text-base")}>
                {component.title}
              </p>
              <StatusPill state={unavailable ? "unavailable" : installing ? "installing" : state} recommended={component.recommended} detail={component.detail} />
            </div>
            {component.description ? (
              <p className="text-sm leading-5 text-muted-foreground">{component.description}</p>
            ) : null}
          </div>

          {/* On a primary card the reason and the controls belong to the body
              below the band, not to the identity row. Rendered there instead. */}
          {primary ? null : (
            <Body
              t={t}
              component={component}
              failed={failed}
              installed={installed}
              installing={installing}
              hasOptions={hasOptions}
              isRuntime={isRuntime}
              options={options}
              action={action}
              runtimeVersions={runtimeVersions}
              blocked={blocked}
              blockedReason={blockedReason}
              onInstall={onInstall}
            />
          )}
        </div>

        {/* Right-side action for the simple states (the others render inline
            above). */}
        <div className="shrink-0">
          {installed || installing || isRuntime || hasOptions ? null : failed ? (
            action && component.retryable ? (
              <Button
                size="sm"
                variant="outline"
                disabled={blocked}
                disabledReason={blockedReason}
                onClick={() => onInstall(component, action)}
              >
                <RotateCw className="size-3.5" />
                {t("retry")}
              </Button>
            ) : null
          ) : action ? (
            <Button
              size="sm"
              disabled={blocked}
              disabledReason={blockedReason}
              onClick={() => onInstall(component, action)}
            >
              <Download className="size-3.5" />
              {t("install")}
            </Button>
          ) : (
            <span className="text-xs text-muted-foreground">{t("notInstallable")}</span>
          )}
        </div>
      </div>

      {primary ? (
        <div className="p-5">
          <Body
            t={t}
            component={component}
            failed={failed}
            installed={installed}
            installing={installing}
            hasOptions={hasOptions}
            isRuntime={isRuntime}
            options={options}
            action={action}
            runtimeVersions={runtimeVersions}
            blocked={blocked}
            blockedReason={blockedReason}
            onInstall={onInstall}
            flush
          />
        </div>
      ) : null}
    </div>
  );
}

/**
 * The part of a component that asks something of you: why it failed, which
 * engine to install, which version to pick.
 *
 * Split out because a primary card puts it under a header band while a
 * secondary card keeps it inline beside the icon — same content, two places,
 * and duplicating it was how the two tiers would drift apart.
 */
function Body({
  t,
  component,
  failed,
  installed,
  installing,
  hasOptions,
  isRuntime,
  options,
  action,
  runtimeVersions,
  blocked,
  blockedReason,
  onInstall,
  flush = false,
}) {
  return (
    <>
      {/* A failed component shows a reason. The backend message can promise
          a "reference" it didn't send, so we only trust it when a reference
          actually came with it; otherwise fall back to self-contained copy. */}
      {failed ? (
        <p className={cn("flex items-start gap-2 text-sm text-destructive", !flush && "mt-2.5")}>
              <CircleAlert className="mt-0.5 size-4 shrink-0" />
              <span>
                {component.reference ? (
                  <>
                    {component.message || t("componentFailed")}
                    <span className="mt-0.5 block font-mono text-xs opacity-90">
                      {t("reference", { reference: component.reference })}
                    </span>
                  </>
                ) : (
                  t("componentFailed")
                )}
              </span>
            </p>
          ) : null}

      {/* Database pick-one, shown while it still needs installing. */}
      {hasOptions && !installed && !installing ? (
        <div className={cn(failed ? "mt-4" : flush ? "" : "mt-3")}>
              <DatabaseOptions
                options={options}
                failed={failed}
                disabled={blocked}
                disabledReason={blockedReason}
                onInstall={(a) => onInstall(component, a)}
              />
            </div>
          ) : null}

          {/* Inline version picker for PHP/Node. */}
          {isRuntime && !installed && !installing ? (
            <VersionInstall
              versions={runtimeVersions}
              action={action}
              disabled={blocked}
              disabledReason={blockedReason}
              onInstall={(a, body) => onInstall(component, a, body)}
            />
          ) : null}
    </>
  );
}
