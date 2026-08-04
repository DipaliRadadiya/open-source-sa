import { Database, FileCode2, Hexagon, MemoryStick, ShieldCheck, Package } from "lucide-react";

// Identity per known component: the ICON tells you what it is. Colour is
// deliberately NOT used here — it's reserved for state (the status pill / card
// tint), so the page stays calm and colour always means something. Every chip
// shares one neutral tone; anything new falls back to a package icon so the
// list stays API-driven.
const ICONS = {
  database: Database,
  php: FileCode2,
  node: Hexagon,
  redis: MemoryStick,
  fail2ban: ShieldCheck,
};

const CHIP = "bg-muted";
const TINT = "text-muted-foreground";

export function componentMeta(key) {
  return { Icon: ICONS[key] ?? Package, chip: CHIP, tint: TINT };
}
