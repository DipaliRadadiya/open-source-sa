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
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";

const POLL_MS = 3000;
// Past this, stop implying steady progress (apt on a small box is slow).
const SLOW_AFTER_MS = 3 * 60 * 1000;
// Past this, stop claiming an install without the server agreeing (a stuck
// queue worker is likelier than apt) and show the server's answer.
const GIVE_UP_MS = 10 * 60 * 1000;

export function SetupChecklist({ initialSetup, versions = {}, canInstall = {}, fail2banProtection = "unknown" }) {
  const t = useTranslations("setup");
  const router = useRouter();
  const [setup, setSetup] = useState(initialSetup);
  // Keys with a POST in flight, until "installing" or the poll takes over.
  const [busy, setBusy] = useState({});
  // The backend tracks progress only for database, php and node (SetupCatalog::progressFor);
  // without this the poll would revert e.g. fail2ban to an Install button.
  const [started, setStarted] = useState({});
  const [slow, setSlow] = useState(false);
  const [finishing, startTransition] = useTransition();

  // Server state with installs started here overlaid; derived, so an override
  // stops applying once the server resolves that component.
  const components = setup.components.map((c) =>
    started[c.key] && c.state !== "installed" && c.state !== "failed"
      ? { ...c, state: "installing" }
      : c,
  );

  const anyInstalling =
    components.some((c) => c.state === "installing") || Object.keys(busy).length > 0;

  // One timer for the set, restarted on each new install.
  useEffect(() => {
    if (!Object.keys(started).length) return undefined;
    const id = setTimeout(() => setStarted({}), GIVE_UP_MS);
    return () => clearTimeout(id);
  }, [started]);

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
      // Kept outside `setup`, which the poll replaces wholesale.
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
    // The navigation re-runs the Server Component; an extra refresh would compete.
    startTransition(() => router.push("/dashboard"));
  }

  const recommended = components.filter((c) => c.recommended);
  const recommendedLeft = recommended.filter((c) => c.state !== "installed").length;
  // Progress counts everything installed, not just the recommended set, so it
  // never contradicts the "Already installed" list.
  const installedCount = components.filter((c) => c.state === "installed").length;
  const failedCount = components.filter((c) => c.state === "failed").length;
  const pct = components.length
    ? Math.round((installedCount / components.length) * 100)
    : 100;

  // Needs-attention first, already-done last.
  const rank = (c) =>
    c.state === "failed" ? 0 : c.state === "installing" ? 1 : c.state === "installed" ? 4 : c.recommended ? 2 : 3;
  const ordered = components
    .map((c, i) => ({ c, i }))
    .sort((a, b) => rank(a.c) - rank(b.c) || a.i - b.i)
    .map((x) => x.c);
  const pending = ordered.filter((c) => c.state !== "installed");
  const done = ordered.filter((c) => c.state === "installed");

  // Attention is failed only; installing stays in its own group.
  const attention = pending.filter((c) => c.state === "failed");
  const advised = pending.filter((c) => !attention.includes(c) && c.recommended);
  const optional = pending.filter((c) => !attention.includes(c) && !c.recommended);

  // The backend labels only the three components it tracks; name the others here.
  const running = components.find((c) => c.state === "installing");
  const runningLabel = setup.label ?? (running ? t("installingNamed", { name: running.title }) : null);

  const renderComponent = (component, tier = "secondary") => (
    <SetupComponent
      key={component.key}
      component={component}
      tier={tier}
      versions={versions[component.key] ?? []}
      busy={Boolean(busy[component.key])}
      // apt runs one install at a time, so others are held to avoid an apt lock.
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
    <div className="space-y-6">
      {setup.complete ? (
        <div className="flex items-center gap-3 rounded-2xl border border-success/30 bg-success/5 px-4 py-3">
          <CheckCircle2 className="size-5 shrink-0 text-success" />
          <p className="text-sm">
            <span className="font-medium">{t("allSetTitle")}</span>{" "}
            <span className="text-muted-foreground">{t("allSetBody")}</span>
          </p>
        </div>
      ) : null}

      {/* Overview panel: how far along, what failed, what is still advised. */}
      <div className="space-y-3 rounded-2xl border bg-card p-5 shadow-sm">
        {/* Percentage and counts share a line; `flex-wrap` gives two rows on narrow
            screens. */}
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5">
          <p className="text-base font-semibold tracking-tight tabular-nums">
            {t("percentComplete", { pct })}
          </p>

          {/* Separate items; failures get a tinted chip. */}
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm">
            <span className="font-medium">
              {setup.complete
                ? t("progressComplete")
                : t("summaryInstalled", { count: installedCount })}
            </span>
            {!setup.complete && failedCount ? (
              <>
                <Dot />
                <span className="rounded-md bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                  {t("progressFailed", { count: failedCount })}
                </span>
              </>
            ) : null}
            {!setup.complete && recommendedLeft ? (
              <>
                <Dot />
                <span className="text-muted-foreground">
                  {t("recommendedLeft", { count: recommendedLeft })}
                </span>
              </>
            ) : null}
          </div>
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

        {/* While installing: what is running, one at a time, and a slow note later. */}
        {anyInstalling ? (
          <p className="flex items-start gap-2 border-t pt-3 text-xs text-muted-foreground">
            <Loader2 className="mt-0.5 size-3 shrink-0 animate-spin" />
            <span>
              {runningLabel ? `${runningLabel} — ` : ""}
              {slow ? t("installSlow") : t("installOneAtATime")}
            </span>
          </p>
        ) : null}
      </div>

      {/* Three groups: needs a decision, advised, done. */}
      <Section title={t("sectionAttention")} hint={t("sectionAttentionHint")} items={attention} render={(c) => renderComponent(c, "primary")} />
      <Section title={t("sectionRecommended")} hint={t("sectionRecommendedHint")} items={advised} render={(c) => renderComponent(c, "secondary")} />
      <Section title={t("sectionOptional")} hint={t("sectionOptionalHint")} items={optional} render={(c) => renderComponent(c, "secondary")} />
      <Section
        title={t("alreadyInstalled")}
        hint={t("alreadyInstalledHint")}
        items={done}
        className="divide-y overflow-hidden rounded-2xl border bg-muted/20"
        render={(c) => renderComponent(c, "compact")}
      />

      {/* "Skip for now" only while something is outstanding; otherwise the button
          names its destination. */}
      <div className="flex flex-col gap-3 rounded-2xl border bg-muted/30 px-4 py-3.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          {setup.complete ? t("doneHint") : anyInstalling ? t("skipWhileInstalling") : t("skipHint")}
        </p>
        <Button
          onClick={finish}
          variant={setup.complete ? "default" : "outline"}
          className="shrink-0"
          disabled={finishing}
          disabledReason={finishing ? t("openingDashboard") : null}
          aria-busy={finishing}
        >
          {finishing ? <Loader2 className="size-4 animate-spin" /> : setup.complete ? <CheckCircle2 className="size-4" /> : null}
          {finishing ? t("openingDashboard") : setup.complete ? t("continue") : t("skip")}
          {finishing || setup.complete ? null : <ArrowRight className="size-4" />}
        </Button>
      </div>
    </div>
  );
}

function Section({ title, hint, items, render, className = "space-y-3" }) {
  if (!items.length) return null;
  return (
    <section className="space-y-3">
      <div className="space-y-0.5">
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

// A character separator, not a border, so it wraps with the text.
function Dot() {
  return (
    <span aria-hidden className="text-muted-foreground/50">
      ·
    </span>
  );
}
