import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { HardDrive, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";

// Unused destinations are ignored so the banner stays meaningful.
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
      // Stacks below `sm`; a wrapping row squeezed the `flex-1 min-w-0` text to
      // one word per line instead of wrapping the button.
      data-slot="notice"
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
        {/* Names them, so not every destination has to be checked. */}
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
