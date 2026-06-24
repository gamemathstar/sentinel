"use client";

import { useState } from "react";
import Link from "next/link";
import { motion, AnimatePresence } from "motion/react";
import { Plus, Users, Building2, Lock, Globe2, Check, Share2 } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Segmented } from "@/components/ui/Segmented";
import { ShareDialog } from "@/components/banks/ShareDialog";
import { useApiList } from "@/lib/useApiList";
import { listBanks, listOrgNodes, createBank, ApiError, type Bank, type OrgNode } from "@/lib/api";
import { demoBanks, demoOrgNodes } from "@/lib/demo";
import { cn } from "@/lib/cn";

export default function BanksPage() {
  const { data: banks, live } = useApiList<Bank>(listBanks, demoBanks);
  const { data: orgNodes } = useApiList<OrgNode>(() => listOrgNodes(), demoOrgNodes as OrgNode[]);
  const [open, setOpen] = useState(false);
  const [rows, setRows] = useState<Bank[]>([]);
  const [shareBank, setShareBank] = useState<Bank | null>(null);
  const all = [...rows, ...banks];

  return (
    <div className="space-y-7">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-semibold tracking-tight">Question Banks</h1>
          <p className="mt-1 text-sm text-muted">
            Containers owned by a department, programme, or course — each with its own visibility.
          </p>
        </div>
        <Button onClick={() => setOpen((o) => !o)} className="shrink-0">
          <Plus className="h-4 w-4" /> New bank
        </Button>
      </div>

      <AnimatePresence>
        {open && (
          <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: "auto" }} exit={{ opacity: 0, height: 0 }}>
            <CreateBank orgNodes={orgNodes} onCreated={(b) => { setRows((r) => [b, ...r]); setOpen(false); }} />
          </motion.div>
        )}
      </AnimatePresence>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {all.map((b) => (
          <GlassCard key={b.id} hover glow className="p-5">
            <Link href={`/banks/${b.id}`} className="block">
              <div className="flex items-start justify-between">
                <div className="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br from-violet-500/30 to-transparent text-violet-700 dark:text-violet-200">
                  <Building2 className="h-5 w-5" strokeWidth={1.8} />
                </div>
                <VisibilityBadge visibility={b.visibility} />
              </div>
              <h3 className="mt-4 font-semibold tracking-tight">{b.name}</h3>
              <p className="mt-1 text-xs text-faint">
                {b.owner_org_node ? `${b.owner_org_node.name} · ${b.owner_org_node.type}` : "Institution-level"}
              </p>
            </Link>
            <div className="mt-4 flex items-center justify-between">
              <div className="flex items-center gap-2 text-sm text-muted">
                <Users className="h-4 w-4 text-faint" /> {b.items_count ?? 0} questions
              </div>
              <button
                onClick={() => setShareBank(b)}
                className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs text-violet-600 dark:text-violet-300 hover:bg-violet-500/10"
              >
                <Share2 className="h-3.5 w-3.5" /> Share
                {(b.shared_users_count || b.shared_groups_count)
                  ? ` · ${(b.shared_users_count ?? 0) + (b.shared_groups_count ?? 0)}`
                  : ""}
              </button>
            </div>
          </GlassCard>
        ))}
      </div>

      {!live && (
        <p className="text-center text-xs text-faint">Showing demo banks — sign in to load your institution&apos;s banks.</p>
      )}

      {shareBank && <ShareDialog bank={shareBank} onClose={() => setShareBank(null)} />}
    </div>
  );
}

function VisibilityBadge({ visibility }: { visibility: Bank["visibility"] }) {
  return visibility === "org_subtree" ? (
    <Badge tone="sky"><Globe2 className="h-3 w-3" /> Org subtree</Badge>
  ) : (
    <Badge tone="violet"><Lock className="h-3 w-3" /> Restricted</Badge>
  );
}

function CreateBank({ orgNodes, onCreated }: { orgNodes: OrgNode[]; onCreated: (b: Bank) => void }) {
  const [name, setName] = useState("");
  const [owner, setOwner] = useState<string>("");
  const [visibility, setVisibility] = useState<"org_subtree" | "restricted">("org_subtree");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const owners = orgNodes.filter((n) => ["department", "programme", "course", "specialization"].includes(n.type));

  async function submit() {
    setError(null);
    if (!name.trim()) return setError("Name the bank.");
    setBusy(true);
    try {
      const bank = await createBank(name, owner || null, visibility);
      onCreated(bank);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Sign in and start the API to create a live bank.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <GlassCard glow className="space-y-4 p-6">
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="mb-2 block text-sm font-medium text-muted">Bank name</label>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="e.g. CSC101 Question Bank"
            className="h-11 w-full rounded-xl border border-line bg-black/[0.02] dark:bg-white/[0.03] px-4 text-sm outline-none focus:border-violet-400/50"
          />
        </div>
        <div>
          <label className="mb-2 block text-sm font-medium text-muted">Owned by</label>
          <select
            value={owner}
            onChange={(e) => setOwner(e.target.value)}
            className="h-11 w-full rounded-xl border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50"
          >
            <option value="">Institution-level</option>
            {owners.map((n) => (
              <option key={n.id} value={n.id}>{n.name} ({n.type})</option>
            ))}
          </select>
        </div>
      </div>

      <div>
        <label className="mb-2 block text-sm font-medium text-muted">Visibility</label>
        <Segmented
          value={visibility}
          onChange={(v) => setVisibility(v as typeof visibility)}
          options={[
            { value: "org_subtree", label: "Owning org subtree" },
            { value: "restricted", label: "Restricted (shared only)" },
          ]}
        />
        <p className="mt-2 text-xs text-faint">
          {visibility === "org_subtree"
            ? "Visible to staff scoped to the owning department / programme / course (and below)."
            : "Visible only to you and the users / staff groups you explicitly share it with."}
        </p>
      </div>

      {error && <p className="rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-200">{error}</p>}

      <Button onClick={submit} disabled={busy}>
        {busy ? "Creating…" : "Create bank"} {!busy && <Check className="h-4 w-4" />}
      </Button>
    </GlassCard>
  );
}
