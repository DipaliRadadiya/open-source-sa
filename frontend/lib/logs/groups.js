import { Globe, Database, Code2, Server, ShieldCheck, Cog } from "lucide-react";

/**
 * Per-group identity for the source rail — a shape, not a colour. The rail's
 * only colour is state: green for a log being written to right now, primary for
 * the one you're reading. Tinting the categories as well made those two compete
 * with decoration.
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
