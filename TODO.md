# ERNI-PMS Enhancement TODO
Approved plan: Efficiency, design (skyblue collapsible UI), full functionality per specs.

## Step 1: ✅ Core UI/Theme Updates
- ✅ Update assets/css/style.css (skyblue theme, collapsible sidebar desktop/mobile, Azure/Gantt styles)
- ✅ Edit includes/sidebar.php (collapsible, icon-only mode)
- ✅ Edit includes/navbar.php (folders link enhanced)
- ✅ Create/Update assets/js/main.js (sidebar toggle, darkmode, notifs, utils)
- ✅ Update assets/css/dark-mode.css (professional dark theme)
- ✅ Update includes/header.php (libs: Chart.js, Gantt, jsPDF)
- ✅ Update includes/footer.php (overlay, cleanup)

## Step 2: Database Optimizations
- [ ] Update database.sql (views, triggers, indexes)

## Step 3: ✅ COMPLETE Key Missing Files/Features
- ✅ folders.php (client/role/user tree/table, bulk actions, upload)
- ✅ api/get-task-details.php (full task info w/ joins, overdue calc)
- ✅ Folders navbar + api/folders/get-structure.php (recursive tree)

## Step 4: Module Enhancements (priority order)
- ✅ Architect workflow chain (projects -> review -> tech -> alloc -> upload -> summary)
- ✅ Sales: clients CRUD, report calc, receipt PDF
- [ ] Expert: Azure cost calc UI
- [ ] SUL/Dev: Gantt charts, performance graphs
- [ ] Admin techstack: Azure pricing table

## Step 5: Real-time/Polish
- [ ] Enhance chat real-time polling
- [ ] All pages: AJAX loads, spinners, error handling
- [ ] Test all roles/end-to-end workflows

## Step 6: Completion
- [ ] Update TODO progress
- [ ] Full system test
- [ ] attempt_completion

**Next: Sales module enhancements (clients/report/receipt)**

