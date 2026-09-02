import { useTranslations, useFormatter } from "next-intl";
import { ArrowRight, ArrowUpCircle, CircleCheck, WifiOff } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * The band every resting state of this page opens with, so the three of them
 * are the same object in three moods rather than three different boxes.
 *
 * Shape is fixed: status chip, a one-word status, the version, then the meta
 * nobody reads until something is wrong — and the actions on the same line,
 * where they close the row off instead of leaving the right half blank.
 *
 * The version is 18px, not a display size. It is the fact you came for, but the
 * page heading above it should still be the largest thing on screen.
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

  // Where the panel came from. `branch` is null once updated (a tag checkout is
  // a detached HEAD), so it drops out rather than printing an empty separator.
  const source = [installed.commit_short, installed.branch].filter(Boolean).join(" · ");
  const meta = [publishedLabel, source].filter(Boolean);

  return (
    // Only ruled off when something follows it. Up to date, the band IS the
    // card, and a bottom border there is a line drawn under nothing.
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

      {/* Natural width, not flex-1: the slack in this row belongs to whatever
          the actions bring with them — a reason that has to wrap onto two lines
          while 500px sits empty beside it is the space being wasted, not used.
          min-w so the column drops to its own line rather than being squeezed
          to one word per line. */}
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

      {/* Spacer for the states that carry no actions, so the band still ends
          flush instead of the version column stretching to fill it. */}
      {actions ?? <span className="flex-1" />}
    </div>
  );
}
