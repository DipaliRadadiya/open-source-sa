import { Database, FileCode2, Hexagon, MemoryStick, ShieldCheck, Package, SquareTerminal } from "lucide-react";

// Icon per known component. No colour here: colour is reserved for state.
// Unknown components fall back to a package icon so the list stays API-driven.
const ICONS = {
  database: Database,
  php: FileCode2,
  node: Hexagon,
  redis: MemoryStick,
  fail2ban: ShieldCheck,
  wp_cli: SquareTerminal,
};

const CHIP = "bg-muted";
const TINT = "text-muted-foreground";

export function componentMeta(key) {
  return { Icon: ICONS[key] ?? Package, chip: CHIP, tint: TINT };
}
