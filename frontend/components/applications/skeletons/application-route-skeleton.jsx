"use client";

import { usePathname } from "next/navigation";
import { ApplicationDashboardSkeleton } from "@/components/applications/skeletons/application-dashboard-skeleton";
import BackupsSkeleton from "@/app/(app)/applications/[application]/backups/loading";
import BotBlockerSkeleton from "@/app/(app)/applications/[application]/bot-blocker/loading";
import CloneSkeleton from "@/app/(app)/applications/[application]/clone/loading";
import DeploymentSkeleton from "@/app/(app)/applications/[application]/deployment/loading";
import DomainsSkeleton from "@/app/(app)/applications/[application]/domains/loading";
import EnvironmentSkeleton from "@/app/(app)/applications/[application]/environment/loading";
import Fail2banSkeleton from "@/app/(app)/applications/[application]/fail2ban/loading";
import FilesSkeleton from "@/app/(app)/applications/[application]/files/loading";
import FirewallSkeleton from "@/app/(app)/applications/[application]/firewall/loading";
import LogsSkeleton from "@/app/(app)/applications/[application]/logs/loading";
import PhpSkeleton from "@/app/(app)/applications/[application]/php/loading";
import SecuritySkeleton from "@/app/(app)/applications/[application]/security/loading";
import StagingSkeleton from "@/app/(app)/applications/[application]/staging/loading";
import WorkersSkeleton from "@/app/(app)/applications/[application]/workers/loading";

const BY_SECTION = {
  "backups": BackupsSkeleton,
  "bot-blocker": BotBlockerSkeleton,
  "clone": CloneSkeleton,
  "deployment": DeploymentSkeleton,
  "domains": DomainsSkeleton,
  "environment": EnvironmentSkeleton,
  "fail2ban": Fail2banSkeleton,
  "files": FilesSkeleton,
  "firewall": FirewallSkeleton,
  "logs": LogsSkeleton,
  "php": PhpSkeleton,
  "security": SecuritySkeleton,
  "staging": StagingSkeleton,
  "workers": WorkersSkeleton,
};

/**
 * The skeleton for the application page in the URL, whichever boundary is
 * showing it. Next shows the NEAREST loading file above whatever is still
 * rendering, and for an application page that is often not the page's own:
 * the list's while the application layout loads, the Dashboard's while a
 * prefetched link waits for the page. Each drew its own shape first and was
 * then replaced by the right one.
 */
export function ApplicationRouteSkeleton({ fallback = null }) {
  const pathname = usePathname() ?? "";
  const match = pathname.match(/^\/applications\/(\d+)(?:\/([^/]+))?/);
  if (!match) return fallback;
  if (!match[2]) return <ApplicationDashboardSkeleton />;
  const Section = BY_SECTION[match[2]];
  return Section ? <Section /> : fallback;
}
