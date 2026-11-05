import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { CircleAlert, FileText, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { PanelServer } from "@/components/illustrations/steel/panel-server";

// Square mono chips, as in the prototype.
// One height for both chips, the IP one included (its copy button made it taller).
const CHIP = "inline-flex h-6 items-center rounded-[2px] px-2 font-mono text-xs font-medium tracking-[0.08em] uppercase ring-1 ring-[var(--hero-line)]";

// The page's h1. Says in one line whether anything is wrong, so the rest of
// the page is detail rather than the place you find out.
export async function DashboardHero({
  facts,
  health,
  findings = [],
  applications = null,
  canCreate = false,
  canViewLogs = false,
}) {
  const t = await getTranslations("serverDashboard");
  const tApplications = await getTranslations("applications");
  const down = health?.down?.length ?? 0;
  // Counted like the attention panel: kinds of problem, not applications.
  const problems = new Set(findings.map((finding) => finding.kind)).size;

  // A failed or forbidden read gives no verdict; "Everything is running" would be a guess.
  // The banner speaks for the server only. "2 things need your attention" sat here beside
  // "View logs", and people read it as being about the logs (Krishna's team, 8 Oct); the
  // count now heads the list it counts, right below.
  const title = down
    ? t("hero.down", { count: down })
    : problems
      ? t("hero.running")
      : applications && !applications.length
        ? t("hero.welcome")
        : health || applications
          ? t("hero.healthy")
          : t("hero.unknown");

  const online = applications?.filter((a) => a.status === "active" && !a.is_disabled).length ?? 0;
  const summary = applications
    ? applications.length
      ? t("hero.apps", { online, total: applications.length })
      : t("hero.noApps")
    : null;

  const ip = facts?.public_ip ?? facts?.ip;

  return (
    // One compact status bar (user feedback, 5 Nov): the drawing and the large headline took
    // prime space; the counts and checks that matter now sit right below instead.
    <section className="relative overflow-hidden rounded-2xl bg-hero">
      <div className="bg-hero-grid pointer-events-none absolute inset-0" />
      {/* Drafting-sheet corner brackets (prototype; Krishna, 5 Nov: keep them). */}
      <span aria-hidden className="pointer-events-none absolute top-1.5 left-1.5 size-4 border-t-2 border-l-2 border-primary" />
      <span aria-hidden className="pointer-events-none absolute right-1.5 bottom-1.5 size-4 border-r-2 border-b-2 border-primary" />
      <div className="relative flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4 md:px-6">
        {/* Small, beside the words (Krishna, 5 Nov): the server keeps its picture without taking
            the row. Decorative; hidden on a phone. */}
        <PanelServer
          total={applications ? applications.length : null}
          online={online}
          alert={down > 0}
          className="pointer-events-none -my-2 hidden h-16 w-auto shrink-0 sm:block"
        />
        <div className="min-w-0 flex-1 basis-72">
          <h1 className="flex items-center gap-2 text-lg font-semibold tracking-tight text-balance">
            {down ? (
              <CircleAlert className="size-5 shrink-0 text-destructive" aria-hidden />
            ) : (
              <span aria-hidden className={`size-2 shrink-0 ${health || applications ? "bg-primary" : "bg-muted-foreground"}`} />
            )}
            {title}
          </h1>
          <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
            {summary ? <p className="text-sm text-[var(--hero-muted)]">{summary}</p> : null}
            {/* No OS chip: it is on the sidebar card and in Server information. */}
            {facts?.uptime?.human ? <span className={CHIP}>{t("hero.up", { time: facts.uptime.human })}</span> : null}
            {ip ? (
              <span className={`${CHIP} gap-1 pr-0.5`}>
                {ip}
                <CopyButton
                  value={ip}
                  label={t("info.copyIp")}
                  className="size-5 rounded-[2px] text-[var(--hero-muted)] hover:bg-[var(--hero-wash)] hover:text-[var(--hero-fg)] [&_svg]:size-3"
                />
              </span>
            ) : null}
          </div>
        </div>

        {canCreate || canViewLogs ? (
          // max-w-full: on a phone the pair is wider than the banner in German and Russian,
          // and shrink-0 alone kept it on one line and cut "Create application" off.
          <div className="flex max-w-full shrink-0 flex-wrap items-center gap-2">
            {canViewLogs ? (
              <Button asChild variant="ghost" className="border border-[var(--hero-line)] text-[var(--hero-fg)] hover:bg-[var(--hero-wash)] hover:text-[var(--hero-fg)] dark:hover:bg-[var(--hero-wash)]">
                <Link href="/logs" prefetch={false}>
                  <FileText /> {t("hero.viewLogs")}
                </Link>
              </Button>
            ) : null}
            {canCreate ? (
              <Button asChild className="shadow-none">
                <Link href="/applications/create" prefetch={false}>
                  <Plus /> {tApplications("create")}
                </Link>
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
    </section>
  );
}
