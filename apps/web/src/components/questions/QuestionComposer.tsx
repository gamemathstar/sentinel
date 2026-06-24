"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { motion } from "motion/react";
import { Plus, Trash2, Check, CheckCheck, Undo2, X, Sparkles, CheckCircle2, Lock, Layers } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Segmented } from "@/components/ui/Segmented";
import {
  createItem, updateItem, getItem, listBanks, listOrgNodes, reviewVersion,
  ApiError, type Bank, type OrgNode,
} from "@/lib/api";
import { useApiList } from "@/lib/useApiList";
import { demoBanks, demoOrgNodes } from "@/lib/demo";
import { cn } from "@/lib/cn";

const LETTERS = "abcdefghij".split("");
type ItemType = "single" | "multiple" | "true_false" | "essay";

export function QuestionComposer({ presetBankId, editItemId }: { presetBankId?: string; editItemId?: string }) {
  const editing = !!editItemId;
  const { data: banks } = useApiList<Bank>(listBanks, demoBanks);
  const { data: orgNodes } = useApiList<OrgNode>(() => listOrgNodes(), demoOrgNodes as OrgNode[]);
  const courses = orgNodes.filter((n) => n.type === "course");
  const specializations = orgNodes.filter((n) => n.type === "specialization");

  const [type, setType] = useState<ItemType>("single");
  const [stem, setStem] = useState("");
  const [options, setOptions] = useState<string[]>(["", "", "", ""]);
  const [single, setSingle] = useState<number | null>(0);
  const [multi, setMulti] = useState<Set<number>>(new Set());
  const [bloom, setBloom] = useState(2);
  const [band, setBand] = useState<"easy" | "medium" | "hard">("medium");
  const [bankId, setBankId] = useState(presetBankId ?? "");
  const [courseId, setCourseId] = useState("");
  const [specId, setSpecId] = useState("");
  const [tags, setTags] = useState<string[]>([]);
  const [tagInput, setTagInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Review workflow (edit mode)
  const [versionId, setVersionId] = useState<string | null>(null);
  const [versionState, setVersionState] = useState<string | null>(null);
  const [reviewComment, setReviewComment] = useState("");
  const [reviewBusy, setReviewBusy] = useState(false);
  const [reviewNote, setReviewNote] = useState<string | null>(null);

  // Load the existing question when editing.
  useEffect(() => {
    if (!editItemId) return;
    getItem(editItemId).then((it) => {
      setVersionId(it.current_version?.id ?? null);
      setVersionState(it.current_version?.state ?? null);
      setType((it.type as ItemType) ?? "single");
      setBankId(it.question_bank_id ?? "");
      setCourseId(it.course_org_node_id ?? "");
      setSpecId(it.specialization_org_node_id ?? "");
      setTags(it.tags ?? []);
      const opts = it.current_version?.content?.options ?? {};
      const keys = Object.keys(opts);
      if (keys.length) {
        setOptions(keys.map((k) => opts[k]));
        const correct = it.answer?.correct ?? [];
        const idx = correct.map((k) => keys.indexOf(k)).filter((i) => i >= 0);
        setSingle(idx[0] ?? 0);
        setMulti(new Set(idx));
      }
      setStem(it.current_version?.content?.stem ?? "");
    }).catch(() => setError("Couldn't load this question — sign in to edit live."));
  }, [editItemId]);

  const effectiveOptions = type === "true_false" ? ["True", "False"] : options;
  const isChoice = type === "single" || type === "multiple" || type === "true_false";

  function changeType(t: ItemType) {
    setType(t);
    setSingle(0);
    setMulti(new Set());
    if (t === "true_false") setOptions(["True", "False"]);
    else if (t === "essay") setOptions([]);
    else if (options.length < 2) setOptions(["", "", "", ""]);
  }
  function correctKeys(): string[] {
    if (type === "essay") return [];
    if (type === "multiple") return [...multi].sort().map((i) => LETTERS[i]);
    return single !== null ? [LETTERS[single]] : [];
  }
  function addTag(t: string) {
    const v = t.trim().toLowerCase();
    if (v && !tags.includes(v)) setTags((p) => [...p, v]);
    setTagInput("");
  }
  function reset() {
    setDone(false); setStem(""); setError(null); setTags([]);
    setOptions(type === "true_false" ? ["True", "False"] : type === "essay" ? [] : ["", "", "", ""]);
    setSingle(0); setMulti(new Set());
  }

  async function review(decision: "approve" | "reject" | "revise") {
    if (!versionId) return;
    setReviewBusy(true);
    setReviewNote(null);
    try {
      const res = await reviewVersion(versionId, decision, reviewComment || undefined);
      setVersionState(res.version_state);
      setReviewComment("");
      setReviewNote(decision === "approve" ? "Advanced to the next stage." : "Sent back to draft for changes.");
    } catch (err) {
      setReviewNote(err instanceof ApiError ? err.message : "Sign in with reviewer rights to act on this.");
    } finally {
      setReviewBusy(false);
    }
  }

  async function submit() {
    setError(null);
    if (!bankId) return setError("Choose a question bank.");
    if (!stem.trim()) return setError("Add a question stem.");
    if (isChoice && effectiveOptions.some((o) => !o.trim())) return setError("Fill in every option.");
    if (isChoice && correctKeys().length === 0) return setError("Mark the correct answer.");

    setBusy(true);
    try {
      const input = {
        type, stem,
        options: effectiveOptions.map((text, i) => ({ key: LETTERS[i], text })),
        correct: correctKeys(),
        questionBankId: bankId, courseOrgNodeId: courseId || null, specializationOrgNodeId: specId || null,
        tags, bloom, difficultyBand: band,
      };
      if (editing) await updateItem(editItemId!, input);
      else await createItem(input);
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't reach the API — sign in and start `php artisan serve`.");
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    const bankName = banks.find((b) => b.id === bankId)?.name;
    return (
      <motion.div initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} className="mx-auto max-w-lg">
        <GlassCard glow className="p-8 text-center">
          <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-emerald-500/30 to-transparent text-emerald-500 dark:text-emerald-300">
            <CheckCircle2 className="h-7 w-7" />
          </div>
          <h2 className="mt-5 text-2xl font-semibold tracking-tight">{editing ? "Question updated" : "Question created"}</h2>
          <p className="mt-2 text-sm text-muted">
            {editing ? "A new immutable version was saved; published papers keep their pinned version." : "Stored in the bank; the correct answer went to the split-key vault, never beside the question."}
          </p>
          {bankName && (
            <p className="mt-4 inline-flex items-center gap-2 rounded-full glass px-3 py-1.5 text-sm">
              <Layers className="h-4 w-4 text-violet-500 dark:text-violet-300" /> {bankName}
            </p>
          )}
          <div className="mt-6 flex justify-center gap-3">
            <Link href={bankId ? `/banks/${bankId}` : "/banks"}><Button variant="glass">Back to bank</Button></Link>
            {!editing && <Button onClick={reset}>Compose another</Button>}
          </div>
        </GlassCard>
      </motion.div>
    );
  }

  return (
    <div className="space-y-6">
      {editing && versionId && (
        <WorkflowCard state={versionState} busy={reviewBusy} note={reviewNote} comment={reviewComment} setComment={setReviewComment} onReview={review} />
      )}
      <div className="grid gap-6 lg:grid-cols-[1fr_420px]">
      <div className="space-y-5">
        <GlassCard className="space-y-5 p-6">
          <Field label="Question type">
            <Segmented value={type} onChange={(t) => changeType(t as ItemType)} options={[
              { value: "single", label: "Single" }, { value: "multiple", label: "Multiple" },
              { value: "true_false", label: "True / False" }, { value: "essay", label: "Essay" },
            ]} />
          </Field>

          <Field label="Stem">
            <textarea value={stem} onChange={(e) => setStem(e.target.value)} rows={3} placeholder="Write the question…"
              className="w-full resize-none rounded-xl border border-line bg-black/[0.02] dark:bg-white/[0.03] px-4 py-3 text-sm outline-none focus:border-violet-400/50" />
          </Field>

          {isChoice && (
            <Field label={type === "multiple" ? "Options · tick all correct" : "Options · select the correct one"}>
              <div className="space-y-2">
                {effectiveOptions.map((opt, i) => {
                  const correct = type === "multiple" ? multi.has(i) : single === i;
                  return (
                    <div key={i} className="flex items-center gap-2">
                      <button type="button" onClick={() => {
                        if (type === "multiple") setMulti((prev) => { const n = new Set(prev); n.has(i) ? n.delete(i) : n.add(i); return n; });
                        else setSingle(i);
                      }} className={cn("grid h-9 w-9 shrink-0 place-items-center rounded-lg border text-sm font-semibold transition",
                        correct ? "border-transparent bg-gradient-to-br from-emerald-500 to-teal-500 text-white" : "border-line text-muted hover:border-line-strong")}>
                        {correct ? <Check className="h-4 w-4" /> : LETTERS[i].toUpperCase()}
                      </button>
                      <input value={opt} onChange={(e) => setOptions((o) => o.map((v, idx) => idx === i ? e.target.value : v))} disabled={type === "true_false"}
                        placeholder={`Option ${LETTERS[i].toUpperCase()}`} className="h-10 flex-1 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50 disabled:opacity-60" />
                      {type !== "true_false" && effectiveOptions.length > 2 && (
                        <button type="button" onClick={() => { setOptions((o) => o.filter((_, idx) => idx !== i)); setSingle((s) => s === i ? 0 : s); setMulti(new Set()); }}
                          className="grid h-9 w-9 place-items-center rounded-lg text-faint hover:text-rose-400 hover:bg-rose-500/10"><Trash2 className="h-4 w-4" /></button>
                      )}
                    </div>
                  );
                })}
                {type !== "true_false" && effectiveOptions.length < LETTERS.length && (
                  <button type="button" onClick={() => setOptions((o) => [...o, ""])} className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-violet-600 dark:text-violet-300 hover:bg-violet-500/10">
                    <Plus className="h-4 w-4" /> Add option
                  </button>
                )}
              </div>
            </Field>
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Question bank *">
              <select value={bankId} onChange={(e) => setBankId(e.target.value)} disabled={editing}
                className="h-10 w-full rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50 disabled:opacity-60">
                <option value="">Select a bank…</option>
                {banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
            </Field>
            <Field label="Course">
              <select value={courseId} onChange={(e) => setCourseId(e.target.value)} className="h-10 w-full rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50">
                <option value="">—</option>
                {courses.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Specialization">
              <select value={specId} onChange={(e) => setSpecId(e.target.value)} className="h-10 w-full rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50">
                <option value="">—</option>
                {specializations.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
            <Field label="Tags">
              <div className="flex min-h-10 flex-wrap items-center gap-1.5 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-2 py-1.5">
                {tags.map((t) => (
                  <button key={t} type="button" onClick={() => setTags(tags.filter((x) => x !== t))} className="inline-flex items-center gap-1 rounded-md bg-violet-500/15 px-2 py-0.5 text-xs text-violet-700 dark:text-violet-200">
                    {t} <span className="opacity-60">×</span>
                  </button>
                ))}
                <input value={tagInput} onChange={(e) => setTagInput(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter" || e.key === ",") { e.preventDefault(); addTag(tagInput); } }}
                  placeholder="add tag…" className="min-w-20 flex-1 bg-transparent text-sm outline-none placeholder:text-faint" />
              </div>
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Difficulty">
              <Segmented value={band} onChange={(b) => setBand(b as typeof band)} options={[{ value: "easy", label: "Easy" }, { value: "medium", label: "Med" }, { value: "hard", label: "Hard" }]} />
            </Field>
            <Field label="Bloom level">
              <select value={bloom} onChange={(e) => setBloom(Number(e.target.value))} className="h-10 w-full rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50">
                {["Remember", "Understand", "Apply", "Analyze", "Evaluate", "Create"].map((l, i) => <option key={i} value={i + 1}>{i + 1} · {l}</option>)}
              </select>
            </Field>
          </div>

          {error && <p className="rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-200">{error}</p>}

          <div className="flex items-center gap-3">
            <Button onClick={submit} disabled={busy}>{busy ? "Saving…" : editing ? "Save changes" : "Create item"} {!busy && <Sparkles className="h-4 w-4" />}</Button>
            <span className="flex items-center gap-1.5 text-xs text-faint"><Lock className="h-3.5 w-3.5" /> answer key in the vault</span>
          </div>
        </GlassCard>
      </div>

      {/* Live preview */}
      <div className="lg:sticky lg:top-24 h-fit">
        <GlassCard glow className="p-6">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase tracking-wide text-faint">Candidate preview</span>
            <Badge tone="violet" className="capitalize">{type.replace("_", "/")}</Badge>
          </div>
          <h3 className="mt-4 text-lg font-medium leading-snug">{stem || "Your question will appear here."}</h3>
          {isChoice && (
            <div className="mt-5 space-y-2.5">
              {effectiveOptions.map((opt, i) => {
                const correct = type === "multiple" ? multi.has(i) : single === i;
                return (
                  <div key={i} className={cn("flex items-center gap-3 rounded-xl border px-4 py-3 text-sm", correct ? "border-emerald-400/40 bg-emerald-500/10" : "border-line")}>
                    <span className="grid h-7 w-7 place-items-center rounded-md border border-line text-xs font-semibold text-muted">{LETTERS[i].toUpperCase()}</span>
                    <span className={correct ? "" : "text-muted"}>{opt || `Option ${LETTERS[i].toUpperCase()}`}</span>
                    {correct && <Check className="ml-auto h-4 w-4 text-emerald-500 dark:text-emerald-400" />}
                  </div>
                );
              })}
            </div>
          )}
          {type === "essay" && <div className="mt-5 rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-faint">Free-text response · routed to manual / AI grading</div>}
        </GlassCard>
      </div>
      </div>
    </div>
  );
}

const WF_STAGES = ["draft", "reviewed", "moderated", "approved"];

function WorkflowCard({
  state, busy, note, comment, setComment, onReview,
}: {
  state: string | null;
  busy: boolean;
  note: string | null;
  comment: string;
  setComment: (v: string) => void;
  onReview: (d: "approve" | "reject" | "revise") => void;
}) {
  const idx = WF_STAGES.indexOf(state ?? "draft");
  const approved = state === "approved";

  return (
    <GlassCard glow className="p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="text-sm font-semibold">Review &amp; approval</h3>
        <Badge tone={approved ? "emerald" : "amber"} className="capitalize">{(state ?? "draft").replace("_", " ")}</Badge>
      </div>

      {/* Stage progress */}
      <div className="mt-4 flex items-center gap-2">
        {WF_STAGES.map((s, i) => (
          <div key={s} className="flex flex-1 items-center gap-2">
            <div className={cn("grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold",
              i < idx ? "bg-gradient-to-br from-emerald-500 to-teal-500 text-white"
                : i === idx ? "bg-gradient-to-br from-violet-500 to-sky-500 text-white"
                : "glass text-faint")}>
              {i < idx || approved ? <Check className="h-3.5 w-3.5" /> : i + 1}
            </div>
            <span className={cn("hidden text-xs capitalize sm:block", i === idx ? "text-ink" : "text-faint")}>{s}</span>
            {i < WF_STAGES.length - 1 && <div className="h-px flex-1 bg-[var(--line)]" />}
          </div>
        ))}
      </div>

      {approved ? (
        <p className="mt-4 flex items-center gap-2 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-200">
          <Check className="h-4 w-4" /> Approved — this question is active and eligible for assessments.
        </p>
      ) : (
        <div className="mt-4 space-y-3">
          <input
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder="Optional comment for the author…"
            className="h-10 w-full rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50"
          />
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => onReview("approve")} disabled={busy}>
              <CheckCheck className="h-4 w-4" /> Approve · advance
            </Button>
            <Button variant="glass" onClick={() => onReview("revise")} disabled={busy}>
              <Undo2 className="h-4 w-4" /> Request changes
            </Button>
            <Button variant="glass" onClick={() => onReview("reject")} disabled={busy}>
              <X className="h-4 w-4" /> Reject
            </Button>
          </div>
          <p className="text-xs text-faint">
            Separation of duties: you can&apos;t approve your own question, and no one performs two consecutive stages.
          </p>
        </div>
      )}
      {note && <p className="mt-3 rounded-lg bg-white/5 px-3 py-2 text-xs text-muted">{note}</p>}
    </GlassCard>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div><label className="mb-2 block text-sm font-medium text-muted">{label}</label>{children}</div>;
}
