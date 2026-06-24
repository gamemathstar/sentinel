import Link from "next/link";
import { cn } from "@/lib/cn";

type Variant = "aurora" | "glass" | "ghost";
type Size = "sm" | "md" | "lg";

const sizes: Record<Size, string> = {
  sm: "h-9 px-4 text-sm",
  md: "h-11 px-5 text-sm",
  lg: "h-12 px-7 text-base",
};

function classesFor(variant: Variant, size: Size, className?: string) {
  return cn(
    "inline-flex items-center justify-center gap-2 rounded-xl font-medium tracking-tight",
    "transition disabled:opacity-50 disabled:pointer-events-none select-none",
    sizes[size],
    variant === "aurora" && "btn-aurora text-white",
    variant === "glass" && "glass glass-hover text-ink",
    variant === "ghost" && "text-muted hover:text-ink hover:bg-white/5",
    className,
  );
}

export function Button({
  variant = "aurora",
  size = "md",
  className,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size }) {
  return <button className={classesFor(variant, size, className)} {...props} />;
}

export function ButtonLink({
  variant = "aurora",
  size = "md",
  className,
  ...props
}: React.ComponentProps<typeof Link> & { variant?: Variant; size?: Size }) {
  return <Link className={classesFor(variant, size, className)} {...props} />;
}
