import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";
import { fileThumbnailUrl } from "@/lib/api/files";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";

// The server refuses to preview SVG at all — it can carry script — so asking
// for one only ever produced a broken-image glyph in the row.
const NO_THUMBNAIL = /\.svgz?$/i;

/**
 * An image file gets a real thumbnail instead of the generic file icon —
 * everything else (dirs, symlinks, non-image files, SVG) still uses the icon.
 * Falls back to the icon on load failure.
 */
export function FileThumb({ file, appId, className }) {
  const [failed, setFailed] = useState(false);
  const imgRef = useRef(null);
  const { icon: Icon, className: iconClassName } = fileIconFor(file.name);
  const thumbnail = isImageFile(file.name) && !NO_THUMBNAIL.test(file.name);

  /*
   * An image that failed BEFORE hydration never reaches `onError`.
   *
   * The row is server-rendered, so the browser starts fetching the <img> as
   * soon as the HTML arrives; if that load fails before React attaches its
   * handler, the error event has already fired and gone. The row then kept a
   * broken-image glyph forever — which is what a folder of SVGs looked like.
   * A decoded image always has a width, so a finished one without is a failure.
   */
  useEffect(() => {
    const img = imgRef.current;
    if (img?.complete && img.naturalWidth === 0) setFailed(true);
  }, []);

  if (!thumbnail || failed) {
    return <Icon className={cn("shrink-0", iconClassName, className)} />;
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element -- authenticated arbitrary-file URL, next/image can't handle it.
    <img
      ref={imgRef}
      src={fileThumbnailUrl(appId, file.path)}
      alt=""
      // Only rows on screen ask. A folder of a few hundred images would
      // otherwise spend the whole preview budget on rows nobody scrolled to.
      loading="lazy"
      decoding="async"
      className={cn("shrink-0 rounded object-cover ring-1 ring-border", className)}
      onError={() => setFailed(true)}
    />
  );
}
