import { ApplicationRouteSkeleton } from "@/components/applications/skeletons/application-route-skeleton";
import { ApplicationDashboardSkeleton } from "@/components/applications/skeletons/application-dashboard-skeleton";

export default function Loading() {
  return <ApplicationRouteSkeleton fallback={<ApplicationDashboardSkeleton />} />;
}
