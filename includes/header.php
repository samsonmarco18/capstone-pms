<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERNI PMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/frappe-gantt@0.3.0/dist/frappe-gantt.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/dark-mode.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dark-mode.css'); ?>">
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/frappe-gantt@0.3.0/dist/frappe-gantt.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>" defer></script>
</head>
<body>
    <div class="d-flex" id="wrapper">
