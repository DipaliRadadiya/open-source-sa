import { useTranslations } from "next-intl";
import { ExternalLink } from "lucide-react";
import { releaseNotesText } from "@/lib/admin/release-notes-text";

// Plain text, not markdown: the release body is remote and there is no sanitizer.
export function ReleaseNotes({ notes: raw, url }) {
  const t = useTranslations("panelUpdate");
  // Markdown markers stripped, since the body is rendered as plain text.
  const notes = releaseNotesText(raw);
  if (!notes && !url) return null;

  return (
    <div className="flex flex-wrap items-start gap-x-6 gap-y-2 border-t bg-muted/30 px-6 py-4">
      {notes ? (
        // Label beside the text from sm up; stacked on a phone so the URL has room.
        <div className="flex min-w-48 flex-1 flex-col gap-1 sm:flex-row sm:flex-wrap sm:items-baseline sm:gap-x-3">
          <p className="shrink-0 text-xs font-semibold text-muted-foreground">
            {t("whatsNew")}
          </p>
          {/* Capped so a long changelog cannot push the actions off screen. */}
          <p className="max-h-40 min-w-0 flex-1 overflow-y-auto text-sm leading-6 break-words whitespace-pre-wrap">
            {notes}
          </p>
        </div>
      ) : null}
      {url ? (
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className={
            "inline-flex shrink-0 items-center gap-1 rounded-sm text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none" +
            (notes ? "" : " ml-0")
          }
        >
          {t("releaseNotes")}
          <ExternalLink className="size-3.5" aria-hidden />
        </a>
      ) : null}
    </div>
  );
}
