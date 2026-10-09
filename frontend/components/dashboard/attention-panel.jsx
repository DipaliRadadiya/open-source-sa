import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { ArrowRight, CircleAlert, CircleX, GitBranch, LockOpen, Power, Rocket, ServerCrash } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";

const KIND_STYLE = {
  failed: { icon: CircleX, tone: "destructive" },
  deployFailed: { icon: Rocket, tone: "destructive" },
  processDown: { icon: Power, tone: "destructive" },
  insecure: { icon: LockOpen, tone: "warning" },
  git: { icon: GitBranch, tone: "warning" },
};


const TONE = {
  destructive: "bg-destructive-soft text-destructive ring-destructive/20",
  warning: "bg-warning-soft text-warning ring-warning/25",
};

// One line per problem (Krishna, 6 Oct: the two-line cards pushed the server's
// details down the page). The longer explanation is one hover away; the link
// leads to the page that explains it in full.
function Row({ icon: Icon, tone, title, detail, names, href, action }) {
  return (
    <li
      title={detail}
      // Wraps: when the text would get under 16rem the link drops below it, instead of
      // squeezing the title to three lines (German, phones).
      className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl bg-card px-3 py-2.5 ring-1 ring-border/70"
    >
      <span className={cn("flex size-8 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset", TONE[tone])}>
        <Icon className="size-4" aria-hidden />
      </span>
      {/* Title, then the names on a line of their own (beside the title they were cut to
          "QA G…"). No small mark before the title: the tile already says the tone (7 Oct). */}
      <div className="min-w-0 flex-1 basis-64">
        <p className="text-sm leading-snug font-medium break-words">{title}</p>
        {names ? (
          <p className="mt-0.5 truncate text-xs text-muted-foreground" title={names}>
            {names}
          </p>
        ) : null}
        <span className="sr-only">{detail}</span>
      </div>
      {href ? (
        <Link
          href={href}
          prefetch={false}
          className="ml-auto inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
        >
          {action}
          <ArrowRight className="size-3.5" aria-hidden />
        </Link>
      ) : null}
    </li>
  );
}

// One row per KIND of problem, not per application, with a way in: the
// dashboard summarises, the applications page enumerates.
export async function AttentionPanel({ findings = [], health, applicationsKnown = true, canViewServices = false }) {
  const t = await getTranslations("serverDashboard");
  const down = health?.down ?? [];

  const kinds = [];
  for (const finding of findings) {
    let kind = kinds.find((k) => k.kind === finding.kind);
    if (!kind) {
      kind = { kind: finding.kind, items: [] };
      kinds.push(kind);
    }
    kind.items.push(finding);
  }

  const count = kinds.length + (down.length ? 1 : 0);
  // An unanswered applications read is not "nothing to fix".
  const verdictKnown = applicationsKnown;

  // All clear: the banner says "Everything is running", so the panel steps aside.
  if (!count && verdictKnown) return null;

  return (
    // The count heads the rows it counts (it used to be the banner's headline, where it
    // read as being about the logs button beside it).
    <section aria-labelledby={count ? "attention-heading" : undefined} aria-label={count ? undefined : t("attention.title")} className="space-y-2.5">
      {count ? (
        <h2 id="attention-heading" className="flex items-center gap-2 text-sm font-semibold">
          <CircleAlert className="size-4 text-warning" aria-hidden />
          {t("hero.attention", { count })}
        </h2>
      ) : null}
      {count ? (
        <ul className="grid gap-2 lg:grid-cols-[repeat(auto-fit,minmax(26rem,1fr))]">
            {down.length ? (
              <Row
                icon={ServerCrash}
                tone="destructive"
                title={t("info.servicesDownCount", { count: down.length, total: health.total })}
                detail={t("info.servicesDown", { names: down.map((s) => s.label).join(", "), count: down.length })}
                href={canViewServices ? "/services" : null}
                action={t("attention.servicesAction")}
              />
            ) : null}
            {kinds.map(({ kind, items }) => {
              const style = KIND_STYLE[kind] ?? KIND_STYLE.failed;
              const one = items.length === 1;
              return (
                <Row
                  key={kind}
                  icon={style.icon}
                  tone={style.tone}
                  title={one ? t(`attention.${kind}.chip`, { site: items[0].site }) : t(`attention.${kind}.many`, { count: items.length })}
                  names={one ? null : items.map((item) => item.site).join(", ")}
                  // The API's own reason when it has one; it knows which step failed.
                  detail={`${t(`attention.${kind}.detail`)}${one && items[0].detail ? ` — ${items[0].detail}` : ""}`}
                  href={one ? items[0].href : "/applications"}
                  action={one ? t(`attention.${kind}.action`) : t("attention.viewApplications")}
                />
              );
            })}
        </ul>
      ) : (
        <p className={cn("flex items-center gap-2 rounded-xl bg-card px-4 py-3 text-sm text-muted-foreground ring-1 ring-border/70", PANEL_CARD)}>
          <CircleX className="size-4 shrink-0 text-destructive" aria-hidden />
          {t("loadFailed")}
        </p>
      )}
    </section>
  );
}
