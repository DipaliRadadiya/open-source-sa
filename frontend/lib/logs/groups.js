import { Globe, Database, Code2, Server, ShieldCheck, Cog } from "lucide-react";

/**
 * Per-group identity for the source rail: a shape, not a colour. Colour in the
 * rail is reserved for state (active log, selected log).
 */
export const GROUP_META = {
  web: { icon: Globe },
  database: { icon: Database },
  php: { icon: Code2 },
  system: { icon: Server },
  security: { icon: ShieldCheck },
  daemon: { icon: Cog },
};

export const FALLBACK_GROUP = { icon: Server };
