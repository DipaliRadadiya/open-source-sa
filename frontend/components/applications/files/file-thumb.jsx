"use client";

import { useState } from "react";
import { cn } from "@/lib/utils";
import { fileDownloadUrl } from "@/lib/api/files";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";

/**
 * An image file gets a real thumbnail instead of the generic file icon —
 * everything else (dirs, symlinks, non-image files) still uses the icon.
 * Falls back to the icon on load failure.
 */
export function FileThumb({ file, appId, className }) {
  const [failed, setFailed] = useState(false);
  const { icon: Icon, className: iconClassName } = fileIconFor(file.name);

  if (!isImageFile(file.name) || failed) {
    return <Icon className={cn("shrink-0", iconClassName, className)} />;
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element -- authenticated arbitrary-file URL, next/image can't handle it.
    <img
      src={fileDownloadUrl(appId, file.path)}
      alt=""
      className={cn("shrink-0 rounded object-cover ring-1 ring-border", className)}
      onError={() => setFailed(true)}
    />
  );
}
