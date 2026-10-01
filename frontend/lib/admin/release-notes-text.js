// Release notes are untrusted remote content with no sanitizer: never render them as HTML.

// Only paired markers: a lone asterisk is usually a bullet or a glob.
const BOLD = /(\*\*|__)(?=\S)([\s\S]*?\S)\1/g;
const CODE = /`([^`\n]+)`/g;
// Leading #s are a heading; the text after them is the heading.
const HEADING = /^\s{0,3}#{1,6}[ \t]+/gm;
// A markdown bullet becomes a real one, so the line still reads as a list.
const BULLET = /^(\s*)[-*+][ \t]+/gm;
// [label](https://…): keep both halves.
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
