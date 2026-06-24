/**
 * Demo data shaped like the Legion API responses, so the UI is beautiful and explorable
 * without a running backend. Login and certificate verification hit the real API; the
 * dashboard/list views read from here until wired to live endpoints.
 */

export const demoStats = {
  candidates: "1,284",
  assessments: "37",
  passRate: "82%",
  flagged: "6",
};

export type DemoAssessment = {
  id: string;
  title: string;
  kind: string;
  status: "draft" | "published" | "live" | "closed";
  candidates: number;
  items: number;
  kr20: number | null;
};

export const demoAssessments: DemoAssessment[] = [
  { id: "csc101-final", title: "CSC101 Final Examination", kind: "final", status: "live", candidates: 312, items: 60, kr20: 0.84 },
  { id: "post-utme-2026", title: "Post-UTME Screening 2026", kind: "postutme", status: "published", candidates: 940, items: 100, kr20: null },
  { id: "med-board-a", title: "Medical Board — Part A", kind: "licensing", status: "closed", candidates: 128, items: 150, kr20: 0.91 },
  { id: "mid-bio", title: "Biology Mid-Semester", kind: "midterm", status: "draft", candidates: 0, items: 0, kr20: null },
  { id: "civil-eng-cert", title: "Civil Engineering Certification", kind: "certification", status: "closed", candidates: 76, items: 80, kr20: 0.79 },
];

/** A score-distribution sparkline (percentage buckets). */
export const demoDistribution = [4, 9, 14, 22, 31, 44, 58, 71, 63, 48, 33, 19, 8];

export const demoRisk = [
  { candidate: "Candidate 1", probability: 0.88, signal: "phone_detected", status: "auto" },
  { candidate: "Candidate 7", probability: 0.71, signal: "multiple_faces", status: "reviewed" },
  { candidate: "Candidate 12", probability: 0.64, signal: "vm_detected", status: "auto" },
];

export type DemoBank = {
  id: string;
  name: string;
  visibility: "org_subtree" | "restricted";
  items_count: number;
  owner_org_node?: { id: string; name: string; type: string } | null;
};

export const demoBanks: DemoBank[] = [
  { id: "bank-csc", name: "CSC101 Question Bank", visibility: "org_subtree", items_count: 84, owner_org_node: { id: "n1", name: "Computer Science", type: "department" } },
  { id: "bank-med", name: "Medical Board — Part A", visibility: "restricted", items_count: 312, owner_org_node: { id: "n2", name: "Medicine", type: "faculty" } },
  { id: "bank-eng", name: "Civil Engineering Pool", visibility: "org_subtree", items_count: 156, owner_org_node: { id: "n3", name: "Civil Engineering", type: "department" } },
  { id: "bank-shared", name: "Shared Numeracy Set", visibility: "restricted", items_count: 40, owner_org_node: null },
];

export const demoOrgNodes = [
  { id: "course-csc101", name: "CSC101 — Intro to Computing", type: "course", parent_id: null },
  { id: "course-csc201", name: "CSC201 — Data Structures", type: "course", parent_id: null },
  { id: "spec-systems", name: "Systems & Architecture", type: "specialization", parent_id: null },
  { id: "spec-ai", name: "AI & Data", type: "specialization", parent_id: null },
];

export const demoTags = ["fundamentals", "memory", "concepts", "algorithms", "sql", "networking"];

export const demoUsers = [
  { id: "u-officer", full_name: "Exam Officer", email: "officer@demo.legion.test" },
  { id: "u-author", full_name: "Question Author", email: "author@demo.legion.test" },
  { id: "u-reviewer", full_name: "Question Reviewer", email: "reviewer@demo.legion.test" },
  { id: "u-grader", full_name: "Grader One", email: "grader1@demo.legion.test" },
];

export const demoGroups = [
  { id: "g-examiners", name: "CSC Examiners", members_count: 3 },
  { id: "g-moderators", name: "Faculty Moderators", members_count: 5 },
];

export type DemoItem = {
  stem: string;
  type: string;
  band: "easy" | "medium" | "hard";
  bloom: number;
  state: "approved" | "reviewed" | "draft";
};

