// Release notes are untrusted remote content: never render them as HTML.

const BOLD = /(\*\*|__)(?=\S)([\s\S]*?\S)\1/g;
const CODE = /`([^`\n]+)`/g;
const HEADING = /^\s{0,3}#{1,6}[ \t]+/gm;
const BULLET = /^(\s*)[-*+][ \t]+/gm;
const LINK = /\[([^\]\n]*)\]\((\s*<?([^)\s]+)>?[^)]*)\)/g;
const BLANK_RUN = /\n{3,}/g;

export function releaseNotesText(notes) {
  if (typeof notes !== "string") return "";
  return notes
    .replace(CODE, "$1")
    .replace(BOLD, "$2")
    .replace(HEADING, "")
    .replace(BULLET, "$1• ")
    .replace(LINK, (match, label, _rest, url) => (label ? `${label} (${url})` : url))
    .replace(BLANK_RUN, "\n\n")
    .trim();
}
