<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERNI PMS - Decision-Driven Project Management Support System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Chart.js -->
    <!-- Chart.js for dashboards -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Frappe Gantt for project timelines -->
    <script src="https://cdn.jsdelivr.net/npm/frappe-gantt@0.3.0/dist/frappe-gantt.umd.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/frappe-gantt@0.3.0/dist/frappe-gantt.css" rel="stylesheet">
    
    <!-- PDF.js for receipt generation (client-side) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/dark-mode.css">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom Scripts -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    
    <style>
        /* Dark mode media query support */
        @media (prefers-color-scheme: dark) {
            :root {
                --light-color: #1e293b;
                --border-color: #334155;
                --text-muted: #94a3b8;
            }
        }
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar will be included by the page -->
    </div>