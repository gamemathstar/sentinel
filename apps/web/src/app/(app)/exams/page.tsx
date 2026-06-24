"use client";

import Link from "next/link";
import { Radio, ArrowUpRight } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { useApiList } from "@/lib/useApiList";
import { listAssessments, type AssessmentRow } from "@/lib/api";
import { demoAssessments } from "@/lib/demo";

const tone: Record<string, "emerald" | "sky" | "neutral" | "amber"> = {
  live: "emerald", published: "sky", closed: "neutral", draft: "amber",
};

export default function ExamsPage() {
  const { data, live } = useApiList<AssessmentRow>(
    () => listAssessments().then((r) => r.data),
    demoAssessments.map((a) => ({ id: a.id, title: a.title, kind: a.kind, status: a.status })),
  );
  const monitorable = data.filter((a) => a.status === "live" || a.status === "published" || a.status === "closed");

  return (
    <div className="space-y-7">
      <div>
        <h1 className="flex items-center gap-2 text-3xl font-semibold tracking-tight">
          <Radio className="h-7 w-7 text-emerald-500 dark:text-emerald-300" /> Live examinations
        </h1>
        <p className="mt-1 text-sm text-muted">Monitor who is writing — progress, time remaining, and proctoring risk in real time.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {monitorable.map((a) => (
          <Link key={a.id} href={`/exams/${a.id}`}>
            <GlassCard hover glow className="p-5">
              <div className="flex items-start justify-between">
                <Badge tone={tone[a.status] ?? "neutral"} className="capitalize">{a.status}</Badge>
                <ArrowUpRight className="h-4 w-4 text-faint" />
              </div>
              <h3 className="mt-4 font-semibold tracking-tight">{a.title}</h3>
              <p className="mt-1 text-xs text-faint capitalize">{a.kind}</p>
              {a.status === "live" && (
                <div className="mt-3 inline-flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-300">
                  <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" /> in progress
                </div>
              )}
            </GlassCard>
          </Link>
        ))}
      </div>
      {!live && <p className="text-center text-xs text-faint">Demo assessments — sign in to monitor live examinations.</p>}
    </div>
  );
}
