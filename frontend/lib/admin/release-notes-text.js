/**
 * A release body arrives as GitHub markdown and is rendered as plain text —
 * there is no sanitizer in this project, and a release note is remote content
 * we do not control. Shown verbatim it opens with a literal
 * `**Full Changelog**:`, asterisks and all, which reads as a bug.
 *
 * So: strip the markers that only exist to carry formatting, and leave
 * everything else exactly as written. Deliberately conservative — this is a
 * tidy-up, not a markdown parser, and anything it does not recognise passes
 * through untouched rather than being guessed at.
 */

// Only PAIRED markers. A lone asterisk is far more likely to be a bullet or a
// glob (`rm -rf *`) than an unclosed emphasis.
const BOLD = /(\*\*|__)(?=\S)([\s\S]*?\S)\1/g;
const CODE = /`([^`\n]+)`/g;
// Leading #s are a heading; the text after them is the heading.
const HEADING = /^\s{0,3}#{1,6}[ \t]+/gm;
// A markdown bullet becomes a real one, so the line still reads as a list.
const BULLET = /^(\s*)[-*+][ \t]+/gm;
// [label](https://…) — keep both halves; the URL is often the only useful part.
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
