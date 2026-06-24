import { cn } from "@/lib/cn";

/** A frosted-glass surface. `glow` adds the gradient hairline; `hover` lifts on hover. */
export function GlassCard({
  className,
  glow = false,
  hover = false,
  ...props
}: React.HTMLAttributes<HTMLDivElement> & { glow?: boolean; hover?: boolean }) {
  return (
    <div
      className={cn(
        "glass rounded-2xl",
        glow && "ring-gradient",
        hover && "glass-hover",
        className,
      )}
      {...props}
    />
  );
}
