import { DynamicIcon } from "lucide-react/dynamic";
import { CircleHelp } from "lucide-react";

// The backend sends a kebab-case Lucide icon name (e.g. "layout-dashboard");
// missing names fall back to a neutral icon.
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
