import { read } from "@/lib/api/read";
import { activityRetentionSchema } from "@/lib/schemas/activity";

// Admin only, like the log it trims. A failure is reported, never shown as "keep everything".
export async function getActivityRetention() {
  const result = await read("/admin/activity-log/retention", activityRetentionSchema);
  return { retention: result.data?.retention ?? null, failed: result.failed };
}
