import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, CircleAlert, Download, Loader2, RotateCw } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { DatabaseInstallProgress } from "@/components/databases/database-install-progress";
import { DatabaseOptions } from "@/components/setup/database-options";
import { componentMeta } from "@/components/setup/component-meta";

const RUNTIME_KEYS = new Set(["php", "node"]);

const QUEUED_DATABASE_PROGRESS = {
  status: "installing",
  current_step: "queued",
};

function VersionInstall({ versions, action, recommended, disabled, disabledReason, onInstall }) {
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
    <div className="mt-3 flex flex-wrap items-center gap-2">
      <div className="w-full sm:w-56">
        <Combobox
          options={options}
          value={version}
          onChange={setVersion}
          placeholder={t("chooseVersion")}
          searchPlaceholder={t("chooseVersion")}
        />
      </div>
      <Button
        className="shrink-0"
        variant={recommended ? "default" : "outline"}
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

function IconChip({ meta, small = false }) {
  const { Icon, tint, chip } = meta;
  return (
    <span className={cn("flex shrink-0 items-center justify-center rounded-xl", small ? "size-9" : "size-10", chip)}>
      <Icon className={cn(small ? "size-4" : "size-5", tint)} aria-hidden />
    </span>
  );
}

function StatusPill({ state, detail }) {
  const t = useTranslations("setup");
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
  return null;
}

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
  const blocked = busy || locked || denied;
  const blockedReason = denied ? t("installNotPermitted") : locked ? t("lockedByOtherInstall") : null;
  const unavailable = isRuntime && runtimeVersions.length === 0 && !installed && !installing;
  const simple = !hasOptions && !isRuntime;
  const meta = componentMeta(component.key);

  const primary = tier === "primary";

  if (tier === "compact") {
    return (
      <div className="flex items-center gap-3 px-4 py-3">
        <IconChip meta={meta} small />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium">{component.title}</p>
          {component.detail ? (
            <p className="text-xs text-muted-foreground tabular-nums">{component.detail}</p>
          ) : null}
          {note ? <p className="mt-1 text-xs">{note}</p> : null}
        </div>
        <span className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-success">
          <CheckCircle2 className="size-4" aria-hidden />
          {t("pillInstalled")}
        </span>
      </div>
    );
  }

  const trailing = installed || installing || isRuntime || hasOptions ? null : failed ? (
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
      variant={component.recommended ? "default" : "outline"}
      disabled={blocked}
      disabledReason={blockedReason}
      onClick={() => onInstall(component, action)}
    >
      <Download className="size-3.5" />
      {t("install")}
    </Button>
  ) : (
    <span className="text-xs text-muted-foreground">{t("notInstallable")}</span>
  );

  return (
    <div
      aria-busy={installing}
      className={cn(
        "rounded-2xl border border-border/70 bg-card shadow-e1 transition-colors",
        primary ? "overflow-hidden" : "p-4 sm:p-5",
        !primary && unavailable && "bg-muted/30 shadow-none",
      )}
    >
      <div
        className={cn(
          "grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-3 sm:grid-cols-[auto_minmax(0,1fr)_auto]",
          simple ? "items-center" : "items-start",
          primary && "border-b bg-muted/30 px-5 py-4",
        )}
      >
        <IconChip meta={meta} small={!primary} />

        <div className="min-w-0">
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className={cn("font-medium leading-tight", primary && "text-base")}>
                {component.title}
              </p>
              <StatusPill state={unavailable ? "unavailable" : installing ? "installing" : state} detail={component.detail} />
            </div>
            {component.description ? (
              <p className="text-sm leading-5 text-muted-foreground">{component.description}</p>
            ) : null}
          </div>

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

        {trailing ? <div className="col-start-2 sm:col-start-auto">{trailing}</div> : null}
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
              recommended={component.recommended}
              disabled={blocked}
              disabledReason={blockedReason}
              onInstall={(a, body) => onInstall(component, a, body)}
            />
          ) : null}
    </>
  );
}
