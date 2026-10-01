import { listFiles } from "@/lib/api/files";

// The API's 404 message is the same generic "not found", so list the folder to confirm.
export async function destinationMissing(appId, error, folder) {
  if (error?.response?.status !== 404 || !folder) return false;
  try {
    await listFiles(appId, folder);
    return false;
  } catch (listError) {
    return listError?.response?.status === 404;
  }
}
