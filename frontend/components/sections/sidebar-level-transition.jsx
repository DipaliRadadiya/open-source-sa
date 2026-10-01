import * as React from "react";
import { cn } from "@/lib/utils";

/**
 * Slides the sidebar's menu when it changes level (server menu ↔ application
 * menu), as a drill-down.
 *
 * Direction carries the meaning: into an application slides in from the right,
 * back out from the left.
 *
 * Enter-only, deliberately: a cross-fade would keep the outgoing menu mounted,
 * and its `Link`s derive `active` from the live pathname, so it would re-render
 * mid-flight with the new page highlighted.
 */
export function SidebarLevelTransition({ level, children, className }) {
  const direction = useLevelDirection(level);

  // The key goes on the INNER element, not on this component: remounting replays
  // the CSS animation, but remounting the element that holds the direction state
  // would reset it, and going back would slide the wrong way.
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

/**
 * Which way navigation just went: into an application, or back out. Tracked from the
 * previous level, not the route, because the sidebar decides what "level" means
 * (including falling back to the server panel for an empty application menu).
 */
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
