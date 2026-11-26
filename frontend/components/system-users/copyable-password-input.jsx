import { cn } from "@/lib/utils";
import { Input } from "@/components/ui/input";
import { CopyButton } from "@/components/ui/copy-button";

export function CopyablePasswordInput({ className, value, copyLabel, ...props }) {
  return (
    <div className="relative">
      <Input type="password" className={cn("pr-10", className)} value={value} {...props} />
      <span className="absolute inset-y-0 right-1 flex items-center">
        <CopyButton value={value} label={copyLabel} />
      </span>
    </div>
  );
}
