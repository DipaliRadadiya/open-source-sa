"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { HardDrive, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * When the place the backups go is broken, say so here.
 *
 * This is the failure mode the whole feature is most exposed to and the one
 * the screens said nothing about: a key gets rotated or a bucket gets deleted,
 * every nightly run fails from that moment, and the first anyone hears of it
 * is a column of red rows days later — or the day they need a restore.
 *
 * Only destinations that something actually depends on are reported. A broken
 * destination nobody has pointed a schedule at is not urgent, and warning
 * about it here would train people to ignore this banner.
 *
 * Never-tested counts. It is not a failure, but it is the state most likely to
 * become one at 2am, and treating "untested" as "fine" is how it gets there.
 */
export function DestinationHealth({ destinations, inUse }) {
  const t = useTranslations("backups.destinationHealth");

  const used = destinations.filter((destination) => inUse.includes(destination.id));
  const failed = used.filter((destination) => destination.last_test_success === false);
  const untested = used.filter(
    (destination) => !destination.status || destination.status === "never_tested",
  );

  if (failed.length === 0 && untested.length === 0) return null;

  const broken = failed.length > 0;
  const list = broken ? failed : untested;

  return (
    <div
      // Stacks below `sm`. As a wrapping row the text column carried
      // `flex-1 min-w-0`, so it shrank to nothing rather than wrapping the
      // BUTTON — one word per line beside a button that never moved.
      className={`flex flex-col items-start gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:gap-4 ${
        broken ? "border-destructive/30 bg-destructive/5" : "border-warning/30 bg-warning/5"
      }`}
    >
      <span
        className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${
          broken ? "bg-destructive/10 text-destructive" : "bg-warning/15 text-warning"
        }`}
      >
        <TriangleAlert className="size-5" />
      </span>

      <div className="min-w-0 flex-1">
        <p className="font-semibold tracking-tight">
          {broken ? t("failedTitle", { count: failed.length }) : t("untestedTitle", { count: untested.length })}
        </p>
        {/* Naming them matters: with several destinations configured, "one is
            failing" sends someone to check all of them. */}
        <p className="text-sm text-muted-foreground">
          {broken
            ? t("failedBody", { names: list.map((d) => d.name).join(", ") })
            : t("untestedBody", { names: list.map((d) => d.name).join(", ") })}
        </p>
      </div>

      <Button asChild variant={broken ? "destructive" : "outline"} className="w-full sm:w-auto">
        <Link href="/integrations/storage">
          <HardDrive className="size-4" />
          {t("action")}
        </Link>
      </Button>
    </div>
  );
}
