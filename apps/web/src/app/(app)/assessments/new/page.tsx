"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { motion, AnimatePresence } from "motion/react";
import { ArrowLeft, ArrowRight, Check, Rocket, CheckCircle2, Search, Tag } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import {
  buildWithSelectedQuestions, listBanks, listOrgNodes, listItems,
  ApiError, type Bank, type OrgNode, type ItemRow,
} from "@/lib/api";
import { useApiList } from "@/lib/useApiList";
import { demoBanks, demoOrgNodes, demoItems } from "@/lib/demo";
import { cn } from "@/lib/cn";

const KINDS = ["practice", "ca", "midterm", "final", "postutme", "recruitment", "certification", "licensing", "mock"];
const STEPS = ["Details", "Blueprint", "Questions", "Review"];

const BAND_FACILITY = { easy: 0.8, medium: 0.5, hard: 0.2 } as const;

const demoRows: ItemRow[] = demoItems.map((it, i) => ({
  id: `demo-${i}`, type: it.type, status: it.state === "approved" ? "active" : it.state,
  difficulty: BAND_FACILITY[it.band], tags: ["fundamentals"],
  question_bank_id: "bank-csc", course_org_node_id: "course-csc101", specialization_org_node_id: "spec-systems",
  current_version: { id: `dv-${i}`, content: { stem: it.stem } },
}));

/** Facility index → difficulty band (high facility = easy). Mirrors the backend. */
function bandOf(facility?: number | null): "easy" | "medium" | "hard" | null {
  if (facility == null) return null;
  if (facility >= 0.66) return "easy";
  if (facility >= 0.33) return "medium";
  return "hard";
}

