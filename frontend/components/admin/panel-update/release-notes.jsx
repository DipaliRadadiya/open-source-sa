import { useTranslations } from "next-intl";
import { ExternalLink } from "lucide-react";
import { releaseNotesText } from "@/lib/admin/release-notes-text";

/**
 * What changed, in the card's closing band.
 *
 * Not behind a fold: the body is usually one line of GitHub's markdown
 * (`**Full Changelog**: <url>`), and hiding one line behind a click buys
 * nothing while leaving the band blank.
 *
 * Rendered as text, not markdown: there is no sanitizer in this project and a
 * release body is remote content. `break-words` matters more than it looks — a
 * changelog URL is one unbreakable token, and without it 35px of the link sat
 * behind a horizontal scrollbar on a phone.
 */
export function ReleaseNotes({ notes: raw, url }) {
  const t = useTranslations("panelUpdate");
  // Markdown markers stripped, everything else left as written — the body is
  // shown as text, so `**Full Changelog**` read as a bug rather than as bold.
  const notes = releaseNotesText(raw);
  if (!notes && !url) return null;

  return (
    <div className="flex flex-wrap items-start gap-x-6 gap-y-2 border-t bg-muted/30 px-6 py-4">
      {notes ? (
        // Label beside the text, not above it: a one-line changelog under a
        // heading left the whole width of the label's row unused.
        // Stacked on a phone: side by side, the label ate 90px of a 280px row
        // and left the URL breaking every three characters.
        <div className="flex min-w-48 flex-1 flex-col gap-1 sm:flex-row sm:flex-wrap sm:items-baseline sm:gap-x-3">
          <p className="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {t("whatsNew")}
          </p>
          {/* Capped rather than unbounded: a release with a real changelog must
              not push the actions off the top of the screen. */}
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
