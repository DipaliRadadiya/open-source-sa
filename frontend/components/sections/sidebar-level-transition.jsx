import * as React from "react";
import { cn } from "@/lib/utils";

/**
 * Slides the sidebar's menu when it changes level.
 *
 * The sidebar shows one of two menus: the server's, and — once you open a site
 * — that application's. Until now the swap was instantaneous, so going into an
 * application and coming back both looked like the menu had been replaced by a
 * different page's. Cloudflare's dashboard treats the same move as a drill-down:
 * the new level arrives from the side you are heading towards, which is what
 * makes it read as "deeper in" rather than "somewhere else".
 *
 * **Direction carries the meaning.** Going into an application slides in from
 * the right, coming back slides in from the left. Animating both the same way
 * would be decoration; animating them opposite ways is the thing that tells you
 * which way you went.
 *
 * ## Enter-only, deliberately
 *
 * A true cross-fade would keep the outgoing menu mounted while the incoming one
 * arrives. That needs both trees alive at once, and these menus contain `Link`s
 * whose `active` state is derived from the live pathname — the outgoing copy
 * would re-render mid-flight with the *new* page's item highlighted, which
 * looks like a bug. The incoming menu animating alone reads the same at this
 * duration and cannot desync.
 */
export function SidebarLevelTransition({ level, children, className }) {
  const direction = useLevelDirection(level);

  // ⚠️ The key goes on the INNER element, not on this component.
  //
  // Remounting is what replays a CSS animation — but if the key sat on the same
  // element that holds the direction state, every level change would remount it
  // and reset that state to its initial value. Direction would read "forward"
  // forever and going back would slide the wrong way, which is precisely the
  // thing the direction exists to convey.
  return (
    <div
      key={level}
      data-slot="sidebar-level"
      data-direction={direction}
      className={cn(
        "flex min-h-0 flex-1 flex-col gap-0",
        // Short and shallow on purpose. This fires on every navigation into or
        // out of a site, so anything longer than ~200ms or further than a few
        // pixels stops being a transition and starts being a wait.
        "animate-in fade-in-0 duration-200 ease-out",
        direction === "forward" ? "slide-in-from-right-4" : "slide-in-from-left-4",
        // The sidebar is on screen for the entire session and this runs on every
        // site you open. Motion sensitivity is not a preference here.
        "motion-reduce:animate-none",
        className,
      )}
    >
      {children}
    </div>
  );
}

/**
 * Which way we just went: into an application, or back out.
 *
 * Tracked from the previous level rather than from the route, because the
 * sidebar already decides what "level" means — including the case where an
 * application id is present but its menu came back empty, which falls back to
 * the server panel. Reading the URL here would disagree with the menu actually
 * being shown.
 */
function useLevelDirection(level) {
  const [seen, setSeen] = React.useState({ level, direction: "forward" });

  if (seen.level !== level) {
    setSeen({
      level,
      // Anything that is not the server root is deeper than the server root.
      direction: level === "server" ? "backward" : "forward",
    });
  }

  // During the render that notices the change, `seen` is already updated above,
  // so this is the direction for the level being rendered.
  return seen.level === level ? seen.direction : (level === "server" ? "backward" : "forward");
}
