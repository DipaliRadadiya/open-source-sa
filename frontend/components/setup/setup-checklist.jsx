"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRight, CheckCircle2, Loader2 } from "lucide-react";
import { fetchSetup, runSetupAction } from "@/lib/api/setup";
import { apiMessage } from "@/lib/api/error-message";
import { SetupComponent } from "@/components/setup/setup-component";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";

const POLL_MS = 3000;
// apt on a small box is slow, but past this we stop implying steady progress —
// an unbounded spinner is a promise we have no evidence for.
const SLOW_AFTER_MS = 3 * 60 * 1000;

export function SetupChecklist({ initialSetup, versions = {} }) {
  const t = useTranslations("setup");
  const router = useRouter();
  const [setup, setSetup] = useState(initialSetup);
  // Keys with a POST in flight (before the 202 lands) — a spinner on that
  // control until the optimistic "installing" state or the poll takes over.
  const [busy, setBusy] = useState({});
  const [slow, setSlow] = useState(false);

  const anyInstalling =
    setup.status === "installing" ||
    setup.components.some((c) => c.state === "installing") ||
    Object.keys(busy).length > 0;

  // Poll only while something is in flight; pause when the tab is hidden.
  useEffect(() => {
    if (!anyInstalling) return undefined;
    let active = true;
    const startedAt = Date.now();
    const id = setInterval(async () => {
      if (document.hidden) return;
      if (Date.now() - startedAt > SLOW_AFTER_MS) setSlow(true);
      try {
        const next = await fetchSetup();
        if (active && next) setSetup(next);
      } catch {
        // Keep polling — a blip mid-install is not a failed install.
      }
    }, POLL_MS);
    return () => {
      active = false;
      clearInterval(id);
      // Installing stopped — clear the "taking longer" note for next time.
      setSlow(false);
    };
  }, [anyInstalling]);

  async function install(component, action, body) {
    setSlow(false);
    setBusy((b) => ({ ...b, [component.key]: true }));
    try {
      await runSetupAction(action, body);
      // Flip locally so the row shows progress immediately; the poll confirms.
      setSetup((s) => ({
        ...s,
        status: "installing",
        components: s.components.map((c) =>
          c.key === component.key ? { ...c, state: "installing" } : c,
        ),
      }));
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
    router.push("/dashboard");
    router.refresh();
  }

  const recommended = setup.components.filter((c) => c.recommended);
  const recommendedDone = recommended.filter((c) => c.state === "installed").length;
  // Drive the bar off the recommended set so the number and the "X of Y
  // recommended installed" sentence always tell the same story (the backend
  // `percent` counts every component, which reads as a contradiction).
  const pct = recommended.length ? Math.round((recommendedDone / recommended.length) * 100) : 100;

  // Float what needs attention to the top and sink the already-done to the
  // bottom, so the next step is always the first thing the eye lands on.
  const rank = (c) =>
    c.state === "failed" ? 0 : c.state === "installing" ? 1 : c.state === "installed" ? 4 : c.recommended ? 2 : 3;
  const ordered = setup.components
    .map((c, i) => ({ c, i }))
    .sort((a, b) => rank(a.c) - rank(b.c) || a.i - b.i)
    .map((x) => x.c);
  const pending = ordered.filter((c) => c.state !== "installed");
  const done = ordered.filter((c) => c.state === "installed");

  const renderComponent = (component) => (
    <SetupComponent
      key={component.key}
      component={component}
      versions={versions[component.key] ?? []}
      busy={Boolean(busy[component.key])}
      // apt runs one install at a time — while any is in flight, the others are
      // held so a second click can't hit an apt lock.
      locked={anyInstalling && component.state !== "installing" && !busy[component.key]}
      onInstall={install}
    />
  );

  return (
    <div className="space-y-6">
      {/* A clear payoff once the recommended set is in — the reason to finish. */}
      {setup.complete ? (
        <div className="flex items-center gap-3 rounded-2xl border border-success/30 bg-success/5 px-4 py-3">
          <CheckCircle2 className="size-5 shrink-0 text-success" />
          <p className="text-sm">
            <span className="font-medium">{t("allSetTitle")}</span>{" "}
            <span className="text-muted-foreground">{t("allSetBody")}</span>
          </p>
        </div>
      ) : null}

      <div className="space-y-2">
        <div className="flex items-center justify-between text-sm">
          <span className="font-medium">
            {setup.complete
              ? t("progressComplete")
              : t("progressCount", { done: recommendedDone, total: recommended.length })}
          </span>
          <span className="text-muted-foreground">{pct}%</span>
        </div>
        <Progress
          value={pct}
          role="progressbar"
          aria-label={t("progressLabel")}
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
          className="h-2.5"
        />
        {/* One live line while installing: what's running, that only one runs at
            a time, and — past a while — that it's just slow, not stuck. */}
        {anyInstalling ? (
          <p className="flex items-start gap-2 text-xs text-muted-foreground">
            <Loader2 className="mt-0.5 size-3 shrink-0 animate-spin" />
            <span>
              {setup.label ? `${setup.label} — ` : ""}
              {slow ? t("installSlow") : t("installOneAtATime")}
            </span>
          </p>
        ) : null}
      </div>

      <div className="space-y-3">
        {pending.map(renderComponent)}
        {done.length && pending.length ? (
          <div className="flex items-center gap-3 pt-2">
            <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {t("alreadyInstalled")}
            </span>
            <span className="h-px flex-1 bg-border" />
          </div>
        ) : null}
        {done.map(renderComponent)}
      </div>

      <div className="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          {setup.complete ? t("doneHint") : anyInstalling ? t("skipWhileInstalling") : t("skipHint")}
        </p>
        <Button onClick={finish} variant={setup.complete ? "default" : "outline"} className="shrink-0">
          {setup.complete ? <CheckCircle2 className="size-4" /> : null}
          {setup.complete ? t("continue") : t("skip")}
          {setup.complete ? null : <ArrowRight className="size-4" />}
        </Button>
      </div>
    </div>
  );
}
