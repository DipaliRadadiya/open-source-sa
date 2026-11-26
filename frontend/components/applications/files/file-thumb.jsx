import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";
import { fileThumbnailUrl } from "@/lib/api/files";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";

const NO_THUMBNAIL = /\.svgz?$/i;

export function FileThumb({ file, appId, className, canPreview = true }) {
  const [failed, setFailed] = useState(false);
  const imgRef = useRef(null);
  const { icon: Icon, className: iconClassName } = fileIconFor(file.name);
  const thumbnail = canPreview && isImageFile(file.name) && !NO_THUMBNAIL.test(file.name);

  // An image that failed before hydration never fires onError.
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
      loading="lazy"
      decoding="async"
      className={cn("shrink-0 rounded object-cover ring-1 ring-border", className)}
      onError={() => setFailed(true)}
    />
  );
}
