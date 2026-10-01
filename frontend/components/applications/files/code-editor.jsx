import { useMemo } from "react";
import CodeMirror, { EditorView } from "@uiw/react-codemirror";
import { HighlightStyle, syntaxHighlighting } from "@codemirror/language";
import { tags } from "@lezer/highlight";
import { codeLanguageFor } from "@/lib/files/code-lang";

// Built from the app's --console-* tokens (app/globals.css) rather than a canned
// CodeMirror theme, matching the other console surfaces.
const consoleTheme = EditorView.theme(
  {
    "&": {
      color: "var(--console-foreground)",
      backgroundColor: "var(--console)",
      height: "100%",
      fontSize: "12px",
    },
    ".cm-content": { caretColor: "var(--console-foreground)", padding: "0.75rem 0" },
    ".cm-cursor, .cm-dropCursor": { borderLeftColor: "var(--console-foreground)" },
    "&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection": {
      backgroundColor: "color-mix(in oklch, var(--console-foreground) 20%, transparent)",
    },
    ".cm-activeLine": {
      backgroundColor: "color-mix(in oklch, var(--console-foreground) 6%, transparent)",
    },
    ".cm-gutters": {
      backgroundColor: "var(--console)",
      color: "var(--console-muted)",
      border: "none",
      borderRight: "1px solid var(--console-border)",
    },
    ".cm-activeLineGutter": {
      backgroundColor: "color-mix(in oklch, var(--console-foreground) 6%, transparent)",
      color: "var(--console-foreground)",
    },
    ".cm-foldPlaceholder": {
      backgroundColor: "transparent",
      border: "none",
      color: "var(--console-muted)",
    },
    ".cm-matchingBracket, .cm-nonmatchingBracket": {
      backgroundColor: "color-mix(in oklch, var(--console-foreground) 15%, transparent)",
    },
    "&.cm-editor.cm-focused": { outline: "none" },
    // The scroller, not the editor root, overflows. Mirrors .console-scroll in
    // globals.css, which cannot reach CodeMirror's internal scroll element.
    ".cm-scroller": { scrollbarWidth: "thin", scrollbarColor: "var(--console-border) transparent" },
    ".cm-scroller::-webkit-scrollbar": { width: "10px", height: "10px" },
    ".cm-scroller::-webkit-scrollbar-track": { background: "transparent" },
    ".cm-scroller::-webkit-scrollbar-thumb": {
      backgroundColor: "var(--console-border)",
      border: "3px solid transparent",
      backgroundClip: "content-box",
      borderRadius: "9999px",
    },
  },
  { dark: true },
);

const consoleHighlight = HighlightStyle.define([
  { tag: [tags.keyword, tags.operatorKeyword, tags.controlKeyword], color: "oklch(0.72 0.15 255)" },
  { tag: [tags.string, tags.special(tags.string)], color: "var(--console-success)" },
  { tag: [tags.comment, tags.lineComment, tags.blockComment], color: "var(--console-muted)", fontStyle: "italic" },
  { tag: [tags.number, tags.bool, tags.null], color: "var(--console-warning)" },
  { tag: [tags.function(tags.variableName), tags.definition(tags.function(tags.variableName))], color: "oklch(0.75 0.14 300)" },
  { tag: [tags.typeName, tags.className, tags.tagName], color: "oklch(0.72 0.15 255)" },
  { tag: [tags.attributeName, tags.propertyName], color: "oklch(0.75 0.14 300)" },
  { tag: tags.invalid, color: "var(--console-error)" },
]);

/**
 * The Files editor's editing surface: CodeMirror with line numbers, bracket
 * matching, folding and highlighting, themed to the console tokens. Language
 * comes from the file extension (`codeLanguageFor`); unknown ones fall back to
 * plain text.
 */
export function CodeEditor({ filename, value, onChange, readOnly = false, className }) {
  const language = useMemo(() => codeLanguageFor(filename), [filename]);
  const extensions = useMemo(
    () => [consoleTheme, syntaxHighlighting(consoleHighlight), ...(language ? [language] : [])],
    [language],
  );

  return (
    <CodeMirror
      value={value}
      onChange={onChange}
      extensions={extensions}
      readOnly={readOnly}
      editable={!readOnly}
      theme="none"
      basicSetup={{ highlightActiveLine: !readOnly, foldGutter: true }}
      className={className}
    />
  );
}
