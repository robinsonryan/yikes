/**
 * Shared TypeScript shapes for the yikes frontend.
 *
 * These mirror the note frontmatter schema documented in
 * packages/yikes/docs/SPEC.md — keep the two in sync.
 */

export type YikesNoteType = "bug" | "layout" | "idea" | "refactor";

export type YikesNoteStatus = "new" | "on-hold" | "approved" | "done" | "ignored";

export interface YikesElementContext {
    /** Best-effort unique CSS selector for the picked element. */
    selector: string;
    tag: string;
    /** Trimmed visible text of the element (capped). */
    text: string | null;
}

export interface YikesContext {
    /** Full URL including query string. Null on generic (manually created) notes. */
    url: string | null;
    /** Laravel route name of the page, when resolvable. */
    route: string | null;
    /** Page/component name supplied by a host context provider, or a manually entered area. */
    page: string | null;
    /** The document title at capture time. */
    title?: string | null;
    account: { id: string; name: string } | null;
    department: { id: string; name: string } | null;
    dark_mode: boolean | null;
    viewport: { width: number; height: number } | null;
    user_agent: string | null;
    /** The specific element the note is about (element picker). */
    element?: YikesElementContext | null;
}

export interface YikesResolution {
    commit: string | null;
    note: string | null;
    completed_at: string | null;
}

export interface YikesNote {
    id: string;
    title: string | null;
    /** The user's note text (markdown body below the frontmatter). */
    body: string;
    type: YikesNoteType;
    status: YikesNoteStatus;
    /** ISO8601 */
    created_at: string;
    created_by: { name: string; email: string };
    context: YikesContext;
    /** Attached screenshots with server-generated display URLs. */
    screenshots: { file: string; url: string }[];
    resolution: YikesResolution | null;
}

/*
 * UAT checklist shapes (the /testing surface).
 */

export type ChecklistStatus = "passed" | "failed" | "in-progress" | "pending";

export interface StepResult {
    status: "pass" | "fail";
    reason: string | null;
    note_id: string | null;
    /** ISO8601 */
    recorded_at: string;
}

export interface SuiteSummary {
    status: ChecklistStatus;
    tests: Record<
        string,
        { status: ChecklistStatus; passed: number; failed: number; total: number }
    >;
}

export interface TesterCredential {
    label: string;
    email: string;
    password: string;
    note: string | null;
    /** Key into the roles list (testers.yaml `roles:`) — gates the capability popover. */
    role: string | null;
    /** One-click auto-login URL, when the host app configured a template. */
    login_url: string | null;
}

/** One role's capability summary (testers.yaml `roles:`), shown in RolePopover. */
export interface RoleInfo {
    key: string;
    name: string;
    summary: string | null;
    can: string[];
}
