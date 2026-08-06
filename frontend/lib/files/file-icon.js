import { Image, FileArchive, FileJson, FileCode2, FileText, FileCog, File } from "lucide-react";

// Groups by extension, not by guessing MIME from bytes (this list only ever
// sees a filename) — good enough for a browse view, and it's exactly what
// every panel this feature was benchmarked against does for the same reason.
const IMAGE_EXTENSIONS = ["jpg", "jpeg", "png", "gif", "svg", "webp", "ico", "bmp", "avif"];

const GROUPS = [
  { icon: Image, className: "text-violet-500", exts: IMAGE_EXTENSIONS },
  { icon: FileArchive, className: "text-amber-500", exts: ["zip", "tar", "gz", "tgz", "rar", "7z"] },
  { icon: FileJson, className: "text-emerald-600", exts: ["json", "yml", "yaml", "toml", "xml"] },
  {
    icon: FileCode2,
    className: "text-blue-500",
    exts: [
      "js", "jsx", "ts", "tsx", "php", "py", "rb", "go", "java", "c", "cpp", "h",
      "html", "css", "scss", "sh", "sql", "vue",
    ],
  },
  { icon: FileCog, className: "text-slate-500", exts: ["env", "ini", "conf", "htaccess", "gitignore", "lock"] },
  { icon: FileText, className: "text-muted-foreground", exts: ["md", "txt", "pdf", "doc", "docx", "log"] },
];

const EXT_TO_GROUP = new Map(GROUPS.flatMap((g) => g.exts.map((ext) => [ext, g])));

// "composer.lock" -> "lock", ".env" -> "env", "README" -> "".
function extensionOf(name) {
  const base = name.replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

export function fileIconFor(name) {
  const group = EXT_TO_GROUP.get(extensionOf(name));
  return group ?? { icon: File, className: "text-muted-foreground" };
}

// A browser-native <img> renders these safely (SVG included — loaded as an
// image resource, not inlined, so it can't execute a script) without routing
// through the text editor's "looks binary" rejection.
export function isImageFile(name) {
  return IMAGE_EXTENSIONS.includes(extensionOf(name));
}
