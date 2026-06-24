"use client";

import { cn } from "@/lib/cn";

export type SegOption<T extends string> = { value: T; label: string };

/** A glossy segmented control with a sliding active pill. */
export function Segmented<T extends string>({
  options,
  value,
  onChange,
  className,
}: {
  options: SegOption<T>[];
  value: T;
  onChange: (v: T) => void;
  className?: string;
}) {
  return (
    <div className={cn("glass inline-flex rounded-xl p-1", className)}>
      {options.map((o) => {
        const active = o.value === value;
        return (
          <button
            key={o.value}
            type="button"
            onClick={() => onChange(o.value)}
            className={cn(
              "relative rounded-lg px-3.5 py-1.5 text-sm font-medium transition",
              active ? "text-white" : "text-muted hover:text-ink",
            )}
          >
            {active && (
              <span className="absolute inset-0 rounded-lg bg-gradient-to-br from-violet-500 to-sky-500 shadow-[0_8px_24px_-12px] shadow-violet-500/70" />
            )}
            <span className="relative">{o.label}</span>
          </button>
        );
      })}
    </div>
  );
}
