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

function VersionInstall({ versions, action, busy, onInstall }) {
  const t = useTranslations("setup");
  const options = useMemo(
    () => versions.map((v) => ({ value: v.version, label: v.version, hint: v.lifecycle?.status })),
    [versions],
  );
  const defaultVersion =
    versions.find((v) => v.lifecycle?.status === "lts")?.version ?? options[0]?.value ?? "";
  const [version, setVersion] = useState(defaultVersion);

  if (!options.length) {
    return <p className="mt-3 text-sm text-muted-foreground">{t("noVersions")}</p>;
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
      <Button className="shrink-0" disabled={!version || busy} onClick={() => onInstall(action, { version })}>
        {busy ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
        {t("install")}
      </Button>
    </div>
  );
}

// Identity chip: what the component IS. State is carried by the pill, not here.
function IconChip({ meta }) {
  const { Icon, tint, chip } = meta;
  return (
    <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-xl", chip)}>
      <Icon className={cn("size-5", tint)} aria-hidden />
    </span>
  );
}

// One pill that states where this component stands, in words (never colour
// alone).
function StatusPill({ state, recommended, detail }) {
  const t = useTranslations("setup");
  if (state === "installed") {
    return (
      <Badge variant="success" className="gap-1 font-normal">
        {t("pillInstalled")}
        {detail ? <span className="opacity-80">· {detail}</span> : null}
      </Badge>
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
export function SetupComponent({ component, versions = [], busy = false, locked = false, onInstall }) {
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
  const disabled = busy || locked;
  const meta = componentMeta(component.key);

  return (
    <div
      aria-busy={installing}
      className={cn(
        "rounded-2xl border bg-card p-4 shadow-sm transition-colors",
        failed && "border-destructive/30 bg-destructive/[0.03]",
        installed && "bg-muted/30 shadow-none",
        !installed && !failed && component.recommended && "border-primary/25",
      )}
    >
      <div className="flex items-start gap-4">
        <IconChip meta={meta} />

        <div className="min-w-0 flex-1">
          {/* Title + description stay a tight unit; the interactive blocks below
              are deliberately outside this group so `space-y` can't squeeze them. */}
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="font-medium leading-tight">{component.title}</p>
              <StatusPill state={installing ? "installing" : state} recommended={component.recommended} detail={component.detail} />
            </div>
            {component.description ? (
              <p className="text-sm leading-5 text-muted-foreground">{component.description}</p>
            ) : null}
          </div>

          {/* A failed component shows a reason. The backend message can promise
              a "reference" it didn't send, so we only trust it when a reference
              actually came with it; otherwise fall back to self-contained copy. */}
          {failed ? (
            <p className="mt-3 flex items-start gap-2 rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive">
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
            <div className="mt-3">
              <DatabaseOptions options={options} busy={disabled} onInstall={(a) => onInstall(component, a)} />
            </div>
          ) : null}

          {/* Inline version picker for PHP/Node. */}
          {isRuntime && !installed && !installing ? (
            <VersionInstall
              versions={runtimeVersions}
              action={action}
              busy={disabled}
              onInstall={(a, body) => onInstall(component, a, body)}
            />
          ) : null}
        </div>

        {/* Right-side action for the simple states (the others render inline
            above). */}
        <div className="shrink-0">
          {installed || installing || isRuntime || hasOptions ? null : failed ? (
            action && component.retryable ? (
              <Button size="sm" variant="outline" disabled={disabled} onClick={() => onInstall(component, action)}>
                <RotateCw className="size-3.5" />
                {t("retry")}
              </Button>
            ) : null
          ) : action ? (
            <Button size="sm" disabled={disabled} onClick={() => onInstall(component, action)}>
              <Download className="size-3.5" />
              {t("install")}
            </Button>
          ) : (
            <span className="text-xs text-muted-foreground">{t("notInstallable")}</span>
          )}
        </div>
      </div>
    </div>
  );
}
