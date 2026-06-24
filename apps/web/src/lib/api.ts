/**
 * Thin client for the Legion CBT Laravel API.
 *
 * Auth is a bearer token (IAM module) kept in localStorage. The base URL points at the
 * Laravel app; set NEXT_PUBLIC_API_URL to override. Every helper returns parsed JSON and
 * throws an ApiError with the server's message on non-2xx.
 */
const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const TOKEN_KEY = "legion.token";

export class ApiError extends Error {
  constructor(public status: number, message: string, public body?: unknown) {
    super(message);
  }
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}
export function setToken(token: string | null) {
  if (typeof window === "undefined") return;
  if (token) window.localStorage.setItem(TOKEN_KEY, token);
  else window.localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers["Content-Type"] = "application/json";

  const res = await fetch(`${BASE}/api${path}`, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const text = await res.text();
  const data = text ? safeJson(text) : null;
  if (!res.ok) {
    const message =
      (data && typeof data === "object" && "message" in data && (data as { message: string }).message) ||
      `Request failed (${res.status})`;
    throw new ApiError(res.status, message as string, data);
  }
  return data as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

export const api = {
  get: <T>(path: string) => request<T>("GET", path),
  post: <T>(path: string, body?: unknown) => request<T>("POST", path, body ?? {}),
  del: <T>(path: string) => request<T>("DELETE", path),
};

/* ── Domain helpers ── */

export type LoginResult =
  | { status: "authenticated"; token: string; user: { id: string; email: string; full_name?: string } }
  | { status: "mfa_required"; challenge: string };

export async function login(email: string, password: string): Promise<LoginResult> {
  const res = await api.post<LoginResult>("/auth/login", { email, password });
  if (res.status === "authenticated") setToken(res.token);
  return res;
}

export async function verifyCertificate(token: string) {
  // Public endpoint — no auth required.
  const res = await fetch(`${BASE}/api/certification/verify/${encodeURIComponent(token)}`, {
    headers: { Accept: "application/json" },
  });
  return { ok: res.ok, data: (await res.json()) as CertificateVerification };
}

export type CertificateVerification = {
  valid: boolean;
  reason?: string;
  serial?: string;
  issued_at?: string;
  anchored?: boolean;
  payload?: {
    candidate?: { name?: string };
    assessment?: { title?: string; kind?: string };
    result?: { raw_score?: number; scaled_score?: number | null };
  };
};

export function logout() {
  setToken(null);
}

/* ── Exam delivery helpers ── */

export type ExamQuestion = {
  position: number;
  item_version_id: string;
  type: string;
  stem: string | null;
  options: { index: number; text: string | null }[];
};
export type SittingState = {
  sitting: { id: string; status: string; server_deadline_epoch: number | null };
  remaining_seconds: number | null;
  answers?: Record<string, { selected?: number[]; text?: string; value?: string }>;
  questions: ExamQuestion[];
};

export const resumeSitting = (id: string) => api.post<SittingState>(`/delivery/sittings/${id}/resume`);
export const showSitting = (id: string) => api.get<SittingState>(`/delivery/sittings/${id}`);
export const recordResponse = (id: string, itemVersionId: string, answer: unknown) =>
  api.post(`/delivery/sittings/${id}/responses`, { item_version_id: itemVersionId, answer });
export const submitSitting = (id: string) =>
  api.post<{ status: string; score: { raw_score: number; status: string } }>(`/delivery/sittings/${id}/submit`);
export const extendSitting = (id: string, minutes: number, reason?: string) =>
  api.post<{ server_deadline_epoch: number | null; remaining_seconds: number | null }>(`/delivery/sittings/${id}/extend`, { minutes, reason });

export type MonitorRow = {
  id: string;
  candidate: string | null;
  status: string;
  server_deadline_epoch: number | null;
  remaining_seconds: number | null;
  answered: number;
  total: number;
  risk: { id: string; session_id: string; cheating_probability: number; status: string } | null;
};
export const monitorAssessment = (assessmentId: string) =>
  api.get<{ assessment: { id: string; title: string; status: string; kind: string }; sittings: MonitorRow[] }>(`/delivery/assessments/${assessmentId}/monitor`);

export type SittingDetail = {
  id: string;
  candidate: string | null;
  status: string;
  answered: number;
  total: number;
  remaining_seconds: number | null;
  session: { mode: string; lockdown_active: boolean } | null;
  flags: { type: string; confidence: number; occurred_at: string; source: string }[];
  risk: { id: string; cheating_probability: number; suspicion_score: number; status: string; timeline: { type: string; contribution: number; combined_confidence: number; occurrences: number }[] } | null;
};
export const sittingDetail = (id: string) => api.get<SittingDetail>(`/delivery/sittings/${id}/detail`);

export type AssessmentRow = { id: string; title: string; kind: string; status: string };
export const listAssessments = () => api.get<Paginated<AssessmentRow>>("/authoring/assessments");

/* ── Analytics & psychometrics ── */

export type Reliability = { assessment_id: string; kr20: number; cronbach_alpha: number; sem: number };
export type DistractorOption = { key: string; count: number; share: number; is_key: boolean };
export type ItemStat = {
  item_id: string;
  type: string;
  bloom_level: string | null;
  stem: string;
  sample_n: number | null;
  facility_index: number | null;
  discrimination_index: number | null;
  distractor_analysis: DistractorOption[] | Record<string, unknown> | null;
};
export const getReliability = (assessmentId: string) =>
  api.get<Reliability>(`/analytics/assessments/${assessmentId}/reliability`);
export const getAssessmentItemStats = (assessmentId: string) =>
  api.get<Paginated<ItemStat>>(`/analytics/assessments/${assessmentId}/items`);
export const compileAnalytics = (assessmentId: string) =>
  api.post<Reliability>(`/analytics/assessments/${assessmentId}/compile`, {});

/* ── Grading helpers ── */

export type GradingTaskRow = { id: string; type: string; status: string; final_mark?: number | null };
export type GradingMark = { grader_id: string | null; mark: number; is_ai: boolean };
export type GradingTaskDetail = {
  task: { id: string; type: string; status: string; final_mark: number | null };
  question: string | null;
  answer: string;
  marks: GradingMark[];
};

export const listGradingTasks = () => api.get<Paginated<GradingTaskRow>>("/delivery/grading/tasks");
export const getGradingTask = (id: string) => api.get<GradingTaskDetail>(`/delivery/grading/tasks/${id}`);
export const aiSuggestGrade = (id: string, maxMark: number) =>
  api.post<{ mark: number; rationale: string; advisory: boolean }>(`/delivery/grading/tasks/${id}/ai-suggest`, { max_mark: maxMark });
export const submitGradeMark = (id: string, mark: number) =>
  api.post<{ status: string; final_mark: number | null }>(`/delivery/grading/tasks/${id}/marks`, { mark });
export const reconcileGrade = (id: string, finalMark: number) =>
  api.post<{ status: string; final_mark: number | null }>(`/delivery/grading/tasks/${id}/reconcile`, { final_mark: finalMark });

/* ── Proctoring helpers ── */

export type RiskTimelineEntry = {
  type: string;
  weight: number;
  combined_confidence: number;
  occurrences: number;
  contribution: number;
  flag_ids?: string[];
};
export type RiskRow = {
  id: string;
  cheating_probability: number;
  suspicion_score: number;
  status: string;
  timeline: RiskTimelineEntry[];
  session?: { sitting?: { candidate?: { full_name?: string } } };
};

export const listReviewQueue = (threshold = 0.5) =>
  api.get<Paginated<RiskRow>>(`/proctoring/review-queue?threshold=${threshold}`);
export const reviewRisk = (riskId: string, decision: "cleared" | "upheld") =>
  api.post<{ status: string }>(`/proctoring/risk/${riskId}/review`, { decision });

/* ── Authoring helpers ── */

export type Bank = {
  id: string;
  name: string;
  visibility: "org_subtree" | "restricted";
  items_count?: number;
  shared_users_count?: number;
  shared_groups_count?: number;
  owner_org_node?: { id: string; name: string; type: string } | null;
};

export type OrgNode = { id: string; name: string; type: string; parent_id: string | null };

export type StaffUser = { id: string; full_name: string; email: string };
export type StaffGroup = { id: string; name: string; members_count?: number };
export type BankDetail = Bank & {
  shared_users?: StaffUser[];
  shared_groups?: { id: string; name: string }[];
};

export const listBanks = () => api.get<Bank[]>("/question-bank/banks");
export const getBank = (id: string) => api.get<BankDetail>(`/question-bank/banks/${id}`);
export const listOrgNodes = (type?: string) =>
  api.get<OrgNode[]>(`/tenancy/org-nodes${type ? `?type=${type}` : ""}`);
export const listUsers = () => api.get<StaffUser[]>("/iam/users");

/* ── Scheduling & timetabling ── */

export type Venue = { id: string; name: string; code: string | null; location: string | null; capacity: number; status: string };
export type Selection = { scope: "all" | "nodes"; org_node_ids?: string[]; levels?: string[] };
export type SelectionGroup = { programme: string; programme_id: string; level: string; count: number };
export type SelectionSummary = { total: number; groups: SelectionGroup[] };
export type SessionInvigilator = { id: string; name: string; role: string };
export type ExamSession = {
  id: string; name: string | null; venue: string | null;
  starts_at: string; ends_at: string; capacity: number; seated: number; status: string;
  invigilators: SessionInvigilator[];
};
export type SessionsView = { assessment: { id: string; title: string; status: string }; sessions: ExamSession[] };
export type RosterRow = { id: string; candidate: string; email: string; session: string | null; session_id: string; seat_no: number | null; source: string; status: string };
export type AutoMapVenue = { venue_id: string; capacity?: number };
export type AutoMapResult = {
  sessions_created: number; candidates_total: number; scheduled: number; unscheduled: number; total_capacity: number;
  per_session: { session_id: string; name: string | null; seated: number; capacity: number }[];
};

export const listVenues = () => api.get<{ data: Venue[] }>("/scheduling/venues").then((r) => r.data);
export const createVenue = (v: { name: string; code?: string; location?: string; capacity: number }) =>
  api.post<Venue>("/scheduling/venues", v);
export const previewSelection = (s: Selection) => api.post<SelectionSummary>("/scheduling/selection/preview", s);
export const listSessions = (assessmentId: string) => api.get<SessionsView>(`/scheduling/assessments/${assessmentId}/sessions`);
export const createSession = (assessmentId: string, body: { venue_id?: string; name?: string; starts_at: string; duration_minutes: number; capacity?: number }) =>
  api.post<ExamSession>(`/scheduling/assessments/${assessmentId}/sessions`, body);
export const autoMap = (assessmentId: string, body: { selection: Selection; venues: AutoMapVenue[]; start_times: string[]; duration_minutes: number }) =>
  api.post<AutoMapResult>(`/scheduling/assessments/${assessmentId}/auto-map`, body);
export const getRoster = (assessmentId: string) => api.get<{ data: RosterRow[] }>(`/scheduling/assessments/${assessmentId}/roster`).then((r) => r.data);
export const releaseSchedule = (assessmentId: string) => api.post<{ released: number }>(`/scheduling/assessments/${assessmentId}/release`, {});
export const assignSessionCandidates = (sessionId: string, candidateIds: string[]) =>
  api.post<{ scheduled: number }>(`/scheduling/sessions/${sessionId}/candidates`, { candidate_ids: candidateIds });
export const assignSessionInvigilators = (sessionId: string, invigilators: { user_id: string; role?: string }[]) =>
  api.post<{ ok: boolean }>(`/scheduling/sessions/${sessionId}/invigilators`, { invigilators });
export const listGroups = () => api.get<StaffGroup[]>("/question-bank/groups");

export function createBank(name: string, ownerOrgNodeId: string | null, visibility: string) {
  return api.post<Bank>("/question-bank/banks", { name, owner_org_node_id: ownerOrgNodeId, visibility });
}

export const shareBankUser = (bankId: string, userId: string, canEdit: boolean) =>
  api.post(`/question-bank/banks/${bankId}/share-user`, { user_id: userId, can_edit: canEdit });
export const shareBankGroup = (bankId: string, groupId: string, canEdit: boolean) =>
  api.post(`/question-bank/banks/${bankId}/share-group`, { group_id: groupId, can_edit: canEdit });
export const unshareBankUser = (bankId: string, userId: string) =>
  api.del(`/question-bank/banks/${bankId}/share-user/${userId}`);
export const unshareBankGroup = (bankId: string, groupId: string) =>
  api.del(`/question-bank/banks/${bankId}/share-group/${groupId}`);
export const createGroup = (name: string) => api.post<StaffGroup>("/question-bank/groups", { name });

export type ItemRow = {
  id: string;
  type: string;
  status: string;
  difficulty?: number | null;
  tags?: string[];
  question_bank_id?: string | null;
  course_org_node_id?: string | null;
  specialization_org_node_id?: string | null;
  question_bank?: { id: string; name: string } | null;
  course?: { id: string; name: string } | null;
  specialization?: { id: string; name: string } | null;
  current_version?: { id?: string; state?: string; content?: { stem?: string } } | null;
};
export type Paginated<T> = { data: T[]; total: number };

export const reviewVersion = (versionId: string, decision: "approve" | "reject" | "revise", comment?: string) =>
  api.post<{ version_state: string }>(`/question-bank/versions/${versionId}/reviews`, { decision, comment });

export function listItems(params: Record<string, string> = {}) {
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString();
  return api.get<Paginated<ItemRow>>(`/question-bank/items${qs ? `?${qs}` : ""}`);
}

export type ComposeItemInput = {
  type: string;
  stem: string;
  options: { key: string; text: string }[];
  correct: string[];
  questionBankId?: string | null;
  courseOrgNodeId?: string | null;
  specializationOrgNodeId?: string | null;
  tags?: string[];
  difficultyBand?: string;
  bloom?: number;
};

function itemBody(input: ComposeItemInput) {
  const optionMap: Record<string, string> = {};
  input.options.forEach((o) => (optionMap[o.key] = o.text));
  return {
    type: input.type,
    question_bank_id: input.questionBankId ?? null,
    course_org_node_id: input.courseOrgNodeId ?? null,
    specialization_org_node_id: input.specializationOrgNodeId ?? null,
    tags: input.tags ?? [],
    content: { stem: input.stem, options: optionMap },
    answer: input.correct.length ? { correct: input.correct } : null,
    metadata: { bloom_level: input.bloom },
  };
}

/** Create a question bank item. Correctness goes in `answer`, never in `content`. */
export function createItem(input: ComposeItemInput) {
  return api.post<{ id: string }>("/question-bank/items", itemBody(input));
}

/** Modify a question — creates a new immutable version + refreshes its classification. */
export function updateItem(id: string, input: ComposeItemInput) {
  return api.post<{ id: string }>(`/question-bank/items/${id}/versions`, itemBody(input));
}

export type ItemDetail = {
  id: string;
  type: string;
  question_bank_id: string | null;
  course_org_node_id: string | null;
  specialization_org_node_id: string | null;
  tags?: string[];
  current_version?: { id?: string; state?: string; content?: { stem?: string; options?: Record<string, string> } } | null;
  answer?: { correct?: string[]; accept?: string[] } | null;
};
export const getItem = (id: string) => api.get<ItemDetail>(`/question-bank/items/${id}`);

export const pinSectionItems = (assessmentId: string, sectionId: string, itemVersionIds: string[]) =>
  api.post(`/authoring/assessments/${assessmentId}/sections/${sectionId}/items`, { item_version_ids: itemVersionIds });

export type ImportSummary = {
  created: number;
  duplicates: number;
  errors: number;
  results: { index: number; status: string; message?: string }[];
};

export function importQuestions(format: string, body: string) {
  return api.post<ImportSummary>("/question-bank/items/import", { format, body });
}

export type BuildAssessmentInput = {
  title: string;
  kind: string;
  durationMinutes: number;
  total: number;
  difficulty: { easy: number; medium: number; hard: number };
  bankIds?: string[];
  courseOrgNodeId?: string | null;
  specializationOrgNodeId?: string | null;
  tags?: string[];
};

/**
 * Orchestrate the full authoring chain the backend exposes:
 * scoring rule -> blueprint -> assessment -> section -> assemble -> publish.
 */
export async function buildAndPublishAssessment(input: BuildAssessmentInput) {
  const rule = await api.post<{ id: string }>("/authoring/scoring-rules", {
    name: `Rule ${Date.now()}`,
    policy: { correct: 1, wrong: 0, blank: 0 },
  });
  const constraints: Record<string, unknown> = {
    total: input.total,
    types: ["single", "multiple"],
    difficulty: {
      easy: input.difficulty.easy / 100,
      medium: input.difficulty.medium / 100,
      hard: input.difficulty.hard / 100,
    },
  };
  if (input.bankIds?.length) constraints.bank_ids = input.bankIds;
  if (input.courseOrgNodeId) constraints.course_org_node_id = input.courseOrgNodeId;
  if (input.specializationOrgNodeId) constraints.specialization_org_node_id = input.specializationOrgNodeId;
  if (input.tags?.length) constraints.tags = input.tags;

  const blueprint = await api.post<{ id: string }>("/authoring/blueprints", {
    name: `${input.title} blueprint`,
    constraints,
  });
  const assessment = await api.post<{ id: string }>("/authoring/assessments", {
    title: input.title,
    kind: input.kind,
    duration_seconds: input.durationMinutes * 60,
    scoring_rule_id: rule.id,
    blueprint_id: blueprint.id,
  });
  const section = await api.post<{ id: string }>(`/authoring/assessments/${assessment.id}/sections`, {
    title: "Section A",
  });
  await api.post(`/authoring/assessments/${assessment.id}/sections/${section.id}/assemble`, {
    blueprint_id: blueprint.id,
  });
  await api.post(`/authoring/assessments/${assessment.id}/publish`);
  return assessment;
}

/** Build & publish from an explicitly-curated set of questions (manual selection). */
export async function buildWithSelectedQuestions(input: {
  title: string;
  kind: string;
  durationMinutes: number;
  itemVersionIds: string[];
}) {
  const rule = await api.post<{ id: string }>("/authoring/scoring-rules", {
    name: `Rule ${Date.now()}`,
    policy: { correct: 1, wrong: 0, blank: 0 },
  });
  const assessment = await api.post<{ id: string }>("/authoring/assessments", {
    title: input.title,
    kind: input.kind,
    duration_seconds: input.durationMinutes * 60,
    scoring_rule_id: rule.id,
  });
  const section = await api.post<{ id: string }>(`/authoring/assessments/${assessment.id}/sections`, { title: "Section A" });
  await pinSectionItems(assessment.id, section.id, input.itemVersionIds);
  await api.post(`/authoring/assessments/${assessment.id}/publish`);
  return assessment;
}
