# ERNI PMS User Workflow Guide

This guide explains how users should move a project through the ERNI PMS system from client intake to release. Follow the steps in order so task status, approvals, handoffs, roadmap progress, QA, and release records stay consistent.

## Main Roles

| Role | Main responsibility |
| --- | --- |
| Admin | Manage users, settings, tech stack data, audit logs, and password reset requests. |
| Sales | Create clients and projects, confirm estimates, monitor release status, and generate reports or receipts. |
| Architect | Review project requirements, create feature tasks, recommend technology, allocate departments, build the roadmap, upload diagrams, and approve final work. |
| SUL | Assign experts and developers, review submitted work, request revisions, and monitor department progress. |
| Expert | Prepare feature estimates, project cost estimates, and team composition details. |
| Developer | Work on assigned tasks, save department handoffs, submit UI/UX designs, submit Git or pull request updates, and respond to revisions. |
| QA | Test approved work, complete checklists, pass or fail tasks, and send failed work back for fixes. |

## Complete Project Workflow

### 1. Admin prepares the system

1. Log in as Admin.
2. Open `User Management`.
3. Create or activate the needed users for Sales, Architect, SUL, Expert, Developer, and QA.
4. Assign the correct role and department for each user.
5. Open `Tech Stack` and confirm available technologies are updated.
6. Use `Settings`, `Password Resets`, and `Audit Log` when maintenance or account review is needed.

### 2. Sales creates the client and project

1. Log in as Sales.
2. Open `Clients`.
3. Add a new client or select an existing client.
4. Create the project request.
5. Enter project description, requested features, requirements, budget details, and timeline dates.
6. Submit the project so Architect can review it.

Important: Sales should make requirements clear before the project moves forward. Missing requirements can delay architecture, estimation, UI/UX design, development, and QA.

### 3. Architect reviews and prepares the project

1. Log in as Architect.
2. Open `Projects`.
3. Review the submitted project from Sales.
4. Open the project review page and confirm the client requirements.
5. Create feature tasks from the requested features.
6. Open `Tech Recommendation` and select the recommended technology stack.
7. Open `Developer Allocation` and define the needed departments and developer counts.
8. Open `Roadmap` or `Methodology`.
9. Select the project methodology, such as Agile, Scrum, Waterfall, Kanban, Iterative, Spiral, or V-Model.
10. Set phase dates and assign feature tasks into the roadmap.
11. Open `Upload Diagram` and upload the required architecture plan and workflow diagram.
12. Open `Summary` and submit the prepared project package for SUL and team execution.

Important: Every feature task should be scheduled in the roadmap before the project is handed off.

### 4. SUL assigns estimation work to Expert

1. Log in as SUL.
2. Open `Task Allocation`.
3. Review unassigned tasks for the department.
4. Assign estimation-ready work to an Expert when estimation is needed.
5. Monitor assigned tasks from the same page.

### 5. Expert prepares estimates

1. Log in as Expert.
2. Open `Feature Estimation`.
3. Review the assigned feature tasks.
4. Add effort estimates and notes for each feature.
5. Open `Cost Estimation` when project-level cost details are required.
6. Open `Team Composition` when staffing details are needed.
7. Submit completed estimates for review and approval.

### 6. Sales approves estimates

1. Log in as Sales.
2. Open `Estimation Approval`.
3. Review feature estimates, cost estimates, and project timeline impact.
4. Select or confirm the approved estimate days when required.
5. Approve the estimation.
6. After approval, the project can move into active development planning and developer assignment.

Important: Developers should not start implementation until the related work is Sales-approved and ready for assignment.

### 7. SUL assigns developers

1. Log in as SUL.
2. Open `Task Allocation`.
3. Select the project and the current roadmap task.
4. Assign the task to the correct developer or department member.
5. Make sure assignments follow the roadmap order.
6. Use `Roadmap Monitoring` to check phase and task progress.
7. Use `Resource Management` and `Performance / Report` to monitor workload and performance.

### 8. UI/UX developer submits design work

1. Log in as a Developer assigned to the UI/UX department.
2. Open `Task`.
3. Open the assigned UI/UX task.
4. Read the client requirements and additional requirements shown in the task details.
5. Add a design handoff title if needed.
6. Upload a design image, add a Figma or wireframe link, or write design notes.
7. Click `Submit Design`.
8. The system automatically saves the design handoff first.
9. After the handoff is saved, the task moves to `For Review` for SUL approval.

Important: The design handoff must include at least one of these: uploaded design image, external link, or notes.

### 9. Other developers complete implementation work

1. Log in as Developer.
2. Open `Task`.
3. Click `Start` on an assigned task to move it to `In Progress`.
4. Open task details and review requirements, roadmap context, and available UI/UX design references.
5. Save department handoffs when useful, such as frontend, backend, full stack, database, DevOps, or QA notes.
6. Use `Version Control` or the Git update panel to log branches, commits, repository links, and pull request links.
7. Submit the task for review when the implementation is ready.
8. If SUL or QA requests revision, fix the issue and resubmit.

