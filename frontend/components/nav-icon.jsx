"use client";

import { DynamicIcon } from "lucide-react/dynamic";
import { CircleHelp } from "lucide-react";

// Backend sends the exact kebab-case Lucide icon name (e.g. "layout-dashboard").
// DynamicIcon renders any Lucide icon by name, so no hand-maintained map is
// needed and new backend icons work with no code change. Unknown/missing
// names fall back to a neutral icon.
export function NavIcon({ name, className = "size-4" }) {
  if (!name) return <CircleHelp className={className} />;
  return (
    <DynamicIcon
      name={name}
      className={className}
      fallback={() => <CircleHelp className={className} />}
    />
  );
}
