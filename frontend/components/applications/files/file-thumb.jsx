import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";
import { fileThumbnailUrl } from "@/lib/api/files";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";

// The server refuses SVG previews (they can carry script).
const NO_THUMBNAIL = /\.svgz?$/i;

// Non-images and SVG use the icon, as does a failed load.
export function FileThumb({ file, appId, className, canPreview = true }) {
  const [failed, setFailed] = useState(false);
  const imgRef = useRef(null);
  const { icon: Icon, className: iconClassName } = fileIconFor(file.name);
  // The preview endpoint needs File Manager manage; a view-only row would 403 per image.
  const thumbnail = canPreview && isImageFile(file.name) && !NO_THUMBNAIL.test(file.name);

  // A load that failed before hydration never reaches `onError`; a completed image with no width failed.
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
