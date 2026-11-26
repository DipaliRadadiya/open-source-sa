import { useEffect, useState } from "react";
import { inspectDockerImage } from "@/lib/api/docker";
import { imageInspectResponseSchema } from "@/lib/schemas/docker";

export function useImageInspection(image, registryId) {
  const [result, setResult] = useState(null);
  useEffect(() => {
    if (!image) return undefined;
    const controller = new AbortController();
    inspectDockerImage(image, { registryId, signal: controller.signal })
      .then(({ data }) => {
        const parsed = imageInspectResponseSchema.safeParse(data);
        setResult(parsed.success ? parsed.data : null);
      })
      .catch(() => {});
    return () => controller.abort();
  }, [image, registryId]);
  return result;
}
