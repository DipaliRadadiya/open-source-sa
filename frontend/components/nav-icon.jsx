import { DynamicIcon } from "lucide-react/dynamic";
import {
  AppWindow,
  Archive,
  ArrowUpCircle,
  Ban,
  Bot,
  Box,
  Bug,
  CircleHelp,
  Clock,
  Container,
  Copy,
  Cpu,
  Database,
  FileCode,
  FileCode2,
  FileKey,
  FileText,
  FlaskConical,
  Folder,
  GitBranch,
  Globe,
  HardDrive,
  Hexagon,
  History,
  KeyRound,
  LayoutDashboard,
  Lock,
  LogIn,
  PlugZap,
  RefreshCw,
  ScrollText,
  Settings,
  Settings2,
  Shield,
  ShieldCheck,
  Stethoscope,
  Trash2,
  Users,
} from "lucide-react";

// Every name the menus send today, bundled. DynamicIcon fetches each icon as its own
// chunk and shows the fallback "?" until it arrives, so on a slow load or a new build
// the whole sidebar flashed question marks (Krishna, 7 Oct).
const ICONS = {
  "app-window": AppWindow,
  archive: Archive,
  "arrow-up-circle": ArrowUpCircle,
  ban: Ban,
  bot: Bot,
  box: Box,
  bug: Bug,
  clock: Clock,
  container: Container,
  copy: Copy,
  cpu: Cpu,
  database: Database,
  "file-code": FileCode,
  "file-code-2": FileCode2,
  "file-key": FileKey,
  "file-text": FileText,
  "flask-conical": FlaskConical,
  folder: Folder,
  "git-branch": GitBranch,
  globe: Globe,
  "hard-drive": HardDrive,
  hexagon: Hexagon,
  history: History,
  "key-round": KeyRound,
  "layout-dashboard": LayoutDashboard,
  lock: Lock,
  "log-in": LogIn,
  "plug-zap": PlugZap,
  "refresh-cw": RefreshCw,
  "scroll-text": ScrollText,
  settings: Settings,
  "settings-2": Settings2,
  shield: Shield,
  "shield-check": ShieldCheck,
  stethoscope: Stethoscope,
  "trash-2": Trash2,
  users: Users,
};

// The backend sends a kebab-case Lucide icon name (e.g. "layout-dashboard"). A name not
// in the map (a newer backend) still loads on demand; a missing one gets a neutral icon.
export function NavIcon({ name, className = "size-4" }) {
  if (!name) return <CircleHelp className={className} />;
  const Icon = ICONS[name];
  if (Icon) return <Icon className={className} />;
  return (
    <DynamicIcon
      name={name}
      className={className}
      // Blank while it loads, not "?": a placeholder that looks like an icon reads as broken.
      fallback={() => <span className={className} aria-hidden />}
    />
  );
}
