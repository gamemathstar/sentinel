"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import { ArrowLeft, LayoutGrid, CalendarClock, Radio, ShieldAlert } from "lucide-react";
import { Badge } from "@/components/ui/Badge";
import { listAssessments, type AssessmentRow } from "@/lib/api";
import { demoAssessments } from "@/lib/demo";
import { cn } from "@/lib/cn";

const TABS = [
  { seg: "", label: "Overview", icon: LayoutGrid },
  { seg: "scheduling", label: "Scheduling", icon: CalendarClock },
  { seg: "live", label: "Live monitor", icon: Radio },
  { seg: "proctoring", label: "Proctoring", icon: ShieldAlert },
];

export default function AssessmentLayout({ children }: { children: React.ReactNode }) {
  const { id } = useParams<{ id: string }>();
  const pathname = usePathname();
  const base = `/assessments/${id}`;
  const [meta, setMeta] = useState<AssessmentRow | null>(
    () => (demoAssessments.find((a) => a.id === id) as AssessmentRow | undefined) ?? null,
  );

  useEffect(() => {
    listAssessments().then((r) => { const m = r.data.find((a) => a.id === id); if (m) setMeta(m); }).catch(() => {});
  }, [id]);

  const active = (seg: string) => seg === "" ? pathname === base : pathname.startsWith(`${base}/${seg}`);

  return (
    <div className="space-y-6">
      <div>
        <Link href="/assessments" className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink"><ArrowLeft className="h-4 w-4" /> Assessments</Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-3xl font-semibold tracking-tight">{meta?.title ?? "Assessment"}</h1>
          {meta && <Badge tone={meta.status === "live" ? "emerald" : meta.status === "published" ? "sky" : "neutral"} className="capitalize">{meta.status}</Badge>}
        </div>
      </div>

      <div className="flex gap-1 overflow-x-auto rounded-xl glass p-1 text-sm">
        {TABS.map(({ seg, label, icon: Icon }) => (
          <Link
            key={seg}
            href={seg ? `${base}/${seg}` : base}
            className={cn("flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 transition", active(seg) ? "bg-violet-500/15 text-violet-700 dark:text-violet-200" : "text-muted hover:text-ink")}
          >
            <Icon className="h-4 w-4" /> {label}
          </Link>
        ))}
      </div>

      {children}
    </div>
  );
}
