import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { HardDrive, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";

// The steps whose failure means the destination refused or dropped the upload. The
// API does not say why (keys, permissions, a full bucket), so the banner only says it failed.
const UPLOAD_REASONS = ["upload_artifact", "upload_stalled"];

// Unused destinations are ignored so the banner stays meaningful. `lastBackups` are each
// site's newest backup: one that failed at the upload outranks "never tested".
export function DestinationHealth({ destinations, inUse, lastBackups = [] }) {
  const t = useTranslations("backups.destinationHealth");

  const used = destinations.filter((destination) => inUse.includes(destination.id));
  const failed = used.filter((destination) => destination.last_test_success === false);
  // A history row names its destination (names are unique); the overview's
  // `last_backup` does not, so the caller adds the site's `storage_destination_id`.
  const uploadFailures = lastBackups.filter(
    (backup) => backup?.status === "failed" && UPLOAD_REASONS.includes(backup.reason),
  );
  const uploadFailed = used.filter(
    (destination) =>
      !failed.includes(destination) &&
      uploadFailures.some(
        (backup) =>
          backup.storage_destination_id === destination.id || backup.storage_destination_name === destination.name,
      ),
  );
  const untested = used.filter(
    (destination) =>
      (!destination.status || destination.status === "never_tested") && !uploadFailed.includes(destination),
  );

  if (failed.length === 0 && uploadFailed.length === 0 && untested.length === 0) return null;

  const broken = failed.length > 0 || uploadFailed.length > 0;
  const kind = failed.length > 0 ? "failed" : uploadFailed.length > 0 ? "uploadFailed" : "untested";
  const list = kind === "failed" ? failed : kind === "uploadFailed" ? uploadFailed : untested;

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
          {t(`${kind}Title`, { count: list.length })}
        </p>
        {/* Names them, so not every destination has to be checked. */}
        <p className="text-sm text-muted-foreground">
          {t(`${kind}Body`, { names: list.map((d) => d.name).join(", ") })}
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
