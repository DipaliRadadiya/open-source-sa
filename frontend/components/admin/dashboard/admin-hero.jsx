import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { ArrowUpCircle, CircleAlert, Stethoscope } from "lucide-react";
import { Button } from "@/components/ui/button";

// Square mono chips, as on the server dashboard's banner.
const CHIP = "inline-flex h-6 items-center gap-1.5 rounded-[2px] px-2 font-mono text-xs font-medium tracking-[0.08em] uppercase ring-1 ring-[var(--hero-line)]";

// The page's h1, in the server dashboard's banner: one line on whether the panel is
// fine, and the facts the four tiles used to repeat (version, update, Central).
export async function AdminHero({ userName = null, doctor = null, update = null, central = null }) {
  const t = await getTranslations("admin.hero");

  const failed = doctor?.failed ?? 0;
  const title = !doctor ? t("unknown") : failed ? t("failing", { count: failed }) : t("healthy");

  const installed = update?.installed?.version ?? null;
  const available = update?.update_available ? update.available?.version : null;

  return (
    <section className="relative overflow-hidden rounded-2xl bg-hero">
      <div className="bg-hero-grid pointer-events-none absolute inset-0" />
      <span aria-hidden className="pointer-events-none absolute top-1.5 left-1.5 size-4 border-t-2 border-l-2 border-primary" />
      <span aria-hidden className="pointer-events-none absolute right-1.5 bottom-1.5 size-4 border-r-2 border-b-2 border-primary" />
      <div className="relative flex flex-wrap items-center gap-x-6 gap-y-4 px-5 py-6 md:px-8">
        <div className="min-w-0 flex-1 basis-72">
          {userName ? (
            <p className="flex items-center gap-2 truncate font-mono text-xs font-medium tracking-[0.12em] text-[var(--hero-muted)] uppercase">
              <span aria-hidden className="size-1.5 shrink-0 bg-primary" />
              {t("greeting", { name: userName })}
            </p>
          ) : null}
          {/* One step below the server banner's title (Krishna, 17 Oct: too big here). */}
          <h1 className="mt-0.5 flex items-center gap-2 text-lg font-semibold tracking-tight text-balance">
            {failed ? <CircleAlert className="size-[18px] shrink-0" aria-hidden /> : null}
            {title}
          </h1>
          <div className="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
            {installed ? <span className={CHIP}>{t("version", { version: installed })}</span> : null}
            {available ? (
              <span className={`${CHIP} text-primary ring-primary/50`}>{t("updateAvailable", { version: available })}</span>
            ) : update ? (
              <span className={CHIP}>{t("upToDate")}</span>
            ) : null}
            {central === null ? null : (
              <span className={CHIP}>
                <span
                  aria-hidden
                  className={`size-1.5 ${central === "on" ? "bg-success" : central === "waiting" ? "bg-warning" : "bg-[var(--hero-muted)]"}`}
                />
                {central === "on" ? t("centralOn") : central === "waiting" ? t("centralWaiting") : t("centralOff")}
              </span>
            )}
          </div>
        </div>

        <div className="flex max-w-full shrink-0 flex-wrap items-center gap-2">
          {/* Filled when it is the only action; an outline button beside Update. The banner's
              see-through style read as a label on its own (Krishna, 17 Oct). */}
          <Button asChild variant={available ? "outline" : "default"} className="shadow-none">
            <Link href="/admin/doctor" prefetch={false}>
              <Stethoscope /> {t("health")}
            </Link>
          </Button>
          {/* The main action only when there is one to take. */}
          {available ? (
            <Button asChild className="shadow-none">
              <Link href="/admin/panel-update" prefetch={false}>
                <ArrowUpCircle /> {t("update")}
              </Link>
            </Button>
          ) : null}
        </div>
      </div>
    </section>
  );
}
