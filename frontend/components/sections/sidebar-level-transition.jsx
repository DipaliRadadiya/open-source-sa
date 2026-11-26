import * as React from "react";
import { cn } from "@/lib/utils";

export function SidebarLevelTransition({ level, children, className }) {
  const direction = useLevelDirection(level);

  return (
    <div
      key={level}
      data-slot="sidebar-level"
      data-direction={direction}
      className={cn(
        "flex min-h-0 flex-1 flex-col gap-0",
        "animate-in fade-in-0 duration-200 ease-out",
        direction === "forward" ? "slide-in-from-right-4" : "slide-in-from-left-4",
        "motion-reduce:animate-none",
        className,
      )}
    >
      {children}
    </div>
  );
}

function useLevelDirection(level) {
  const [seen, setSeen] = React.useState({ level, direction: "forward" });

  if (seen.level !== level) {
    setSeen({
      level,
      direction: level === "server" ? "backward" : "forward",
    });
  }

  return seen.level === level ? seen.direction : (level === "server" ? "backward" : "forward");
}
