import { ApplicationRouteSkeleton } from "@/components/applications/skeletons/application-route-skeleton";
import { ApplicationDashboardSkeleton } from "@/components/applications/skeletons/application-dashboard-skeleton";

// Also wraps every section below it, so it draws the section in the URL.
export default function Loading() {
  return <ApplicationRouteSkeleton fallback={<ApplicationDashboardSkeleton />} />;
}
