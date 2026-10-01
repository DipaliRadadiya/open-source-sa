import * as React from "react";
import { cn } from "@/lib/utils";

// Enter-only on purpose: a cross-fade keeps the old menu mounted, and its `Link`s would
// re-render mid-flight with the new page highlighted.
export function SidebarLevelTransition({ level, children, className }) {
  const direction = useLevelDirection(level);

  // Key the INNER element: remounting this one would reset the direction state and
  // going back would slide the wrong way.
  return (
    <div
      key={level}
      data-slot="sidebar-level"
      data-direction={direction}
      className={cn(
        "flex min-h-0 flex-1 flex-col gap-0",
        // Short and shallow: this runs on every navigation into or out of a site, and
        // anything longer feels like a wait.
        "animate-in fade-in-0 duration-200 ease-out",
        direction === "forward" ? "slide-in-from-right-4" : "slide-in-from-left-4",
        // Always on screen and frequent, so reduced motion is honoured.
        "motion-reduce:animate-none",
        className,
      )}
    >
      {children}
    </div>
  );
}

// Tracked from the previous level, not the route: the sidebar decides what "level" means.
function useLevelDirection(level) {
  const [seen, setSeen] = React.useState({ level, direction: "forward" });

  if (seen.level !== level) {
    setSeen({
      level,
      // Anything other than the server root is deeper than it.
      direction: level === "server" ? "backward" : "forward",
    });
  }

  // During the render that notices the change, `seen` is already updated above,
  // so this is the direction for the level being rendered.
  return seen.level === level ? seen.direction : (level === "server" ? "backward" : "forward");
}
