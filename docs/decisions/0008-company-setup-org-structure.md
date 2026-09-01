# 0008 — Company Setup: managing the org structure (departments & positions)

- **Status:** Accepted
- **Date:** 2026-06-11
- **Related:** [Departments module](../modules/departments.md),
  [ERD §2](../database/erd.md), [`employees` & org tables](../database/employees-tables.md),
  [0004 — Employee ↔ User](./0004-employee-user-separation.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md)

## Context

`departments`, `positions` and `work_schedules` were created as the **employee
foundation** — lookups the Employee and Recruitment modules select from — but they
had no management surface. They could only be seeded; an organisation could not shape
its own org chart. The sidebar's **Company Setup → Departments** entry was a
placeholder.

Departments are also **hierarchical** (`parent_id` self-reference) and each has a
**head** (an employee) and a set of **positions** (with a salary band). That structure
is the configuration the whole HR system reads from, so it deserves a first-class,
correct management module rather than a flat CRUD table.

Two latent issues surfaced while building it:

1. `departments.code` was declared **globally unique** — wrong under multi-tenancy
   (ADR 0005): two organisations could never both use "HR".
2. Re-parenting a department needs a **cycle guard** (a department must not become its
   own ancestor).

## Decision

Add a **Company Setup** area (route prefix `/setup`, its own `routes/setup.php`) whose
first surface manages the **org structure**: departments (as a hierarchy) and the
positions under each. Built to the established module pattern (controllers, requests,
resources, a statistics query, hashid route binding, permission gates, activity log).

- **The interface is a tree, not a table.** Departments are inherently hierarchical, so
  the page renders an expandable **org tree**; selecting a department opens a detail
  drawer that manages its **positions** inline. This is the "right pattern for the data"
  rather than forcing the directory-table layout used elsewhere.
- **Positions live here**, as a sub-resource of departments — there is no separate
  Positions module, and they only make sense under a department.
- **`code` is unique per tenant**, via a *partial* unique index
  `(organization_id, code) WHERE deleted_at IS NULL` — so archived departments free
  their code for reuse, and tenants are independent. Validation mirrors the index.
- **Cycle-safe re-parenting.** `parent_id` may not be the department itself or any
  descendant; enforced both in the request (`Rule::notIn(subtreeIds)`) and reflected in
  the UI (the parent picker hides the department's own subtree).
- **Archive, not destroy, by default.** Departments soft-delete (they are referenced HR
  data); restore is guarded against a code clash, and a permanent delete detaches —
  rather than deletes — sub-departments, positions and employees (their FKs null out).
- **Actor/subject convention holds:** `head_id` references an **employee** (the person);
  mutations are attributed to the acting **user** via the activity log.

## Alternatives considered

- **A flat departments table with a "parent" column.** Hides the structure that is the
  whole point; rejected in favour of the tree.
- **A separate Positions module.** Positions have no meaning outside a department and
  no nav entry; managing them in the department drawer keeps related edits together.
- **Per-tenant uniqueness via a plain composite unique** (ignoring `deleted_at`). Then an
  archived department would permanently reserve its code. The partial index is the
  correct soft-delete-aware form.

## Consequences

- **Positive:** organisations own their structure; the lookups every other module reads
  are now first-class and correct; the `code` collision bug is fixed for multi-tenancy;
  the tree makes hierarchy obvious.
- **Negative / watch-outs:**
  - The index eager-loads each department's positions (small metadata) to avoid a
    per-row fetch; fine at org-structure scale, not for thousands of positions.
  - Soft-deleting a parent leaves its children parentless in the tree — the UI renders
    such orphans at the root rather than hiding them.
  - The single-default-style invariants here are structural (cycles, codes); there is no
    separate "company profile" yet — that is a later Company Setup surface.
