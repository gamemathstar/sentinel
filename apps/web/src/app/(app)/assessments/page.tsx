"use client";

import Link from "next/link";
import { ArrowUpRight, Plus } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { ButtonLink } from "@/components/ui/Button";
import { useApiList } from "@/lib/useApiList";
import { listAssessments } from "@/lib/api";
import { demoAssessments } from "@/lib/demo";

type Row = { id: string; title: string; kind: string; status: string; candidates?: number; items?: number; kr20?: number | null };

const statusTone: Record<string, "emerald" | "sky" | "neutral" | "amber"> = {
  live: "emerald",
  published: "sky",
  closed: "neutral",
  draft: "amber",
};

export default function AssessmentsPage() {
  const { data, live } = useApiList<Row>(
    () => listAssessments().then((r) => r.data),
    demoAssessments as Row[],
  );

  return (
    <div className="space-y-7">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-semibold tracking-tight">Assessments</h1>
          <p className="mt-1 text-sm text-muted">{data.length} exams across the institution{!live && " · demo"}</p>
        </div>
        <ButtonLink href="/assessments/new" className="shrink-0">
          <Plus className="h-4 w-4" /> New assessment
        </ButtonLink>
      </div>

      <GlassCard glow className="overflow-hidden">
        <div className="hidden grid-cols-12 gap-4 border-b border-line px-6 py-3 text-xs uppercase tracking-wide text-faint sm:grid">
          <div className="col-span-5">Assessment</div>
          <div className="col-span-2">Status</div>
          <div className="col-span-2">Candidates</div>
          <div className="col-span-2">Reliability</div>
          <div className="col-span-1" />
        </div>
        <div className="divide-y divide-white/5">
          {data.map((a) => (
            <Link
              key={a.id}
              href={`/assessments/${a.id}`}
              className="grid grid-cols-1 gap-2 px-6 py-4 transition hover:bg-white/[0.03] sm:grid-cols-12 sm:items-center sm:gap-4"
            >
              <div className="col-span-5">
                <div className="font-medium">{a.title}</div>
                <div className="text-xs text-faint capitalize">{a.kind}{a.items != null ? ` · ${a.items} items` : ""}</div>
              </div>
              <div className="col-span-2">
                <Badge tone={statusTone[a.status] ?? "neutral"} className="capitalize">{a.status}</Badge>
              </div>
              <div className="col-span-2 text-sm text-muted">{a.candidates != null ? a.candidates.toLocaleString() : "—"}</div>
              <div className="col-span-2 text-sm">
                {a.kr20 != null ? (
                  <span className="font-mono text-emerald-600 dark:text-emerald-300">{a.kr20.toFixed(2)}</span>
                ) : (
                  <span className="text-faint">—</span>
                )}
              </div>
              <div className="col-span-1 flex justify-end">
                <ArrowUpRight className="h-4 w-4 text-faint" />
              </div>
            </Link>
          ))}
        </div>
      </GlassCard>
    </div>
  );
}