export const demoItems: DemoItem[] = [
  { stem: "Which component is volatile memory?", type: "single", band: "easy", bloom: 1, state: "approved" },
  { stem: "What does the SQL keyword SELECT do?", type: "single", band: "easy", bloom: 2, state: "approved" },
  { stem: "Select all prime numbers below 10.", type: "multiple", band: "medium", bloom: 3, state: "approved" },
  { stem: "Big-O of binary search on a sorted array?", type: "single", band: "medium", bloom: 3, state: "reviewed" },
  { stem: "Explain the difference between RAM and ROM.", type: "essay", band: "hard", bloom: 4, state: "approved" },
  { stem: "Normalize this relation to 3NF.", type: "essay", band: "hard", bloom: 5, state: "draft" },
  { stem: "TCP is connection-oriented. True or false?", type: "true_false", band: "easy", bloom: 1, state: "approved" },
];

export const demoMonitor = {
  assessment: { id: "csc101-final", title: "CSC101 Final Examination", status: "live", kind: "final" },
  sittings: [
    { id: "s1", candidate: "Candidate 1", status: "in_progress", server_deadline_epoch: null, remaining_seconds: 1840, answered: 42, total: 60, risk: { cheating_probability: 0.88, status: "auto" } },
    { id: "s2", candidate: "Candidate 2", status: "in_progress", server_deadline_epoch: null, remaining_seconds: 2510, answered: 51, total: 60, risk: null },
    { id: "s3", candidate: "Candidate 3", status: "in_progress", server_deadline_epoch: null, remaining_seconds: 360, answered: 28, total: 60, risk: { cheating_probability: 0.41, status: "auto" } },
    { id: "s4", candidate: "Candidate 4", status: "submitted", server_deadline_epoch: null, remaining_seconds: 0, answered: 60, total: 60, risk: null },
    { id: "s5", candidate: "Candidate 5", status: "in_progress", server_deadline_epoch: null, remaining_seconds: 1200, answered: 12, total: 60, risk: { cheating_probability: 0.64, status: "reviewed" } },
  ],
};

export const demoReviewQueue = [
  {
    id: "risk-1", cheating_probability: 0.8776, suspicion_score: 1.31, status: "auto",
    session: { sitting: { candidate: { full_name: "Candidate 1" } } },
    timeline: [
      { type: "phone_detected", weight: 0.8, combined_confidence: 0.95, occurrences: 1, contribution: 0.76 },
      { type: "face_absent", weight: 0.5, combined_confidence: 0.8, occurrences: 2, contribution: 0.4 },
      { type: "tab_switch", weight: 0.15, combined_confidence: 1.0, occurrences: 3, contribution: 0.15 },
    ],
  },
  {
    id: "risk-2", cheating_probability: 0.71, suspicion_score: 0.92, status: "auto",
    session: { sitting: { candidate: { full_name: "Candidate 7" } } },
    timeline: [
      { type: "multiple_faces", weight: 0.75, combined_confidence: 0.82, occurrences: 1, contribution: 0.62 },
      { type: "voice_detected", weight: 0.4, combined_confidence: 0.6, occurrences: 2, contribution: 0.24 },
    ],
  },
  {
    id: "risk-3", cheating_probability: 0.64, suspicion_score: 0.7, status: "auto",
    session: { sitting: { candidate: { full_name: "Candidate 12" } } },
    timeline: [
      { type: "vm_detected", weight: 0.85, combined_confidence: 0.75, occurrences: 1, contribution: 0.64 },
    ],
  },
];

export const demoGradingTasks = [
  { id: "gt-1", type: "essay", status: "pending", final_mark: null },
  { id: "gt-2", type: "essay", status: "double_marking", final_mark: null },
  { id: "gt-3", type: "short_answer", status: "pending", final_mark: null },
];

