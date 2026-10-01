import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { CircleAlert, Download, Loader2, RotateCw } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { DatabaseInstallProgress } from "@/components/databases/database-install-progress";
import { DatabaseOptions } from "@/components/setup/database-options";
import { componentMeta } from "@/components/setup/component-meta";

const RUNTIME_KEYS = new Set(["php", "node"]);

// Shown right after the 202 until the first poll returns backend detail.
const QUEUED_DATABASE_PROGRESS = {
  status: "installing",
  current_step: "queued",
};

function VersionInstall({ versions, action, disabled, disabledReason, onInstall }) {
  const t = useTranslations("setup");
  const options = useMemo(
    () => versions.map((v) => ({ value: v.version, label: v.version, hint: v.lifecycle?.status })),
    [versions],
  );
  const defaultVersion =
    versions.find((v) => v.lifecycle?.status === "lts")?.version ?? options[0]?.value ?? "";
  const [version, setVersion] = useState(defaultVersion);

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
      {/* No spinner: this picker unmounts once its own install starts, so one could only mean another install. */}
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

// Identity chip: what the component is. State is carried by the pill.
function IconChip({ meta, small = false }) {
  const { Icon, tint, chip } = meta;
  return (
    <span className={cn("flex shrink-0 items-center justify-center rounded-xl", small ? "size-9" : "size-10", chip)}>
      <Icon className={cn(small ? "size-4" : "size-5", tint)} aria-hidden />
    </span>
  );
}

// States where the component stands, in words (never colour alone).
function StatusPill({ state, recommended, detail }) {
  const t = useTranslations("setup");
  // Beside the badge, not inside: a shrink-0 badge would overflow narrow screens.
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
      <Badge variant="muted" className="gap-1.5 font-normal text-primary">
        <Loader2 className="size-3 animate-spin" />
        {t("pillInstalling")}
      </Badge>
    );
  }
  if (state === "unavailable") {
    return <Badge variant="muted" className="font-normal">{t("pillUnavailable")}</Badge>;
  }
  if (state === "failed") {
    return <Badge variant="destructive" className="font-normal">{t("pillFailed")}</Badge>;
  }
  // No "Optional" badge: it would read as "you can skip this".
  return recommended ? (
    <Badge variant="warning" className="font-normal">{t("recommended")}</Badge>
  ) : null;
}

// Failure UI is gated strictly on `state === "failed"`.
export function SetupComponent({ component, versions = [], busy = false, locked = false, denied = false, tier = "secondary", note = null, onInstall }) {
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
  // `installing` is this component's own progress (spinner); `blocked` is apt's lock
  // held elsewhere or no permission (disabled + reason only).
  const blocked = busy || locked || denied;
  const blockedReason = denied ? t("installNotPermitted") : locked ? t("lockedByOtherInstall") : null;
  // A neutral fact, so the card sits back like a finished one.
  const unavailable = isRuntime && runtimeVersions.length === 0 && !installed && !installing;
  // Title, sentence and one button: the button sits beside them.
  const simple = !hasOptions && !isRuntime;
  const meta = componentMeta(component.key);

  /** A finished component is a compact line, so what still needs a decision carries the weight. */
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
          {/* Keeps the description (why it matters); wraps rather than clamps. */}
          {component.description ? (
            <p className="text-xs text-muted-foreground">{component.description}</p>
          ) : null}
          {note ? <p className="text-xs">{note}</p> : null}
        </div>
        {/* Wraps, since `detail` can be a sentence. */}
        <div className="flex min-w-0 flex-wrap items-center justify-end gap-x-2 gap-y-1">
          <StatusPill state="installed" detail={component.detail} />
        </div>
      </div>
    );
  }

  return (
    <div
      aria-busy={installing}
      // Failure shows in the badge and reason box, not a red card. No tint for
      // recommended: a primary border means "selected" elsewhere.
      className={cn(
        "rounded-2xl border transition-colors",
        // Primary: a surface with a banded header; secondary: same anatomy, flatter.
        primary ? "overflow-hidden bg-card shadow-sm" : "bg-card/50 p-4",
        !primary && installed && "bg-muted/30 shadow-none",
        !primary && unavailable && "bg-muted/20 shadow-none",
      )}
    >
      <div
        className={cn(
          "flex gap-4",
          simple ? "items-center" : "items-start",
          primary && "border-b bg-muted/30 px-5 py-4",
        )}
      >
        <IconChip meta={meta} small={!primary} />

        <div className="min-w-0 flex-1">
          {/* The interactive blocks stay outside this group so `space-y` cannot squeeze them. */}
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

          {/* On a primary card the body renders below the band instead. */}
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

        {/* Simple states only; the others render inline above. */}
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

// Shared so the primary and secondary placements cannot drift.
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
  const databaseProgress =
    component.key === "database"
      ? component.progress ??
        (component.state === "installing" ? QUEUED_DATABASE_PROGRESS : null)
      : null;

  return (
    <>
      {databaseProgress ? (
        <DatabaseInstallProgress
          progress={databaseProgress}
          label={component.title}
          className={!flush ? "mt-3" : undefined}
        />
      ) : null}

      {/* Legacy fallback for APIs that predate the database progress object. */}
      {failed && !databaseProgress ? (
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
