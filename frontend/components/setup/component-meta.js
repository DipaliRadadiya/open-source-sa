import { Container, Database, FileCode2, Hexagon, MemoryStick, ShieldCheck, Package, SquareTerminal } from "lucide-react";

const ICONS = {
  database: Database,
  php: FileCode2,
  node: Hexagon,
  redis: MemoryStick,
  fail2ban: ShieldCheck,
  wp_cli: SquareTerminal,
  docker: Container,
};

const CHIP = "bg-muted";
const TINT = "text-muted-foreground";

export function componentMeta(key) {
  return { Icon: ICONS[key] ?? Package, chip: CHIP, tint: TINT };
}