export default function NewAssessmentPage() {
  const [step, setStep] = useState(0);
  const [title, setTitle] = useState("CSC101 Final Examination");
  const [kind, setKind] = useState("final");
  const [duration, setDuration] = useState(60);
  const [total, setTotal] = useState(40);
  const [easy, setEasy] = useState(40);
  const [medium, setMedium] = useState(40);
  const hard = Math.max(0, 100 - easy - medium);

  const { data: banks } = useApiList<Bank>(listBanks, demoBanks);
  const { data: orgNodes } = useApiList<OrgNode>(() => listOrgNodes(), demoOrgNodes as OrgNode[]);
  const courses = orgNodes.filter((n) => n.type === "course");
  const specializations = orgNodes.filter((n) => n.type === "specialization");
  const [bankIds, setBankIds] = useState<string[]>([]);

  // Question selection
  const [questions, setQuestions] = useState<ItemRow[]>([]);
  const [loadedQuestions, setLoadedQuestions] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [filters, setFilters] = useState({ course: "", spec: "", tag: "", type: "", search: "" });

  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Load questions when entering the selection step.
  useEffect(() => {
    if (step !== 2 || loadedQuestions) return;
    listItems({ per_page: "200" })
      .then((res) => setQuestions(res.data))
      .catch(() => setQuestions(demoRows))
      .finally(() => setLoadedQuestions(true));
  }, [step, loadedQuestions]);

  const visible = useMemo(() => questions.filter((q) =>
    (bankIds.length === 0 || (q.question_bank_id != null && bankIds.includes(q.question_bank_id))) &&
    (!filters.course || q.course_org_node_id === filters.course) &&
    (!filters.spec || q.specialization_org_node_id === filters.spec) &&
    (!filters.type || q.type === filters.type) &&
    (!filters.tag || (q.tags ?? []).some((t) => t.toLowerCase().includes(filters.tag.toLowerCase()))) &&
    (!filters.search || (q.current_version?.content?.stem ?? "").toLowerCase().includes(filters.search.toLowerCase()))
  ), [questions, bankIds, filters]);

  const mix = useMemo(() => {
    const c = { easy: 0, medium: 0, hard: 0, unrated: 0 };
    questions.forEach((q) => {
      if (!q.current_version?.id || !selected.has(q.current_version.id)) return;
      const b = bandOf(q.difficulty);
      if (b) c[b]++; else c.unrated++;
    });
    return c;
  }, [questions, selected]);

  function toggle(q: ItemRow) {
    const vid = q.current_version?.id;
    if (!vid) return;
    setSelected((prev) => {
      const n = new Set(prev);
      n.has(vid) ? n.delete(vid) : n.add(vid);
      return n;
    });
  }

  async function publish() {
    setError(null);
    if (selected.size === 0) return setError("Select at least one question.");
    setBusy(true);
    try {
      const res = await buildWithSelectedQuestions({ title, kind, durationMinutes: duration, itemVersionIds: [...selected] });
      setDone(res.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Sign in and run the API to publish a live assessment.");
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <motion.div initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} className="mx-auto max-w-lg">
        <GlassCard glow className="p-8 text-center">
          <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-emerald-500/30 to-transparent text-emerald-500 dark:text-emerald-300">
            <CheckCircle2 className="h-7 w-7" />
          </div>
          <h2 className="mt-5 text-2xl font-semibold tracking-tight">Published</h2>
          <p className="mt-2 text-sm text-muted">{selected.size} curated question(s) pinned and the assessment is live.</p>
          <div className="mt-6 flex justify-center gap-3">
            <Link href="/assessments"><Button variant="glass">All assessments</Button></Link>
            <Link href={`/exam/${done}`}><Button>Preview runtime</Button></Link>
          </div>
        </GlassCard>
      </motion.div>
    );
  }

  const canNext = step === 0 ? title.trim().length > 0 : step === 1 ? bankIds.length > 0 : true;

  return (
    <div className="mx-auto max-w-3xl space-y-7">
      <div>
        <Link href="/assessments" className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink">
          <ArrowLeft className="h-4 w-4" /> Assessments
        </Link>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">New assessment</h1>
      </div>

      {/* Stepper */}
      <div className="flex items-center gap-3">
        {STEPS.map((s, i) => (
          <div key={s} className="flex flex-1 items-center gap-3">
            <div className={cn(
              "grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-semibold transition",
              i < step ? "bg-gradient-to-br from-emerald-500 to-teal-500 text-white"
                : i === step ? "bg-gradient-to-br from-violet-500 to-sky-500 text-white"
                : "glass text-faint",
            )}>
              {i < step ? <Check className="h-4 w-4" /> : i + 1}
            </div>
            <span className={cn("hidden text-sm font-medium sm:block", i === step ? "text-ink" : "text-faint")}>{s}</span>
            {i < STEPS.length - 1 && <div className="h-px flex-1 bg-[var(--line)]" />}
          </div>
        ))}
      </div>

      <GlassCard glow className="p-6 sm:p-8">
        <AnimatePresence mode="wait">
          <motion.div key={step} initial={{ opacity: 0, x: 14 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -14 }} transition={{ duration: 0.22 }}>
            {step === 0 && (
              <div className="space-y-5">
                <Field label="Title">
                  <input value={title} onChange={(e) => setTitle(e.target.value)} className="h-11 w-full rounded-xl border border-line bg-black/[0.02] dark:bg-white/[0.03] px-4 text-sm outline-none focus:border-violet-400/50" />
                </Field>
                <div className="grid gap-5 sm:grid-cols-2">
                  <Field label="Kind">
                    <select value={kind} onChange={(e) => setKind(e.target.value)} className="h-11 w-full rounded-xl border border-line bg-bg-soft px-3 text-sm capitalize outline-none focus:border-violet-400/50">
                      {KINDS.map((k) => <option key={k} value={k}>{k}</option>)}
                    </select>
                  </Field>
                  <Field label={`Duration · ${duration} min`}>
                    <input type="range" min={10} max={240} step={5} value={duration} onChange={(e) => setDuration(Number(e.target.value))} className="mt-4 w-full accent-violet-500" />
                  </Field>
                </div>
              </div>
            )}

            {step === 1 && (
              <div className="space-y-6">
                <Field label="Source banks *">
                  <div className="flex flex-wrap gap-2">
                    {banks.map((b) => {
                      const on = bankIds.includes(b.id);
                      return (
                        <button key={b.id} type="button"
                          onClick={() => setBankIds((p) => on ? p.filter((x) => x !== b.id) : [...p, b.id])}
                          className={cn("inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition",
                            on ? "border-violet-400/50 bg-violet-500/10 text-ink" : "border-line glass text-muted hover:text-ink")}>
                          {on && <Check className="h-3.5 w-3.5 text-violet-500 dark:text-violet-300" />}{b.name}
                        </button>
                      );
                    })}
                  </div>
                  <p className="mt-2 text-xs text-faint">Their questions load in the next step. Pick at least one bank.</p>
                </Field>

                <Field label={`Target size · ${total} questions`}>
                  <input type="range" min={5} max={150} step={5} value={total} onChange={(e) => setTotal(Number(e.target.value))} className="mt-3 w-full accent-violet-500" />
                </Field>
                <div className="space-y-4">
                  <Slider label="Easy" value={easy} tone="emerald" onChange={(v) => setEasy(Math.min(v, 100 - medium))} />
                  <Slider label="Medium" value={medium} tone="amber" onChange={(v) => setMedium(Math.min(v, 100 - easy))} />
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted">Hard <span className="text-faint">(auto)</span></span>
                    <span className="font-medium text-rose-500 dark:text-rose-300">{hard}%</span>
                  </div>
                  <div className="overflow-hidden rounded-xl"><div className="flex h-3">
                    <span className="bg-emerald-500" style={{ width: `${easy}%` }} />
                    <span className="bg-amber-500" style={{ width: `${medium}%` }} />
                    <span className="bg-rose-500" style={{ width: `${hard}%` }} />
                  </div></div>
                  <p className="text-xs text-faint">A guide for curation — the next step tracks your picks against this target.</p>
                </div>
              </div>
            )}

            {step === 2 && (
              <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                  <label className="flex h-10 min-w-44 flex-1 items-center gap-2 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3">
                    <Search className="h-4 w-4 text-faint" />
                    <input value={filters.search} onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))} placeholder="Search stems…" className="w-full bg-transparent text-sm outline-none placeholder:text-faint" />
                  </label>
                  <FilterSelect value={filters.course} onChange={(v) => setFilters((f) => ({ ...f, course: v }))} placeholder="Any course" options={courses} />
                  <FilterSelect value={filters.spec} onChange={(v) => setFilters((f) => ({ ...f, spec: v }))} placeholder="Any specialization" options={specializations} />
                  <label className="flex h-10 w-32 items-center gap-2 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3">
                    <Tag className="h-3.5 w-3.5 text-faint" />
                    <input value={filters.tag} onChange={(e) => setFilters((f) => ({ ...f, tag: e.target.value }))} placeholder="tag" className="w-full bg-transparent text-sm outline-none placeholder:text-faint" />
                  </label>
                </div>

                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted">{visible.length} question(s) across {bankIds.length || "all readable"} bank(s)</span>
                  <Badge tone={selected.size >= total ? "emerald" : "violet"}>{selected.size} / {total} selected</Badge>
                </div>

                {/* Running difficulty mix — balance as you pick */}
                {selected.size > 0 && (
                  <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-faint">picked mix:</span>
                    <Badge tone="emerald">{mix.easy} easy</Badge>
                    <Badge tone="amber">{mix.medium} medium</Badge>
                    <Badge tone="rose">{mix.hard} hard</Badge>
                    {mix.unrated > 0 && <Badge tone="neutral">{mix.unrated} unrated</Badge>}
                  </div>
                )}

                <div className="max-h-96 space-y-2 overflow-y-auto pr-1">
                  {visible.length === 0 && <p className="py-8 text-center text-sm text-faint">No questions match these filters.</p>}
                  {visible.map((q) => {
                    const vid = q.current_version?.id;
                    const on = vid ? selected.has(vid) : false;
                    return (
                      <button key={q.id} type="button" onClick={() => toggle(q)}
                        className={cn("flex w-full items-start gap-3 rounded-xl border px-4 py-3 text-left transition",
                          on ? "border-violet-400/50 bg-violet-500/10" : "border-line glass hover:border-line-strong")}>
                        <span className={cn("mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-md border", on ? "border-transparent bg-gradient-to-br from-violet-500 to-sky-500 text-white" : "border-line")}>
                          {on && <Check className="h-3.5 w-3.5" />}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="block text-sm font-medium">{q.current_version?.content?.stem ?? "—"}</span>
                          <span className="mt-0.5 block text-xs text-faint capitalize">{q.type.replace("_", "/")}{q.question_bank?.name ? ` · ${q.question_bank.name}` : ""}</span>
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {step === 3 && (
              <div className="space-y-5">
                <h3 className="text-lg font-semibold tracking-tight">{title}</h3>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <Summary k="Kind" v={kind} />
                  <Summary k="Duration" v={`${duration} min`} />
                  <Summary k="Selected" v={`${selected.size}`} />
                  <Summary k="Target" v={`${total}`} />
                </div>
                <p className="text-sm text-muted">
                  {selected.size} hand-picked question(s) will be pinned into this assessment.
                  {selected.size < total && <span className="text-amber-600 dark:text-amber-300"> {total - selected.size} below your target.</span>}
                </p>

                {/* Difficulty mix of the selection */}
                <div>
                  <p className="text-xs uppercase tracking-wide text-faint">Difficulty mix</p>
                  <div className="mt-2 flex flex-wrap gap-2">
                    <Badge tone="emerald">{mix.easy} easy</Badge>
                    <Badge tone="amber">{mix.medium} medium</Badge>
                    <Badge tone="rose">{mix.hard} hard</Badge>
                    {mix.unrated > 0 && <Badge tone="neutral">{mix.unrated} unrated</Badge>}
                  </div>
                  {selected.size > 0 && (
                    <div className="mt-3 flex h-2.5 overflow-hidden rounded-full bg-white/8">
                      <span className="bg-emerald-500" style={{ width: `${(mix.easy / selected.size) * 100}%` }} />
                      <span className="bg-amber-500" style={{ width: `${(mix.medium / selected.size) * 100}%` }} />
                      <span className="bg-rose-500" style={{ width: `${(mix.hard / selected.size) * 100}%` }} />
                      <span className="bg-white/15" style={{ width: `${(mix.unrated / selected.size) * 100}%` }} />
                    </div>
                  )}
                  {mix.unrated > 0 && (
                    <p className="mt-2 text-xs text-faint">Unrated questions have no facility index yet — they get one once analytics runs on real sittings.</p>
                  )}
                </div>

                {error && <p className="rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-200">{error}</p>}
              </div>
            )}
          </motion.div>
        </AnimatePresence>

        <div className="mt-8 flex items-center justify-between">
          <Button variant="glass" disabled={step === 0} onClick={() => setStep((s) => s - 1)}><ArrowLeft className="h-4 w-4" /> Back</Button>
          {step < 3 ? (
            <Button onClick={() => setStep((s) => s + 1)} disabled={!canNext}>Continue <ArrowRight className="h-4 w-4" /></Button>
          ) : (
            <Button onClick={publish} disabled={busy || selected.size === 0}>
              {busy ? "Publishing…" : "Create & publish"} {!busy && <Rocket className="h-4 w-4" />}
            </Button>
          )}
        </div>
      </GlassCard>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div><label className="mb-2 block text-sm font-medium text-muted">{label}</label>{children}</div>;
}

function FilterSelect({ value, onChange, placeholder, options }: { value: string; onChange: (v: string) => void; placeholder: string; options: OrgNode[] }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className="h-10 rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50">
      <option value="">{placeholder}</option>
      {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
    </select>
  );
}

function Slider({ label, value, tone, onChange }: { label: string; value: number; tone: "emerald" | "amber"; onChange: (v: number) => void }) {
  const color = tone === "emerald" ? "text-emerald-500 dark:text-emerald-300" : "text-amber-500 dark:text-amber-300";
  return (
    <div>
      <div className="flex items-center justify-between text-sm"><span className="text-muted">{label}</span><span className={cn("font-medium", color)}>{value}%</span></div>
      <input type="range" min={0} max={100} step={5} value={value} onChange={(e) => onChange(Number(e.target.value))} className="mt-2 w-full accent-violet-500" />
    </div>
  );
}

function Summary({ k, v }: { k: string; v: string }) {
  return <div className="glass rounded-xl px-4 py-3"><div className="text-xs text-faint">{k}</div><div className="mt-0.5 font-medium capitalize">{v}</div></div>;
}