### 10. SUL reviews submitted work

1. Log in as SUL.
2. Open `Task Allocation`.
3. Check tasks under `For Review`.
4. Review developer notes, handoffs, files, links, Git updates, and pull request details.
5. Approve the work when it is ready.
6. Send it to QA when testing is required.
7. Request revision if the work is incomplete or unclear.

Common outcomes:

| SUL action | Result |
| --- | --- |
| Approve UI/UX or department work | Task moves forward in the controlled workflow. |
| Send to QA | Task moves to `QA Testing`. |
| Request revision | Task moves to `SUL Revision`. |

### 11. QA tests the work

1. Log in as QA.
2. Open `Testing Dashboard`.
3. Review tasks under `QA Testing`.
4. Open the task and check requirements, notes, design handoffs, and Git references.
5. Complete the QA checklist.
6. Add QA comments or evidence when needed.
7. Click pass when the feature works correctly.
8. Click fail when the feature needs fixes.

Common outcomes:

| QA action | Result |
| --- | --- |
| Pass | Task moves to `QA Passed`. |
| Fail | Task moves to `QA Needs Revision`, and the developer must fix it. |

### 12. Architect gives final approval

1. Log in as Architect.
2. Open `Final Approval`.
3. Review tasks that passed QA.
4. Check implementation notes, QA results, and Git or release references.
5. Approve and merge the task when it is ready.
6. The task becomes completed after final approval.

### 13. Sales monitors release and closes business records

1. Log in as Sales.
2. Open `Release Status`.
3. Review completed work and release progress.
4. Open `Report` to prepare project or sales reporting.
5. Open `Receipt` when payment or final documentation is needed.
6. Confirm completion with the client when all required tasks are done.

## Task Status Guide

| Status | Meaning |
| --- | --- |
| `To Do` | Task is waiting to be started or assigned. |
| `In Progress` | Developer is actively working on the task. |
| `For Review` | Work was submitted and is waiting for SUL review. |
| `SUL Revision` | SUL requested changes from the developer. |
| `QA Testing` | QA is testing the approved work. |
| `QA Needs Revision` | QA found issues and sent the task back. |
| `QA Passed` | QA approved the work. |
| `Architect Review` | Work is ready for architect-level review, when applicable. |
| `Merged to Main` | Work has been merged into the main codebase. |
| `Ready for Release` | Work is ready to be released. |
| `Completed` | Work is finished and approved. |
| `Blocked` | Work cannot continue until a dependency or issue is resolved. |
| `Overdue` | Task passed its deadline. |

## Daily User Checklist

1. Log in and check notifications.
2. Open your role dashboard.
3. Review assigned tasks, pending approvals, and blocked items.
4. Update task notes, handoffs, Git updates, or QA comments before changing status.
5. Use the correct workflow button instead of manually skipping ahead.
6. Check the calendar for deadlines and planned work.
7. Keep comments short, specific, and action-focused.

## Handoff Rules

1. UI/UX should save design images, prototype links, or design notes before submitting.
2. Frontend should reference UI behavior, responsive states, and component notes.
3. Backend should reference APIs, validation, data rules, and integration notes.
4. Full Stack should connect frontend, backend, and integration details.
5. Database should document schema, migration, and data integrity details.
6. DevOps should document environment, deployment, pipeline, and release concerns.
7. QA should document test evidence, defects, and checklist notes.

## Recommended Order For One Feature

1. Sales captures the requirement.
2. Architect creates the feature task.
3. Expert estimates the feature.
4. Sales approves the estimate.
5. SUL assigns the work.
6. UI/UX submits design handoff when design is needed.
7. Developer implements the feature.
8. Developer submits Git or pull request update.
9. SUL reviews the submission.
10. QA tests the feature.
11. Architect gives final approval.
12. Sales tracks release status.

## What Users Should Avoid

1. Do not submit vague requirements.
2. Do not assign developers before Sales approval when approval is required.
3. Do not skip roadmap scheduling for feature tasks.
4. Do not submit UI/UX design without a handoff file, link, or note.
5. Do not approve work without reviewing handoffs and Git references.
6. Do not pass QA without checklist review.
7. Do not mark work complete before final approval.

## Where To Go By Role

| Role | Main menu path |
| --- | --- |
| Admin | `Dashboard`, `Tech Stack`, `User Management`, `Password Resets`, `Audit Log`, `Settings` |
| Sales | `Clients`, `Estimation Approval`, `Release Status`, `Report`, `Receipt` |
| Architect | `Projects`, `Final Approval`, `Version Control` |
| SUL | `Task Allocation`, `Roadmap Monitoring`, `Resource Management`, `Performance / Report`, `Version Control` |
| QA | `Testing Dashboard`, `Version Control` |
| Expert | `Feature Estimation`, `Cost Estimation`, `Team Composition` |
| Developer | `Task`, `Version Control` |

Use this guide as the main operating procedure when demonstrating or using the system.
