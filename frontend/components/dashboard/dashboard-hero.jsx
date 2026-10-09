import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { CircleAlert, FileText, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { IsoServer } from "@/components/illustrations/iso-server";

const CHIP = "rounded-full bg-white/15 px-2.5 py-1 ring-1 ring-white/25 backdrop-blur";

// The page's h1. Says in one line whether anything is wrong, so the rest of
// the page is detail rather than the place you find out.
export async function DashboardHero({
  userName = null,
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
    <section className="relative overflow-hidden rounded-2xl bg-hero text-white shadow-e3">
      <div className="bg-hero-grid pointer-events-none absolute inset-0" />
      {/* One compact row (Krishna, 6 Oct): the drawing leads the words on the left,
          the buttons stand alone on the right. Chips share the summary line. */}
      <div className="relative flex items-center gap-6 px-5 py-5 md:px-7">
        {/* -my so the drawing never sets the banner's height. */}
        <IsoServer className="pointer-events-none -my-4 -ml-2 hidden h-32 w-auto shrink-0 sm:block" />
        {/* Words and buttons wrap inside their own box, so on a narrow screen the
            buttons drop under the words rather than under the drawing. */}
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-6 gap-y-4">
        <div className="min-w-0 flex-1 basis-72">
          {userName ? <p className="truncate text-sm text-white/85">{t("hero.greeting", { name: userName })}</p> : null}
          <h1 className="mt-0.5 flex items-center gap-2 text-xl font-semibold tracking-tight text-balance md:text-2xl">
            {down ? <CircleAlert className="size-6 shrink-0" aria-hidden /> : null}
            {title}
          </h1>
          <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
            {summary ? <p className="text-sm text-white/90">{summary}</p> : null}
            {/* No OS chip: it is on the sidebar card and in Server information. */}
            {facts?.uptime?.human ? <span className={CHIP}>{t("hero.up", { time: facts.uptime.human })}</span> : null}
            {ip ? (
              <span className={`${CHIP} flex items-center gap-1 py-0.5 pr-0.5 font-mono`}>
                {ip}
                <CopyButton
                  value={ip}
                  label={t("info.copyIp")}
                  className="size-6 text-white/85 hover:bg-white/20 hover:text-white"
                />
              </span>
            ) : null}
          </div>
        </div>

        {canCreate || canViewLogs ? (
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            {canViewLogs ? (
              <Button asChild variant="ghost" className="text-white hover:bg-white/15 hover:text-white dark:hover:bg-white/15">
                <Link href="/logs" prefetch={false}>
                  <FileText /> {t("hero.viewLogs")}
                </Link>
              </Button>
            ) : null}
            {canCreate ? (
              <Button asChild className="bg-white text-[var(--primary-700,var(--primary))] shadow-none hover:bg-white/90 dark:hover:bg-white/90">
                <Link href="/applications/create" prefetch={false}>
                  <Plus /> {tApplications("create")}
                </Link>
              </Button>
            ) : null}
          </div>
        ) : null}
        </div>
      </div>
    </section>
  );
}