export const demoGradingDetails: Record<string, {
  task: { id: string; type: string; status: string; final_mark: number | null };
  question: string;
  answer: string;
  marks: { grader_id: string | null; mark: number; is_ai: boolean }[];
}> = {
  "gt-1": {
    task: { id: "gt-1", type: "essay", status: "pending", final_mark: null },
    question: "Explain, in your own words, the difference between RAM and ROM.",
    answer: "RAM is volatile working memory that loses its contents on power-off, while ROM is non-volatile and retains firmware permanently. RAM is read-write and fast; ROM is mostly read-only.",
    marks: [],
  },
  "gt-2": {
    task: { id: "gt-2", type: "essay", status: "double_marking", final_mark: null },
    question: "Discuss the trade-offs of normalization in relational databases.",
    answer: "Normalization reduces redundancy and update anomalies but can require more joins, hurting read performance; denormalization trades storage and integrity risk for speed.",
    marks: [{ grader_id: "u-author", mark: 6, is_ai: false }, { grader_id: "u-reviewer", mark: 9, is_ai: false }],
  },
  "gt-3": {
    task: { id: "gt-3", type: "short_answer", status: "pending", final_mark: null },
    question: "Name the layer of the OSI model responsible for routing.",
    answer: "The network layer (layer 3).",
    marks: [],
  },
};

export const demoActivity = [
  { who: "Exam Officer", what: "published CSC101 Final Examination", when: "2m ago" },
  { who: "Grader Two", what: "reconciled 14 essay scripts", when: "26m ago" },
  { who: "Proctor", what: "flagged Candidate 1 — phone detected", when: "1h ago" },
  { who: "System", what: "issued 312 certificates", when: "3h ago" },
];

/* ── Scheduling & timetabling ── */

export const demoFaculties = [
  { id: "fac-eng", name: "Engineering", type: "faculty", parent_id: null },
  { id: "dep-comp", name: "Computing", type: "department", parent_id: "fac-eng" },
  { id: "prog-csc", name: "Computer Science", type: "programme", parent_id: "dep-comp" },
  { id: "prog-se", name: "Software Engineering", type: "programme", parent_id: "dep-comp" },
  { id: "fac-med", name: "Medicine", type: "faculty", parent_id: null },
  { id: "dep-clin", name: "Clinical Sciences", type: "department", parent_id: "fac-med" },
  { id: "prog-mbbs", name: "MBBS", type: "programme", parent_id: "dep-clin" },
];

export const demoLevels = ["100", "200", "300", "400"];

export const demoVenues = [
  { id: "v-a", name: "Main Hall A", code: "MHA", location: "Block A", capacity: 250, status: "active" },
  { id: "v-b", name: "Computer Lab B", code: "CLB", location: "ICT Wing", capacity: 80, status: "active" },
  { id: "v-c", name: "Lecture Theatre C", code: "LTC", location: "Block C", capacity: 120, status: "active" },
];

export const demoSelectionSummary = {
  total: 312,
  groups: [
    { programme: "Computer Science", programme_id: "prog-csc", level: "100", count: 96 },
    { programme: "Computer Science", programme_id: "prog-csc", level: "200", count: 84 },
    { programme: "Software Engineering", programme_id: "prog-se", level: "100", count: 78 },
    { programme: "Software Engineering", programme_id: "prog-se", level: "200", count: 54 },
  ],
};

export const demoSessions = {
  assessment: { id: "csc101-final", title: "CSC101 Final Examination", status: "published" },
  sessions: [
    { id: "s-1", name: "Main Hall A · 09:00", venue: "Main Hall A", starts_at: "2026-07-01T09:00:00Z", ends_at: "2026-07-01T10:00:00Z", capacity: 250, seated: 250, status: "scheduled", invigilators: [{ id: "u-1", name: "Dr. Bello", role: "chief" }, { id: "u-2", name: "Mr. Okon", role: "assistant" }] },
    { id: "s-2", name: "Computer Lab B · 09:00", venue: "Computer Lab B", starts_at: "2026-07-01T09:00:00Z", ends_at: "2026-07-01T10:00:00Z", capacity: 80, seated: 62, status: "scheduled", invigilators: [{ id: "u-3", name: "Mrs. Ade", role: "chief" }] },
    { id: "s-3", name: "Main Hall A · 11:00", venue: "Main Hall A", starts_at: "2026-07-01T11:00:00Z", ends_at: "2026-07-01T12:00:00Z", capacity: 250, seated: 0, status: "scheduled", invigilators: [] },
  ],
};

