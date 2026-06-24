"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Plus, Search, Tag, Lock, Globe2, Share2, Pencil } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { ButtonLink } from "@/components/ui/Button";
import { ShareDialog } from "@/components/banks/ShareDialog";
import {
  getBank, listItems, listOrgNodes, type Bank, type BankDetail, type ItemRow, type OrgNode,
} from "@/lib/api";
import { demoBanks, demoOrgNodes, demoItems } from "@/lib/demo";

const TYPES = ["single", "multiple", "true_false", "essay", "numerical", "fill_blank"];

const demoRowsFor = (bankId: string): ItemRow[] => demoItems.map((it, i) => ({
  id: `demo-${i}`, type: it.type, status: it.state === "approved" ? "active" : it.state,
  tags: ["fundamentals"], question_bank_id: bankId, course_org_node_id: "course-csc101",
  specialization_org_node_id: "spec-systems",
  current_version: { id: `dv-${i}`, content: { stem: it.stem } },
}));

export default function BankDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [bank, setBank] = useState<BankDetail | Bank | null>(demoBanks.find((b) => b.id === id) ?? null);
  const [items, setItems] = useState<ItemRow[]>(demoRowsFor(id));
  const [orgNodes, setOrgNodes] = useState<OrgNode[]>(demoOrgNodes as OrgNode[]);
  const [filters, setFilters] = useState({ course: "", spec: "", tag: "", type: "", search: "" });
  const [share, setShare] = useState(false);

  useEffect(() => {
    getBank(id).then(setBank).catch(() => {});
    listItems({ question_bank_id: id, per_page: "200" }).then((r) => setItems(r.data)).catch(() => {});
    listOrgNodes().then(setOrgNodes).catch(() => {});
  }, [id]);

  const courses = orgNodes.filter((n) => n.type === "course");
  const specializations = orgNodes.filter((n) => n.type === "specialization");

  const visible = useMemo(() => items.filter((q) =>
    (!filters.course || q.course_org_node_id === filters.course) &&
    (!filters.spec || q.specialization_org_node_id === filters.spec) &&
    (!filters.type || q.type === filters.type) &&
    (!filters.tag || (q.tags ?? []).some((t) => t.toLowerCase().includes(filters.tag.toLowerCase()))) &&
    (!filters.search || (q.current_version?.content?.stem ?? "").toLowerCase().includes(filters.search.toLowerCase()))
  ), [items, filters]);

  return (
    <div className="space-y-6">
      <Link href="/banks" className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink">
        <ArrowLeft className="h-4 w-4" /> Question Banks
      </Link>

      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-3 text-3xl font-semibold tracking-tight">
            {bank?.name ?? "Bank"}
            {bank && (bank.visibility === "org_subtree"
              ? <Badge tone="sky"><Globe2 className="h-3 w-3" /> Org subtree</Badge>
              : <Badge tone="violet"><Lock className="h-3 w-3" /> Restricted</Badge>)}
          </h1>
          <p className="mt-1 text-sm text-muted">
            {bank?.owner_org_node ? `${bank.owner_org_node.name} · ${bank.owner_org_node.type}` : "Institution-level"} · {items.length} questions
          </p>
        </div>
        <div className="flex gap-2">
          {bank && <button onClick={() => setShare(true)} className="inline-flex h-11 items-center gap-2 rounded-xl glass glass-hover px-4 text-sm"><Share2 className="h-4 w-4" /> Share</button>}
          <ButtonLink href={`/bank/new?bank=${id}`}><Plus className="h-4 w-4" /> New question</ButtonLink>
        </div>
      </div>

      <GlassCard className="flex flex-wrap items-center gap-2 p-3">
        <label className="flex h-10 min-w-44 flex-1 items-center gap-2 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3">
          <Search className="h-4 w-4 text-faint" />
          <input value={filters.search} onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))} placeholder="Search stems…" className="w-full bg-transparent text-sm outline-none placeholder:text-faint" />
        </label>
        <Sel value={filters.course} onChange={(v) => setFilters((f) => ({ ...f, course: v }))} placeholder="All courses" options={courses} />
        <Sel value={filters.spec} onChange={(v) => setFilters((f) => ({ ...f, spec: v }))} placeholder="All specializations" options={specializations} />
        <select value={filters.type} onChange={(e) => setFilters((f) => ({ ...f, type: e.target.value }))} className="h-10 rounded-lg border border-line bg-bg-soft px-3 text-sm capitalize outline-none focus:border-violet-400/50">
          <option value="">All types</option>
          {TYPES.map((t) => <option key={t} value={t}>{t.replace("_", "/")}</option>)}
        </select>
        <label className="flex h-10 w-32 items-center gap-2 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3">
          <Tag className="h-3.5 w-3.5 text-faint" />
          <input value={filters.tag} onChange={(e) => setFilters((f) => ({ ...f, tag: e.target.value }))} placeholder="tag" className="w-full bg-transparent text-sm outline-none placeholder:text-faint" />
        </label>
      </GlassCard>

      <GlassCard glow className="overflow-hidden">
        <div className="divide-y divide-[var(--line)]">
          {visible.length === 0 && <div className="px-6 py-10 text-center text-sm text-faint">No questions match these filters.</div>}
          {visible.map((q) => (
            <Link key={q.id} href={`/bank/${q.id}/edit`} className="flex items-center gap-4 px-6 py-4 transition hover:bg-black/[0.02] dark:hover:bg-white/[0.03]">
              <div className="min-w-0 flex-1">
                <div className="truncate font-medium">{q.current_version?.content?.stem ?? "—"}</div>
                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-faint">
                  <span className="capitalize">{q.type.replace("_", "/")}</span>
                  {[q.course?.name, q.specialization?.name].filter(Boolean).map((x) => <span key={x}>· {x}</span>)}
                  {(q.tags ?? []).map((t) => <span key={t} className="rounded bg-violet-500/10 px-1.5 py-0.5 text-[10px] text-violet-700 dark:text-violet-300">{t}</span>)}
                </div>
              </div>
              {q.current_version?.state && q.current_version.state !== "approved" && (
                <Badge tone="amber" className="capitalize">{q.current_version.state.replace("_", " ")}</Badge>
              )}
              <Badge tone={q.status === "active" ? "emerald" : "neutral"} className="capitalize">{q.status === "active" ? "active" : "draft"}</Badge>
              <Pencil className="h-4 w-4 text-faint" />
            </Link>
          ))}
        </div>
      </GlassCard>

      {share && bank && <ShareDialog bank={bank as Bank} onClose={() => setShare(false)} />}
    </div>
  );
}

function Sel({ value, onChange, placeholder, options }: { value: string; onChange: (v: string) => void; placeholder: string; options: OrgNode[] }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className="h-10 rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50">
      <option value="">{placeholder}</option>
      {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
    </select>
  );
}
