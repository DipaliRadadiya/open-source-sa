import { Globe, Database, Code2, Server, ShieldCheck, Cog } from "lucide-react";

// A shape per group, not a colour: colour in the rail is reserved for state.
export const GROUP_META = {
  web: { icon: Globe },
  database: { icon: Database },
  php: { icon: Code2 },
  system: { icon: Server },
  security: { icon: ShieldCheck },
  daemon: { icon: Cog },
};

export const FALLBACK_GROUP = { icon: Server };
