"use client";

import { useEffect, useState, useTransition } from "react";
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
// Ceiling on how long we will claim something is installing without the server
// agreeing. Past this the queue worker is the likelier explanation than apt, and
// showing the server's own answer — even "not installed" — beats a spinner that
// will never stop.
const GIVE_UP_MS = 10 * 60 * 1000;

export function SetupChecklist({ initialSetup, versions = {} }) {
  const t = useTranslations("setup");
  const router = useRouter();
  const [setup, setSetup] = useState(initialSetup);
  // Keys with a POST in flight (before the 202 lands) — a spinner on that
  // control until the "installing" state or the poll takes over.
  const [busy, setBusy] = useState({});
  // Installs we started here, key → when. The backend only tracks progress for
  // database, php and node (SetupCatalog::progressFor maps exactly those three),
  // so a queued fail2ban install reports `pending` for its entire run. Without
  // this the very next poll overwrote the row back to an Install button while
  // apt was still going, polling then stopped because nothing looked in flight,
  // and the page never noticed the install finishing.
  const [started, setStarted] = useState({});
  const [slow, setSlow] = useState(false);
  const [finishing, startTransition] = useTransition();

  // Server truth, with our own known-started installs laid over it. Derived
  // rather than merged into `setup`: an override simply stops applying once the
  // server resolves that component, so there is no bookkeeping to prune and
  // nothing to keep in sync.
  const components = setup.components.map((c) =>
    started[c.key] && c.state !== "installed" && c.state !== "failed"
      ? { ...c, state: "installing" }
      : c,
  );

  const anyInstalling =
    components.some((c) => c.state === "installing") || Object.keys(busy).length > 0;

  // The ceiling. One timer for the whole set, restarted whenever another install
  // is started, because that is a fresh reason to keep waiting.
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
      // Remembered rather than written into `setup`: the poll replaces that
      // wholesale, so anything merged into it survives exactly one tick.
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
    // The dashboard navigation re-runs its Server Component itself. Refreshing
    // as well issued a second request and left the button looking inert while
    // both navigations competed.
    startTransition(() => router.push("/dashboard"));
  }

  const recommended = components.filter((c) => c.recommended);
  const recommendedLeft = recommended.filter((c) => c.state !== "installed").length;
  // Counted over everything installed, not just the recommended set. Driving it
  // off `recommended` alone meant a server with PHP and Redis already up read
  // "0 of 2 recommended installed · 0%" with an empty bar — directly above an
  // "Already installed" list naming two components. The bar is the answer to
  // "how far along is this server", and what is still *advised* is a separate
  // sentence rather than a second, contradictory score.
  const installedCount = components.filter((c) => c.state === "installed").length;
  const failedCount = components.filter((c) => c.state === "failed").length;
  const pct = components.length
    ? Math.round((installedCount / components.length) * 100)
    : 100;

  // Float what needs attention to the top and sink the already-done to the
  // bottom, so the next step is always the first thing the eye lands on.
  const rank = (c) =>
    c.state === "failed" ? 0 : c.state === "installing" ? 1 : c.state === "installed" ? 4 : c.recommended ? 2 : 3;
  const ordered = components
    .map((c, i) => ({ c, i }))
    .sort((a, b) => rank(a.c) - rank(b.c) || a.i - b.i)
    .map((x) => x.c);
  const pending = ordered.filter((c) => c.state !== "installed");
  const done = ordered.filter((c) => c.state === "installed");

  // Grouped by what each one asks of the reader.
  //
  // "Also available" rather than folding Node.js under "Recommended": it is not
  // recommended, and a heading that says otherwise is a heading that lies. It
  // is also not called "Optional" — this page already decided that reads as
  // "you can skip this", which is wrong for a runtime a Node site needs.
  const attention = pending.filter((c) => c.state === "failed" || c.state === "installing");
  const advised = pending.filter((c) => !attention.includes(c) && c.recommended);
  const optional = pending.filter((c) => !attention.includes(c) && !c.recommended);

  // The backend names what's running, but only for the three components it
  // tracks — for the others its label is null and the line went anonymous
  // ("One install runs at a time" with no subject). Name it ourselves then.
  const running = components.find((c) => c.state === "installing");
  const runningLabel = setup.label ?? (running ? t("installingNamed", { name: running.title }) : null);

  const renderComponent = (component, tier = "secondary") => (
    <SetupComponent
      key={component.key}
      component={component}
      tier={tier}
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

      {/* The overview, as its own panel rather than a caption floating above the
          list. It answers the three questions someone opens this page with —
          how far along, did anything break, what is still advised — and being a
          surface rather than loose text is what stops it reading as a label for
          the card underneath it. */}
      <div className="space-y-3 rounded-2xl border bg-card p-5 shadow-sm">
        {/* The percentage leads, because it is the one number that answers the
            question the page title raises. The label that used to sit here
            ("Setup progress") named the panel it was already inside. */}
        {/* Percentage and counts share the line above the bar. They are the same
            fact at two resolutions — a score and its breakdown — so giving the
            breakdown its own row below the bar spent a whole line separating
            things that belong together. `flex-wrap` lets it fall back to two
            rows on a narrow screen, where there is no room for one. */}
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5">
          <p className="text-base font-semibold tracking-tight tabular-nums">
            {t("percentComplete", { pct })}
          </p>

          {/* Counts as separate items, not one run-on sentence: each is a
              different kind of fact, and the failure is the one that has to
              catch the eye — it gets a tinted chip rather than another grey
              clause. */}
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

        {/* One live line while installing: what's running, that only one runs at
            a time, and — past a while — that it's just slow, not stuck. */}
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

      {/* Three groups, because they are three different requests of the reader:
          something went wrong and wants a decision; something is advised and
          can wait; something is done and wants nothing. Rendering them as one
          undifferentiated stack of equal cards is what made the page read as a
          wall — the tiers below carry the weight, the headings say why. */}
      <Section title={t("sectionAttention")} hint={t("sectionAttentionHint")} items={attention} render={(c) => renderComponent(c, "primary")} />
      <Section title={t("sectionRecommended")} hint={t("sectionRecommendedHint")} items={advised} render={(c) => renderComponent(c, "secondary")} />
      <Section title={t("sectionOptional")} hint={t("sectionOptionalHint")} items={optional} render={(c) => renderComponent(c, "secondary")} />
      <Section
        title={t("alreadyInstalled")}
        hint={t("alreadyInstalledHint")}
        items={done}
        // A receipt, not a queue. One bordered container with divided rows
        // reads as a single quiet block; two separately bordered cards read as
        // two more things to deal with, competing with the actions above.
        className="divide-y overflow-hidden rounded-2xl border bg-muted/20"
        render={(c) => renderComponent(c, "compact")}
      />

      {/* "Skip for now" is a fair name only while something is actually
          outstanding. With nothing left to install it named the act of giving
          up on a page where there was nothing left to give up on — so once the
          list is clear the button names its destination instead. */}
      {/* A panel in the same stack as the cards, not a rule with things loose
          under it. A bare border-t left the last decision on the page floating
          in the margin below everything, reading as page furniture rather than
          the end of the flow it belongs to. */}
      <div className="flex flex-col gap-3 rounded-2xl border bg-muted/30 px-4 py-3.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          {setup.complete ? t("doneHint") : anyInstalling ? t("skipWhileInstalling") : t("skipHint")}
        </p>
        <Button
          onClick={finish}
          variant={setup.complete ? "default" : "outline"}
          className="shrink-0"
          disabled={finishing}
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

/**
 * A titled group, rendered only when it has anything in it.
 *
 * The count sits with the heading rather than in it: "Recommended 1" scans as a
 * heading and a quantity, where "Recommended (1)" reads as part of the name.
 */
function Section({ title, hint, items, render, className = "space-y-3" }) {
  if (!items.length) return null;
  return (
    <section className="space-y-3">
      <div className="space-y-0.5">
        <div className="flex items-baseline gap-2">
          <h2 className="text-base font-semibold tracking-tight">{title}</h2>
          <span className="text-xs text-muted-foreground tabular-nums">{items.length}</span>
        </div>
        {/* One line saying why this group exists. A bare heading names a pile;
            the hint is what tells you whether the pile is yours to deal with
            now or later — which is the only reason to group them at all. */}
        {hint ? <p className="text-sm text-muted-foreground">{hint}</p> : null}
      </div>
      <div className={className}>{items.map(render)}</div>
    </section>
  );
}

// The separator between summary counts. A character, not a border, so it wraps
// with the text it divides instead of leaving a stray rule on a folded line.
function Dot() {
  return (
    <span aria-hidden className="text-muted-foreground/50">
      ·
    </span>
  );
}
