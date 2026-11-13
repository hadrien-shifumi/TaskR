# TaskR user guide

This guide explains **what TaskR does and how to use it day to day**. It is
written for anyone using the application — no technical knowledge required.

> Looking to install or host TaskR instead? See
> [`installation_EN.md`](installation_EN.md) and [`self-hosting_EN.md`](self-hosting_EN.md).

## Table of contents

1. [TaskR in one page](#taskr-in-one-page)
2. [Account and sign-in](#account-and-sign-in)
3. [Workspaces, members and invitations](#workspaces-members-and-invitations)
4. [Roles and permissions](#roles-and-permissions)
5. [Projects](#projects)
6. [Issues](#issues)
7. [Statuses and workflow](#statuses-and-workflow)
8. [Comments and issue templates](#comments-and-issue-templates)
9. [Time tracking](#time-tracking)
10. [Project health and reporting](#project-health-and-reporting)
11. [Views, search and navigation](#views-search-and-navigation)
12. [Knowledge base](#knowledge-base)
13. [Notifications and mentions](#notifications-and-mentions)
14. [Activity log](#activity-log)
15. [Exports and backup](#exports-and-backup)
16. [Account security](#account-security)
17. [AI agents](#ai-agents)
18. [Installable app (mobile and desktop)](#installable-app-mobile-and-desktop)
19. [Glossary](#glossary)

## TaskR in one page

TaskR is a collaborative work manager: a team organizes its activity into
**projects**, tracks tasks on a **kanban board**, logs **time spent**, tracks
each project's **progress**, and keeps its internal **documentation** in a
base you can search in plain language. Everything can also be driven by
**AI agents**, following exactly the same rules as a human.

Four ideas guide the product:

- **Organize** work into projects, issues and sub-issues, with priorities,
  labels and due dates.
- **Track** issues through a free status workflow, know what's overdue and
  who owns what.
- **Steer** by measuring each project's progress and everyone's workload.
- **Document and delegate**: keep the team's knowledge, and let AI agents act
  on the instance just like a human would.

A few principles that simplify everyday life:

- **Being a member of a workspace is enough to do almost everything**:
  creating and editing projects, issues, labels, documentation pages. Only
  your own comments and time entries are reserved to you.
- **The workflow is free**: no imposed path between statuses, an issue can
  go straight from "to do" to "done".
- **Nothing is frozen**: progress indicators are recalculated every time you
  look at them.
- **Cancelling is not deleting**: a cancelled issue stays visible
  (struck through) and still counts in the project's total; only deleting it
  erases it for good.
- **The interface is available in French and English**, chosen by each
  user.

## Account and sign-in

| Action | How |
|---|---|
| **Create an account** | Public sign-up: name, e-mail, password (8 characters minimum). A workspace is created automatically for you, with four starting statuses (Backlog, In Progress, Done, Cancelled). You're signed in immediately. |
| **Sign in** | E-mail + password, with a "remember me" option. |
| **Change language** | Selector in the sidebar: French / English. The choice is remembered on your account. |
| **Create an API token** | In *Settings → Tokens*: a token lets an AI agent or a script connect as you. Its value is shown **only once** — copy it right away. You can revoke it at any time. |

Not available yet: editing your name or e-mail, deleting your account.

## Workspaces, members and invitations

A **workspace** groups everything you work with: projects, issues,
statuses, labels, templates, documentation. You can belong to as many
workspaces as you like, and switch between them from the sidebar selector.

- **Create a workspace**: available from the same selector. You become its
  owner.
- **Invite someone**: reserved to Owners. An invitation link is generated
  (valid 7 days); it is shared manually, no e-mail is sent automatically.
  The Owner picks the role (Member or Guest, see below) and, optionally, a
  specific project scope.
- **Accept an invitation**: you must be signed in with the exact e-mail
  address the invitation was sent to, otherwise it is refused with an
  explicit message.
- **Remove a member**: reserved to Owners. The person loses access to this
  workspace (their other workspaces are unaffected); a workspace always
  keeps at least one Owner.

## Roles and permissions

TaskR distinguishes three levels: the role within the workspace, the role
within a project, and ownership of an individual object (comment, time
entry).

### Workspace roles

| | Owner | Member | Guest |
|---|---|---|---|
| Create and edit projects, issues, labels, documentation pages | ✅ | ✅ | Only within its scope |
| Comment, log time | ✅ | ✅ | ✅, within its scope |
| Manage its own API tokens, change language | ✅ | ✅ | ❌ |
| Manage members, statuses, issue templates | ✅ | ❌ | ❌ |
| Download the workspace's full backup | ✅ | ❌ | ❌ |

A workspace always keeps at least one Owner, who cannot remove themself.

Two restricted-access profiles exist, for working with people who should
only see part of the workspace:

- **Guest**: typically an external client or contractor. Their scope is the
  projects they're on the team of. They never see the member list, the
  workspace-wide labels, or a project's global indicators (they stay on
  "My workload"). Outside their scope, everything is simply invisible — as
  if it didn't exist.
- **Scoped member**: an internal colleague whose project visibility is
  restricted. Unlike a Guest, they keep every function of a regular member
  (member list, labels, API tokens, cross-project documentation, global
  indicators) — only the list of projects they see is limited. The scope is
  set from the Members page, by the Owner.

### Project roles

Each project has its own team, with three roles independent from workspace
roles: **Owner** (a single one per project), **Manager**, **Member**. Owner
and Manager manage the project's team and see its global health
indicators; a plain project Member only sees their own workload.

Important: a project's team does **not** lock down issues — any workspace
member can create or edit the issues of every project. A project's team is
for steering, not for access control.

### Object ownership

Only comments and time entries are reserved to their author: only you can
edit or delete your own. Everything else (issues, labels, documentation
pages, saved views) can be edited by any member.

## Projects

A project groups issues together. It has a name (required, unique within
the workspace), an optional description, and an optional **target date**
that triggers the expected-progress calculation.

- **Create**: any workspace member can create a project; they automatically
  become its Owner.
- **Archive**: a reversible one-click action — the project disappears from
  the active list but nothing is lost. It stays available from the
  "Archived" dialog, with a button to restore it.
- **Delete**: refused as long as the project contains at least one issue
  (an explicit message suggests archiving it instead). This is a deliberate
  safeguard against accidental deletion.

## Issues

The issue is TaskR's unit of work.

### Create and edit

Only the title is required. You can create an issue from three places: the
Issues page, the dashboard (quick create, title + project), or a project's
board (full form). **The project is fixed at creation**: an issue is never
moved to another project afterwards.

An issue can carry: a Markdown description, a status, a priority (none /
low / high / urgent), labels, one or more assignees, a due date, an hour
estimate, and a parent issue.

### Hierarchy (sub-issues)

An issue can have sub-issues, with unlimited depth, even across a different
project. Cycles are impossible (an issue can never be its own ancestor). By
default, a parent's status and its children's statuses evolve
independently; you can enable, on a parent issue, the **"auto-completion"**
option: when all its active sub-issues reach done or cancelled, the parent
switches automatically.

### Dependencies between issues

An issue can be **blocked by** one or more other issues (even from a
different project). A "blocked" badge shows up while a blocker is open, and
"unblockable" once they're all done. The dependency only warns, it never
prevents anything: you can still move a blocked issue forward if needed. A
"Blocked" filter helps find them on the Issues page.

### Due dates (project milestones)

A project can define **milestones**: steps with a name, a date window and
optionally a hierarchy between them. An issue can be attached to one
milestone of the same project (only one at a time). The milestone's page
shows its progress (how many of its issues are done out of the total).
Managing milestones is reserved to the project's team (Owner, Manager,
Member) — a Guest cannot create or edit one, even as part of the team.

### Deletion and archiving

- **Deleting** an issue permanently destroys its comments, logged time and
  attachments — a confirmation lists what will be lost. Its sub-issues
  aren't deleted: they become root issues.
- **Archiving** a done or cancelled issue (reserved to the workspace Owner)
  removes it from active views (board, list, dashboard) without changing
  anything about its count in the project's progress. A button lets you
  bulk-archive all done/cancelled issues of a project at once. A per-project
  "Archives" view lets you browse and restore them.

## Statuses and workflow

Statuses (the board's columns) are defined **at the workspace level** and
shared by every project: the same board, in the same order, everywhere.
Managing them (Settings → Statuses) is reserved to the Owner.

Each status has a free name and a **semantic category** among four:
*Backlog*, *In Progress*, *Done*, *Cancelled*. It's this category — never
the name — that drives every calculation (progress, overdue, hours). You can
have several statuses in the same category (e.g. "To do" and "Blocked",
both in Backlog).

Transitions are entirely free: an issue can go straight from Backlog to
Done. Moving one is done by dragging and dropping on the board, or through
the status selector on the issue's page.

## Comments and issue templates

**Comments**: a Markdown discussion thread on each issue, sorted oldest
first. Anyone can add one; only the author can edit or delete theirs.

**Issue templates**: a reusable preset to pre-fill recurring issues
(default description, priority and labels — never the title, status, due
date or assignees). Creating/editing a template is reserved to the Owner;
everyone can use one when creating an issue. Editing or deleting a template
never affects issues already created from it.

## Time tracking

Time is tracked at two levels: a forecast **estimate** per issue, and actual
**time entries**.

- **Estimate**: an optional number of hours on each issue. "No estimate" is
  a normal, explicitly shown state.
- **Time entry**: hours (minimum 0.01), work date (today by default, past
  dates are accepted), optional note. Everyone always logs time under their
  own name; only the author can edit or delete their entry.
- **Remaining** = estimate − logged time. If it goes negative (overrun), it
  shows in red — that's reporting information, not an error.
- **Timer**: a "Start timer" button on an issue's card or page tracks your
  time live. You can run several timers in parallel on different issues
  (only one per issue at a time). On stop, a time entry is suggested,
  pre-filled with the elapsed duration.
- **Weekly timesheet** ("My time" page): a week-by-week grid, one issue per
  row, to log your hours day by day in a single pass. Retroactive entry is
  possible up to 8 weeks back.

## Project health and reporting

A project's health is never stored: it is recalculated every time you look
at it, from issues and logged time. It shows up as a summary on the
projects list and the dashboard, and in detail on each project's
**Health** page.

Main indicators:

- **% completion**: share of done issues (cancelled issues count in the
  total but never as done — cancelling an issue therefore lowers
  completion).
- **Overdue issues**: due date in the past and the issue still open.
- **Estimated / logged hours** and **consumption rate**, computed on open
  issues.
- **Projection**: where the project should be today, at a calendar pace
  toward its target date — compared to actual completion, it gives an
  **on track**, **at risk** or **late** status.
- **Evolution chart**: three curves (opened / closed / moved issues), over
  30 days, 90 days or since the project's creation.
- **Team workload**: a per-person table (open issues, hours, overdue),
  visible to the project's responsibles; each row opens a **member activity
  view** (their current issues and their recent history on this project).

A plain project contributor (and a Guest, even as a team manager) only sees
their own workload, never the global indicators or anyone else's.

## Views, search and navigation

- **Issues page**: every issue of every active project, as a filterable
  **list** (search, status, priority, assignee, project, overdue, blocked)
  or as a **cross-project kanban** (one board per project, side by side).
  Filters live in the URL: a filtered view can be shared or bookmarked.
- **Saved views**: from a filtered Issues page, the "Save this view" button
  names it for reuse by everyone through a dedicated picker. The Owner can
  rename or delete any view; anyone can delete their own.
- **A project's board**: one column per status, drag and drop to change
  status, a "by person" filter, and a "Parents only" mode that hides
  sub-issues for an overview.
- **Home dashboard**: your counters (open issues, overdue, hours this week,
  active projects), the health of your active projects, and your most
  recently assigned issues.

## Knowledge base

The knowledge base is the workspace's documentary memory: **Markdown**
pages, organized in a free tree, optionally attached to a project (or
cross-project, i.e. shared across the whole workspace).

- **Semantic search**: the search bar accepts a plain-language question — it
  finds relevant pages even without the exact words. (It requires a search
  provider to be configured on the instance; otherwise, everything else —
  creating, reading, editing — works normally.)
- **Trash**: deleting a page moves it to trash for 30 days (restorable),
  after which it is purged automatically. Deletion is refused as long as the
  page has sub-pages.
- **Versioning**: every save creates a new version. The full history is
  browsable, each version can be previewed or restored (restoring itself
  creates a new version — nothing is ever lost).
- **Link to an issue**: from an issue's page, you can link one or more
  relevant knowledge pages (built-in search). A badge shows how many pages
  are linked to an issue.

## Notifications and mentions

- **Mentions**: in a comment, typing `@` followed by a person's or a
  project's name (which notifies its whole team) triggers an alert. A
  "Mentions" page centralizes received notifications, with a counter and a
  "read" marker.
- **E-mail digest**: a periodic summary (daily or weekly, your choice) sent
  by e-mail, covering your overdue issues, your upcoming due dates, your new
  assignments and new comments on your issues. It is disabled by default —
  enable it from *Settings → Notifications*, where you also choose the
  frequency and the "upcoming" threshold.

## Activity log

Every issue and every project keeps a read-only history of its important
changes (creation, status change, assignment, priority, due date, estimate,
comments, time entries...). It can be browsed as a timeline on an issue's
page, or as a filterable overview (by person, by type) on a project's page.
Nothing in it is ever editable or deletable.

## Exports and backup

- **Export issues** (CSV or JSON) from the Issues page: the export respects
  exactly the filters active on screen.
- **Export time entries** (CSV or JSON) from a project's Health page, over a
  chosen period (one month by default) — useful for billing.
- **Full workspace backup** (reserved to the Owner): downloads an archive
  containing every project, issue, logged time, comment and the entire
  knowledge base (with its tree structure). A simple way to keep a copy of
  your data or move it elsewhere.

## Account security

- **Change your password** from *Settings → Account*, confirming your
  current one.
- **Forgot password**: a reset link is sent by e-mail, valid for one hour
  and usable once. After resetting, all your other signed-in sessions are
  automatically signed out.
- **Verified invitations**: accepting an invitation requires being signed in
  with the exact e-mail address targeted by the invitation — an explicit
  message shows otherwise, without ever creating unwanted access.

## AI agents

TaskR is designed to be driven by AI agents just like a human: a connected
assistant acts **on behalf of the user whose token it carries**, with
exactly their rights and exactly the same rules (validations, permissions,
refusals). An agent can never do more than the person it acts on behalf of,
nor bypass a business rule.

Everything an agent does is attributed to its owner, timestamped, and
visible in the interface just like a human action. Revoking the token
immediately cuts off its access.

To set up an agent, see the corresponding section of the
[`README`](../README.md#driving-taskr-from-ai-agents-mcp).

## Installable app (mobile and desktop)

TaskR can be installed as an app from your browser (PWA): on mobile as well
as on desktop, it adds itself to the home screen or the apps menu and opens
in its own window, without any browser tab around it.

## Glossary

| Term | Definition |
|---|---|
| **Workspace** | The container for everything: projects, issues, statuses, labels, templates, documentation. |
| **Project** | A grouping of issues, with an optional target date. |
| **Issue** | The unit of work: title, description, priority, due date, labels, assignees, status. |
| **Status** | A column of the workflow, shared by every project of the workspace. |
| **Label** | A free-form marker, attachable to any issue. |
| **Template** | A pre-fill preset for creating recurring issues. |
| **Milestone** | A project step that issues can be attached to. |
| **Knowledge page** | A Markdown documentation page. |
| **Time entry** | A declaration of hours worked on an issue. |
| **Guest** | External access limited to its team's projects. |
| **Scoped member** | An internal member whose list of visible projects is limited, while keeping every function of a full member. |
