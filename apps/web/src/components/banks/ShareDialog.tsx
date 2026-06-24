"use client";

import { useEffect, useState } from "react";
import { motion } from "motion/react";
import { X, UserPlus, Users, Plus, Check, Mail, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Segmented } from "@/components/ui/Segmented";
import {
  getBank, listUsers, listGroups, shareBankUser, shareBankGroup, unshareBankUser, unshareBankGroup, createGroup,
  type Bank, type BankDetail, type StaffUser, type StaffGroup, ApiError,
} from "@/lib/api";
import { demoUsers, demoGroups } from "@/lib/demo";
import { cn } from "@/lib/cn";

export function ShareDialog({ bank, onClose }: { bank: Bank; onClose: () => void }) {
  const [tab, setTab] = useState<"people" | "groups">("people");
  const [detail, setDetail] = useState<BankDetail | null>(null);
  const [users, setUsers] = useState<StaffUser[]>(demoUsers);
  const [groups, setGroups] = useState<StaffGroup[]>(demoGroups);
  const [canEdit, setCanEdit] = useState(false);
  const [newGroup, setNewGroup] = useState("");
  const [note, setNote] = useState<string | null>(null);
  const [live, setLive] = useState(false);

  useEffect(() => {
    Promise.allSettled([getBank(bank.id), listUsers(), listGroups()]).then(([d, u, g]) => {
      if (d.status === "fulfilled") {
        setDetail(d.value);
        setLive(true);
      }
      if (u.status === "fulfilled" && u.value.length) setUsers(u.value);
      if (g.status === "fulfilled" && g.value.length) setGroups(g.value);
    });
  }, [bank.id]);

  const sharedUserIds = new Set((detail?.shared_users ?? []).map((u) => u.id));
  const sharedGroupIds = new Set((detail?.shared_groups ?? []).map((g) => g.id));

  async function addUser(u: StaffUser) {
    try {
      await shareBankUser(bank.id, u.id, canEdit);
      setDetail((d) => (d ? { ...d, shared_users: [...(d.shared_users ?? []), u] } : d));
      setNote(`Shared with ${u.full_name}.`);
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : "Sign in to share a live bank.");
    }
  }

  async function addGroup(g: StaffGroup) {
    try {
      await shareBankGroup(bank.id, g.id, canEdit);
      setDetail((d) => (d ? { ...d, shared_groups: [...(d.shared_groups ?? []), { id: g.id, name: g.name }] } : d));
      setNote(`Shared with ${g.name}.`);
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : "Sign in to share a live bank.");
    }
  }

  async function removeUser(id: string) {
    try {
      await unshareBankUser(bank.id, id);
    } catch { /* demo */ }
    setDetail((d) => (d ? { ...d, shared_users: (d.shared_users ?? []).filter((u) => u.id !== id) } : d));
  }

  async function removeGroup(id: string) {
    try {
      await unshareBankGroup(bank.id, id);
    } catch { /* demo */ }
    setDetail((d) => (d ? { ...d, shared_groups: (d.shared_groups ?? []).filter((g) => g.id !== id) } : d));
  }

  async function makeGroup() {
    if (!newGroup.trim()) return;
    try {
      const g = await createGroup(newGroup.trim());
      setGroups((prev) => [g, ...prev]);
      setNewGroup("");
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : "Sign in to create a live group.");
    }
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center p-4">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <motion.div
        initial={{ opacity: 0, y: 16, scale: 0.98 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        className="glass ring-gradient relative z-10 w-full max-w-lg rounded-2xl p-6"
      >
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-lg font-semibold tracking-tight">Share “{bank.name}”</h2>
            <p className="mt-1 text-xs text-faint">
              {bank.visibility === "org_subtree" ? "Visible to the owning org subtree" : "Restricted"} · shares add access on top.
            </p>
          </div>
          <button onClick={onClose} className="grid h-8 w-8 place-items-center rounded-lg text-faint hover:text-ink hover:bg-white/5">
            <X className="h-4 w-4" />
          </button>
        </div>

        {((detail?.shared_users?.length ?? 0) + (detail?.shared_groups?.length ?? 0)) > 0 && (
          <div className="mt-4 rounded-xl border border-line p-3">
            <p className="mb-2 text-xs uppercase tracking-wide text-faint">Current access</p>
            <div className="flex flex-wrap gap-1.5">
              {detail?.shared_users?.map((u) => (
                <span key={u.id} className="inline-flex items-center gap-1.5 rounded-full bg-sky-500/10 px-2.5 py-1 text-xs text-sky-700 dark:text-sky-200">
                  <Mail className="h-3 w-3" /> {u.full_name}
                  <button onClick={() => removeUser(u.id)} className="opacity-70 hover:opacity-100"><Trash2 className="h-3 w-3" /></button>
                </span>
              ))}
              {detail?.shared_groups?.map((g) => (
                <span key={g.id} className="inline-flex items-center gap-1.5 rounded-full bg-violet-500/10 px-2.5 py-1 text-xs text-violet-700 dark:text-violet-200">
                  <Users className="h-3 w-3" /> {g.name}
                  <button onClick={() => removeGroup(g.id)} className="opacity-70 hover:opacity-100"><Trash2 className="h-3 w-3" /></button>
                </span>
              ))}
            </div>
          </div>
        )}

        <div className="mt-5 flex items-center justify-between">
          <Segmented
            value={tab}
            onChange={(t) => setTab(t as typeof tab)}
            options={[{ value: "people", label: "People" }, { value: "groups", label: "Groups" }]}
          />
          <label className="flex items-center gap-2 text-xs text-muted">
            <input type="checkbox" checked={canEdit} onChange={(e) => setCanEdit(e.target.checked)} className="accent-violet-500" />
            grant edit
          </label>
        </div>

        <div className="mt-4 max-h-72 space-y-2 overflow-y-auto pr-1">
          {tab === "people"
            ? users.map((u) => {
                const shared = sharedUserIds.has(u.id);
                return (
                  <Row key={u.id} icon={Mail} title={u.full_name} subtitle={u.email} shared={shared} onAdd={() => addUser(u)} />
                );
              })
            : (
              <>
                <div className="flex gap-2">
                  <input
                    value={newGroup}
                    onChange={(e) => setNewGroup(e.target.value)}
                    placeholder="New staff group…"
                    className="h-10 flex-1 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50"
                  />
                  <Button variant="glass" size="sm" onClick={makeGroup}><Plus className="h-4 w-4" /> Group</Button>
                </div>
                {groups.map((g) => {
                  const shared = sharedGroupIds.has(g.id);
                  return (
                    <Row key={g.id} icon={Users} title={g.name} subtitle={`${g.members_count ?? 0} members`} shared={shared} onAdd={() => addGroup(g)} />
                  );
                })}
              </>
            )}
        </div>

        {note && <p className="mt-4 rounded-lg bg-emerald-500/10 px-3 py-2 text-xs text-emerald-700 dark:text-emerald-200">{note}</p>}
        {!live && <p className="mt-3 text-center text-xs text-faint">Demo mode — sign in to share live banks.</p>}
      </motion.div>
    </div>
  );
}

function Row({
  icon: Icon, title, subtitle, shared, onAdd,
}: {
  icon: React.ElementType; title: string; subtitle: string; shared: boolean; onAdd: () => void;
}) {
  return (
    <div className="glass flex items-center gap-3 rounded-xl px-3 py-2.5">
      <div className="grid h-9 w-9 place-items-center rounded-lg bg-gradient-to-br from-violet-500/25 to-transparent text-violet-700 dark:text-violet-200">
        <Icon className="h-4 w-4" />
      </div>
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-medium">{title}</div>
        <div className="truncate text-xs text-faint">{subtitle}</div>
      </div>
      {shared ? (
        <Badge tone="emerald"><Check className="h-3 w-3" /> Shared</Badge>
      ) : (
        <button onClick={onAdd} className={cn("inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs", "text-violet-600 dark:text-violet-300 hover:bg-violet-500/10")}>
          <UserPlus className="h-3.5 w-3.5" /> Share
        </button>
      )}
    </div>
  );
}
