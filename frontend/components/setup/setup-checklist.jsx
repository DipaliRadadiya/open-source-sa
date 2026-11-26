"use client";

import Link from "@/components/ui/app-link";
import { useEffect, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRight, CheckCircle2, Loader2, TriangleAlert } from "lucide-react";
import { fetchSetup, runSetupAction } from "@/lib/api/setup";
import { apiMessage } from "@/lib/api/error-message";
import { SetupComponent } from "@/components/setup/setup-component";
import { SystemUpdatesNote } from "@/components/setup/system-updates-note";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { cn } from "@/lib/utils";

const POLL_MS = 3000;
const UPDATES_POLL_MS = 15000;
const SLOW_AFTER_MS = 3 * 60 * 1000;
const GIVE_UP_MS = 10 * 60 * 1000;

export function SetupChecklist({ initialSetup, versions = {}, canInstall = {}, fail2banProtection = "unknown" }) {
  const t = useTranslations("setup");
  const router = useRouter();
  const [setup, setSetup] = useState(initialSetup);
  const [busy, setBusy] = useState({});
  const [started, setStarted] = useState({});
  const [slow, setSlow] = useState(false);
  const [finishing, startTransition] = useTransition();

  const listed = setup.stack === "docker" && !setup.components.some((c) => c.key === "docker")
    ? [...setup.components, { key: "docker", title: "Docker", detail: t("dockerDetail"), state: "installed", recommended: false, action: null, options: [] }]
    : setup.components;
  const components = listed.map((c) =>
    started[c.key] && c.state !== "installed" && c.state !== "failed"
      ? { ...c, state: "installing" }
      : c,
  );

  const anyInstalling =
    components.some((c) => c.state === "installing") || Object.keys(busy).length > 0;

  useEffect(() => {
    if (!Object.keys(started).length) return undefined;
    const id = setTimeout(() => setStarted({}), GIVE_UP_MS);
    return () => clearTimeout(id);
  }, [started]);

  const updatesRunning = Boolean(setup.system_updates_running);
  const pollMs = anyInstalling ? POLL_MS : updatesRunning ? UPDATES_POLL_MS : null;

  useEffect(() => {
    if (!pollMs) return undefined;
    let active = true;
    const startedAt = Date.now();
    const id = setInterval(async () => {
      if (document.hidden) return;
      if (pollMs === POLL_MS && Date.now() - startedAt > SLOW_AFTER_MS) setSlow(true);
      try {
        const next = await fetchSetup();
        if (active && next) setSetup(next);
      } catch {
        // retry on the next poll
      }
    }, pollMs);
    return () => {
      active = false;
      clearInterval(id);
      setSlow(false);
    };
  }, [pollMs]);

  async function install(component, action, body) {
    setSlow(false);
    setBusy((b) => ({ ...b, [component.key]: true }));
    try {
      await runSetupAction(action, body);
      // Kept outside setup, which the poll replaces.
      setStarted((s) => ({ ...s, [component.key]: true }));
    } catch (error) {
      toast.error(apiMessage(error, t("installFailed")));
    } finally {
      setBusy((b) => {
        const next = { ...b };
        delete next[component.key];
        return next;
      });
    }
  }

  function finish() {
    startTransition(() => router.push("/dashboard"));
  }

  const recommended = components.filter((c) => c.recommended);
  const recommendedLeft = recommended.filter((c) => c.state !== "installed").length;
  const recommendedInstalled = recommended.length - recommendedLeft;
  const optionalLeft = components.filter((c) => !c.recommended && c.state !== "installed").length;
  const failedCount = components.filter((c) => c.state === "failed").length;
  const pct = recommended.length
    ? Math.round((recommendedInstalled / recommended.length) * 100)
    : 100;

  const rank = (c) =>
    c.state === "failed" ? 0 : c.state === "installing" ? 1 : c.state === "installed" ? 4 : c.recommended ? 2 : 3;
  const ordered = components
    .map((c, i) => ({ c, i }))
    .sort((a, b) => rank(a.c) - rank(b.c) || a.i - b.i)
    .map((x) => x.c);
  const pending = ordered.filter((c) => c.state !== "installed");
  const done = ordered.filter((c) => c.state === "installed");

  const attention = pending.filter((c) => c.state === "failed");
  const advised = pending.filter((c) => !attention.includes(c) && c.recommended);
  const optional = pending.filter((c) => !attention.includes(c) && !c.recommended);

  const running = components.find((c) => c.state === "installing");
  const runningLabel = setup.label ?? (running ? t("installingNamed", { name: running.title }) : null);

  const renderComponent = (component, tier = "secondary") => (
    <SetupComponent
      key={component.key}
      component={component}
      tier={tier}
      versions={versions[component.key] ?? []}
      busy={Boolean(busy[component.key])}
      locked={anyInstalling && component.state !== "installing" && !busy[component.key]}
      denied={canInstall[component.key] === false}
      onInstall={install}
      note={
        component.key === "fail2ban" && component.state === "installed" && fail2banProtection === "off" ? (
          <span className="inline-flex items-start gap-1.5 text-foreground">
            <TriangleAlert className="mt-px size-3.5 shrink-0 text-warning" aria-hidden />
            <span>
            {t.rich("fail2banOff", {
              link: (chunks) => (
                <Link href="/fail2ban" className="font-medium underline underline-offset-2">
                  {chunks}
                </Link>
              ),
            })}
            </span>
          </span>
        ) : null
      }
    />
  );

  return (
    <div className="space-y-8">
      <SystemUpdatesNote running={updatesRunning} />

      <div className="rounded-2xl border border-border/70 bg-card p-5 shadow-e1">
        {setup.complete ? (
          <div className={cn("flex gap-3", optionalLeft ? "items-start" : "items-center")}>
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success/10 text-success">
              <CheckCircle2 className="size-[18px]" aria-hidden />
            </span>
            <div className="min-w-48 flex-1 space-y-0.5">
              <p className="font-semibold tracking-tight">{t("allSetTitle")}</p>
              {optionalLeft ? <p className="text-sm text-muted-foreground">{t("allSetBody")}</p> : null}
            </div>
          </div>
        ) : (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
              <p className="font-semibold tracking-tight tabular-nums">
                {t("recommendedProgress", { done: recommendedInstalled, total: recommended.length })}
              </p>
              {failedCount || optionalLeft ? (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  {failedCount ? (
                    <span className="rounded-md bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                      {t("progressFailed", { count: failedCount })}
                    </span>
                  ) : null}
                  {optionalLeft ? (
                    <span className="text-muted-foreground">{t("optionalLeft", { count: optionalLeft })}</span>
                  ) : null}
                </div>
              ) : null}
            </div>
            <Progress
              value={pct}
              role="progressbar"
              aria-label={t("progressLabel")}
              aria-valuenow={pct}
              aria-valuemin={0}
              aria-valuemax={100}
              className="h-2"
            />
          </div>
        )}

        {anyInstalling ? (
          <p className="mt-4 flex items-start gap-2 border-t pt-3 text-xs text-muted-foreground">
            <Loader2 className="mt-0.5 size-3 shrink-0 animate-spin" />
            <span>
              {runningLabel ? `${runningLabel} — ` : ""}
              {slow ? t("installSlow") : t("installOneAtATime")}
            </span>
          </p>
        ) : null}
      </div>

      <Section title={t("sectionAttention")} hint={t("sectionAttentionHint")} items={attention} render={(c) => renderComponent(c, "primary")} />
      <Section title={t("sectionRecommended")} hint={t("sectionRecommendedHint")} items={advised} render={(c) => renderComponent(c, "secondary")} />
      <Section title={t("sectionOptional")} hint={t("sectionOptionalHint")} items={optional} render={(c) => renderComponent(c, "secondary")} />
      <Section
        title={t("alreadyInstalled")}
        items={done}
        className="divide-y overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1"
        render={(c) => renderComponent(c, "compact")}
      />

      <div className="flex flex-col gap-3 border-t pt-6 sm:flex-row sm:items-center sm:justify-between">
        {setup.complete ? <span className="hidden sm:block" /> : (
          <p className="text-sm text-muted-foreground">{anyInstalling ? t("skipWhileInstalling") : t("skipHint")}</p>
        )}
        <Button
          onClick={finish}
          variant={setup.complete ? "default" : "outline"}
          className="shrink-0"
          disabled={finishing}
          disabledReason={finishing ? t("openingDashboard") : null}
          aria-busy={finishing}
        >
          {finishing ? <Loader2 className="size-4 animate-spin" /> : null}
          {finishing ? t("openingDashboard") : setup.complete ? t("continue") : t("skip")}
          {finishing ? null : <ArrowRight className="size-4" />}
        </Button>
      </div>
    </div>
  );
}

function Section({ title, hint, items, render, className = "space-y-3" }) {
  if (!items.length) return null;
  return (
    <section className="space-y-3">
      <div className="space-y-1">
        <div className="flex items-baseline gap-2">
          <h2 className="text-base font-semibold tracking-tight">{title}</h2>
          <span className="text-xs text-muted-foreground tabular-nums">{items.length}</span>
        </div>
        {hint ? <p className="text-sm text-muted-foreground">{hint}</p> : null}
      </div>
      <div className={className}>{items.map(render)}</div>
    </section>
  );
}
