<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$projectResult = $conn->query("
    SELECT p.*, c.name AS client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.id = $projectId
");

if ($projectResult === false) {
    http_response_code(500);
    echo 'Failed to load project.';
    exit();
}

$project = $projectResult->fetch_assoc();
if (!$project) {
    header('Location: projects.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $features = $_POST['features'] ?? [];

    $conn->begin_transaction();
    try {
        $deleteResult = $conn->query("
            DELETE FROM tasks
            WHERE project_id = $projectId
              AND task_type = 'feature'
              AND (assigned_to IS NULL OR assigned_to = 0)
              AND status = 'todo'
        ");

        if ($deleteResult === false) {
            throw new RuntimeException('Failed to clear existing features.');
        }

        foreach ($features as $feature) {
            $title = trim((string) ($feature['title'] ?? ''));
            $description = trim((string) ($feature['description'] ?? ''));
            $priority = trim((string) ($feature['priority'] ?? 'medium'));

            if ($title === '') {
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO tasks (project_id, title, description, task_type, priority, status, assigned_by)
                VALUES (?, ?, ?, 'feature', ?, 'todo', ?)
            ");

            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare feature insert.');
            }

            $stmt->bind_param('isssi', $projectId, $title, $description, $priority, $user['id']);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Failed to save feature.');
            }
            $stmt->close();
        }

        logActivity($user['id'], 'define_features', 'projects', $projectId, null, $_POST);
        $conn->commit();
        header('Location: tech-recommendation.php?id=' . $projectId);
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        echo 'Failed to save features.';
        exit();
    }
}

$existingFeatures = [];
$featureResult = $conn->query("
    SELECT id, title, description, priority
    FROM tasks
    WHERE project_id = $projectId
      AND task_type = 'feature'
    ORDER BY id ASC
");

if ($featureResult !== false) {
    while ($row = $featureResult->fetch_assoc()) {
        $existingFeatures[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Features | ERNI PMS</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #1f2937;
        }

        .wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #d9e2ec;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        p {
            margin: 0 0 20px;
            color: #52606d;
        }

        .feature-item {
            border: 1px solid #d9e2ec;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            background: #f8fafc;
        }

        .grid {
            display: grid;
            grid-template-columns: 2fr 3fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
            text-transform: uppercase;
            color: #52606d;
        }

        input,
        select,
        button {
            width: 100%;
            box-sizing: border-box;
            font: inherit;
        }

        input,
        select {
            padding: 10px 12px;
            border: 1px solid #bcccdc;
            border-radius: 10px;
            background: #fff;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 12px 16px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
        }

        .btn-primary {
            background: #1f6392;
            color: #fff;
        }

        .btn-secondary {
            background: #e4e7eb;
            color: #1f2937;
        }

        .btn-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Define Features</h1>
            <p>Project: <?php echo htmlspecialchars($project['name']); ?> | Client: <?php echo htmlspecialchars($project['client_name']); ?></p>

            <form method="POST">
                <div id="featuresContainer">
                    <?php if ($existingFeatures): ?>
                        <?php foreach ($existingFeatures as $idx => $feature): ?>
                            <div class="feature-item">
                                <div class="grid">
                                    <div>
                                        <label>Feature Title</label>
                                        <input type="text" name="features[<?php echo $idx; ?>][title]" value="<?php echo htmlspecialchars($feature['title']); ?>" required>
                                    </div>
                                    <div>
                                        <label>Description</label>
                                        <input type="text" name="features[<?php echo $idx; ?>][description]" value="<?php echo htmlspecialchars($feature['description']); ?>">
                                    </div>
                                    <div>
                                        <label>Priority</label>
                                        <select name="features[<?php echo $idx; ?>][priority]">
                                            <?php foreach (['low', 'medium', 'high', 'critical'] as $priority): ?>
                                                <option value="<?php echo $priority; ?>" <?php echo $feature['priority'] === $priority ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst($priority); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-danger" onclick="removeFeature(this)">Remove</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="feature-item">
                            <div class="grid">
                                <div>
                                    <label>Feature Title</label>
                                    <input type="text" name="features[0][title]" required>
                                </div>
                                <div>
                                    <label>Description</label>
                                    <input type="text" name="features[0][description]">
                                </div>
                                <div>
                                    <label>Priority</label>
                                    <select name="features[0][priority]">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-danger" onclick="removeFeature(this)">Remove</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <button type="button" class="btn btn-secondary" onclick="addFeature()">Add Feature</button>
                    <button type="submit" class="btn btn-primary">Save Features and Continue</button>
                    <a class="btn btn-secondary" href="project-review.php?id=<?php echo $projectId; ?>">Back</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    let featureIndex = <?php echo count($existingFeatures) > 0 ? count($existingFeatures) : 1; ?>;

    function addFeature() {
        const container = document.getElementById('featuresContainer');
        const item = document.createElement('div');
        item.className = 'feature-item';
        item.innerHTML = `
            <div class="grid">
                <div>
                    <label>Feature Title</label>
                    <input type="text" name="features[${featureIndex}][title]" required>
                </div>
                <div>
                    <label>Description</label>
                    <input type="text" name="features[${featureIndex}][description]">
                </div>
                <div>
                    <label>Priority</label>
                    <select name="features[${featureIndex}][priority]">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div>
                    <button type="button" class="btn btn-danger" onclick="removeFeature(this)">Remove</button>
                </div>
            </div>
        `;
        container.appendChild(item);
        featureIndex++;
    }

    function removeFeature(button) {
        const items = document.querySelectorAll('.feature-item');
        if (items.length <= 1) {
            const parent = button.closest('.feature-item');
            parent.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
            parent.querySelectorAll('select').forEach((select) => {
                select.selectedIndex = 0;
            });
            return;
        }

        button.closest('.feature-item').remove();
    }
    </script>
</body>
</html>
