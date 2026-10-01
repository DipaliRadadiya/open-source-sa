import { useTranslations, useFormatter } from "next-intl";
import { ArrowRight, ArrowUpCircle, CircleCheck, WifiOff } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * The band every resting state of this page opens with, so the three states
 * share one shape: status chip, one-word status, the version, then meta, with
 * the actions closing the same row. The version is 18px so the page heading
 * stays the largest text.
 */
const TONES = {
  update: {
    Icon: ArrowUpCircle,
    chip: "bg-primary/15 ring-1 ring-primary/20",
    tint: "text-primary",
    band: "bg-gradient-to-r from-primary/[0.07] to-transparent",
  },
  current: {
    Icon: CircleCheck,
    chip: "bg-success/15 ring-1 ring-success/20",
    tint: "text-success",
    band: "bg-gradient-to-r from-success/[0.06] to-transparent",
  },
  offline: {
    Icon: WifiOff,
    chip: "bg-muted ring-1 ring-border",
    tint: "text-muted-foreground",
    band: "",
  },
};

export function UpdateHeader({ state, divided = false, actions = null }) {
  const t = useTranslations("panelUpdate");
  const format = useFormatter();

  const { installed, available, update_available: updateAvailable } = state;
  const tone = updateAvailable ? "update" : available.checked ? "current" : "offline";
  const { Icon, chip, tint, band } = TONES[tone];

  const installedLabel = installed.version
    ? t("versionValue", { version: installed.version })
    : t("versionUnknown");

  const publishedLabel = (() => {
    if (!updateAvailable || !available.published_at) return null;
    const date = new Date(available.published_at);
    return Number.isNaN(date.getTime())
      ? null
      : t("published", { date: format.dateTime(date, { dateStyle: "medium" }) });
  })();

  // `branch` is null once updated (a tag checkout is a detached HEAD), so it drops
  // out rather than printing an empty separator.
  const source = [installed.commit_short, installed.branch].filter(Boolean).join(" · ");
  const meta = [publishedLabel, source].filter(Boolean);

  return (
    // Bottom border only when something follows the band.
    <div
      className={cn(
        "flex flex-wrap items-center gap-x-6 gap-y-4 px-6 py-5",
        band,
        divided && "border-b",
      )}
    >
      <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-xl", chip)}>
        <Icon className={cn("size-5", tint)} aria-hidden />
      </span>

      {/* Natural width from sm up so the row's slack goes to the actions. min-w-48 makes
          the column wrap to its own line rather than squeeze to one word per line. */}
      <div className="min-w-48 flex-1 basis-0 space-y-1.5 sm:flex-none sm:basis-auto">
        <h2 className={cn("text-xs font-semibold tracking-wider uppercase", tint)}>
          {tone === "update"
            ? t("statusAvailable")
            : tone === "current"
              ? t("statusCurrent")
              : t("statusOffline")}
        </h2>

        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-lg leading-none tracking-tight">
          <span className={cn(updateAvailable && "text-muted-foreground")}>{installedLabel}</span>
          {updateAvailable && available.version ? (
            <>
              <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
              <span className="font-semibold">
                {t("versionValue", { version: available.version })}
              </span>
            </>
          ) : null}
        </p>

        {tone === "offline" ? (
          <p className="text-sm text-muted-foreground">{t("couldNotCheckBody")}</p>
        ) : null}

        {meta.length ? (
          <p className="text-sm break-words text-muted-foreground">{meta.join(" · ")}</p>
        ) : null}
      </div>

      {/* Spacer for states with no actions, so the band still ends flush. */}
      {actions ?? <span className="flex-1" />}
    </div>
  );
}
