import { listFiles } from "@/lib/api/files";

/**
 * Whether a 404 from move / copy / extract / compress is about the destination
 * folder (the API's message is the same generic "not found"). Lists the folder
 * only after a 404. Resolves true only when it is confirmed missing.
 */
export async function destinationMissing(appId, error, folder) {
  if (error?.response?.status !== 404 || !folder) return false;
  try {
    await listFiles(appId, folder);
    return false;
  } catch (listError) {
    return listError?.response?.status === 404;
  }
}
