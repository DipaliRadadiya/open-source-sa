import { read } from "@/lib/api/read";
import { activityRetentionSchema } from "@/lib/schemas/activity";

export async function getActivityRetention() {
  const result = await read("/admin/activity-log/retention", activityRetentionSchema);
  return { retention: result.data?.retention ?? null, failed: result.failed };
}
