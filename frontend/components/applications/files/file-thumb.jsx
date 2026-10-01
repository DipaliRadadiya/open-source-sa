import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";
import { fileThumbnailUrl } from "@/lib/api/files";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";

// The server refuses SVG previews (they can carry script).
const NO_THUMBNAIL = /\.svgz?$/i;

/**
 * An image file gets a real thumbnail; everything else (dirs, symlinks,
 * non-images, SVG) uses the icon. Falls back to the icon on load failure.
 */
export function FileThumb({ file, appId, className, canPreview = true }) {
  const [failed, setFailed] = useState(false);
  const imgRef = useRef(null);
  const { icon: Icon, className: iconClassName } = fileIconFor(file.name);
  // `canPreview` false: the preview endpoint needs File Manager manage, and a
  // view-only row would request a 403 per image.
  const thumbnail = canPreview && isImageFile(file.name) && !NO_THUMBNAIL.test(file.name);

  /*
   * An image that failed BEFORE hydration never reaches `onError` (the row is
   * server-rendered, so the load can fail before React attaches the handler). A
   * decoded image always has a width, so a completed one without is a failure.
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
      // Lazy, so large image folders do not spend the preview budget off screen.
      loading="lazy"
      decoding="async"
      className={cn("shrink-0 rounded object-cover ring-1 ring-border", className)}
      onError={() => setFailed(true)}
    />
  );
}