export const demoRoster = [
  { id: "r-1", candidate: "Candidate 0001", email: "0001@demo.test", session: "Main Hall A · 09:00", session_id: "s-1", seat_no: 1, source: "auto", status: "scheduled" },
  { id: "r-2", candidate: "Candidate 0002", email: "0002@demo.test", session: "Main Hall A · 09:00", session_id: "s-1", seat_no: 2, source: "auto", status: "scheduled" },
  { id: "r-3", candidate: "Candidate 0003", email: "0003@demo.test", session: "Computer Lab B · 09:00", session_id: "s-2", seat_no: 1, source: "auto", status: "released" },
];

/* ── Analytics & psychometrics ── */

export const demoReliability = { assessment_id: "csc101-final", kr20: 0.84, cronbach_alpha: 0.86, sem: 3.12 };

export const demoItemStats = [
  { item_id: "i-1", type: "single", bloom_level: "remember", stem: "What is the time complexity of binary search on a sorted array?", sample_n: 312, facility_index: 0.78, discrimination_index: 0.41,
    distractor_analysis: [ { key: "A", count: 244, share: 0.78, is_key: true }, { key: "B", count: 38, share: 0.12, is_key: false }, { key: "C", count: 18, share: 0.06, is_key: false }, { key: "D", count: 12, share: 0.04, is_key: false } ] },
  { item_id: "i-2", type: "single", bloom_level: "understand", stem: "Which data structure offers O(1) average-case insertion and lookup?", sample_n: 312, facility_index: 0.62, discrimination_index: 0.33,
    distractor_analysis: [ { key: "A", count: 71, share: 0.23, is_key: false }, { key: "B", count: 193, share: 0.62, is_key: true }, { key: "C", count: 31, share: 0.10, is_key: false }, { key: "D", count: 17, share: 0.05, is_key: false } ] },
  { item_id: "i-3", type: "single", bloom_level: "apply", stem: "Given the recurrence T(n)=2T(n/2)+n, what is the asymptotic running time?", sample_n: 312, facility_index: 0.34, discrimination_index: 0.48,
    distractor_analysis: [ { key: "A", count: 106, share: 0.34, is_key: true }, { key: "B", count: 121, share: 0.39, is_key: false }, { key: "C", count: 55, share: 0.18, is_key: false }, { key: "D", count: 30, share: 0.09, is_key: false } ] },
  { item_id: "i-4", type: "multiple", bloom_level: "analyze", stem: "Select all properties guaranteed by a balanced binary search tree.", sample_n: 312, facility_index: 0.51, discrimination_index: 0.07,
    distractor_analysis: [ { key: "A", count: 159, share: 0.51, is_key: true }, { key: "B", count: 88, share: 0.28, is_key: false }, { key: "C", count: 41, share: 0.13, is_key: false }, { key: "D", count: 24, share: 0.08, is_key: false } ] },
  { item_id: "i-5", type: "single", bloom_level: "remember", stem: "What does SQL stand for?", sample_n: 312, facility_index: 0.96, discrimination_index: 0.05,
    distractor_analysis: [ { key: "A", count: 299, share: 0.96, is_key: true }, { key: "B", count: 7, share: 0.02, is_key: false }, { key: "C", count: 4, share: 0.01, is_key: false }, { key: "D", count: 2, share: 0.01, is_key: false } ] },
  { item_id: "i-6", type: "essay", bloom_level: "evaluate", stem: "Discuss the trade-offs between consistency and availability in distributed systems.", sample_n: 312, facility_index: 0.58, discrimination_index: 0.52, distractor_analysis: null },
  { item_id: "i-7", type: "single", bloom_level: "understand", stem: "Which layer of the OSI model is responsible for routing?", sample_n: null, facility_index: null, discrimination_index: null, distractor_analysis: null },
];
