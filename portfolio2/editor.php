<?php
$projectDataFile = 'ProjectData.json';
$descriptionsFile = 'Descriptions.json';
$assetsDir = 'assets/thumbnails/';

// Ensure assets dir exists
if (!is_dir($assetsDir)) {
    if (!mkdir($assetsDir, 0755, true)) {
        $message = "Error: Could not create assets directory.";
    }
}

// Helpers
function getProjects($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function getDescriptions($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

$projects = getProjects($projectDataFile);
$descriptions = getDescriptions($descriptionsFile);
$allTags = [];

foreach ($projects as $p) {
    if (isset($p['tags']) && is_array($p['tags'])) {
        foreach ($p['tags'] as $t) {
            $t = trim($t);
            if ($t) $allTags[$t] = true;
        }
    }
}
$allTags = array_keys($allTags);
sort($allTags);

$message = '';
$editingProject = null;
$editingDescription = '';
$originalTitle = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect data
    $originalTitle = $_POST['original_title'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $contributors = trim($_POST['contributors'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $iframe = isset($_POST['iframe']) ? true : false;
    
    // Tags processing
    $tagsInput = $_POST['tags'] ?? '';
    $tags = array_map('trim', explode(',', $tagsInput));
    $tags = array_values(array_filter($tags)); // Remove empty and re-index

    if (!$title || !$link) {
        $message = "Error: Title and Link are required.";
        // Keep entered data
        $editingProject = [
            'title' => $title,
            'link' => $link,
            'tags' => $tags,
            'contributors' => $contributors,
            'year' => $year,
            'iframe' => $iframe
        ];
        $editingProject['thumbnail'] = $_POST['existing_thumbnail'] ?? '';
        $editingDescription = $description;
    } else {
        // Handle Image Upload
        $thumbnailPath = $_POST['existing_thumbnail'] ?? '';
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['thumbnail']['tmp_name'];
            $name = basename($_FILES['thumbnail']['name']);
            // Sanitize filename
            $name = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $name);
            $target = $assetsDir . $name;
            if (move_uploaded_file($tmpName, $target)) {
                $thumbnailPath = $target;
            } else {
                $message .= "Failed to upload image. ";
            }
        }

        // Prepare new project object
        $newProject = [
            'title' => $title,
            'link' => $link,
            'tags' => $tags,
            'contributors' => $contributors,
            'year' => $year,
            'thumbnail' => $thumbnailPath,
            'iframe' => $iframe
        ];

        // Update ProjectData.json
        $foundIndex = -1;
        // Search by original title if editing, or by new title if it matches (for overwrite)
        foreach ($projects as $index => $p) {
            if (($originalTitle && $p['title'] === $originalTitle)) {
                $foundIndex = $index;
                break;
            }
        }

        // If not found by original title (maybe creating new, or original title was empty), check if title exists
        if ($foundIndex === -1) {
             foreach ($projects as $index => $p) {
                if ($p['title'] === $title) {
                    $foundIndex = $index;
                    break;
                }
            }
        }

        if ($foundIndex >= 0) {
            // Update existing
            $projects[$foundIndex] = array_merge($projects[$foundIndex], $newProject);
        } else {
            // Add new
            $projects[] = $newProject;
        }

        file_put_contents($projectDataFile, json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Update Descriptions.json
        // If title changed, remove old key
        if ($originalTitle && $originalTitle !== $title && isset($descriptions[$originalTitle])) {
            unset($descriptions[$originalTitle]);
        }
        $descriptions[$title] = $description;
        file_put_contents($descriptionsFile, json_encode($descriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $message = "Project saved successfully!";
        // Update state to reflect saved data
        $editingProject = $newProject;
        $editingDescription = $description;
        $originalTitle = $title;
        
        // Refresh projects list
        $projects = getProjects($projectDataFile);
        $descriptions = getDescriptions($descriptionsFile);
    }
}

// Handle Edit Mode (GET) - overwrite current state if not POSTing
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editTitle = $_GET['edit'];
    foreach ($projects as $p) {
        if ($p['title'] === $editTitle) {
            $editingProject = $p;
            $originalTitle = $p['title'];
            break;
        }
    }
    if (isset($descriptions[$editTitle])) {
        $editingDescription = $descriptions[$editTitle];
    }
}

// Prepare defaults for form
$formTitle = $editingProject['title'] ?? '';
$formLink = $editingProject['link'] ?? '';
$formTags = isset($editingProject['tags']) ? implode(', ', $editingProject['tags']) : '';
$formContributors = $editingProject['contributors'] ?? '';
$formYear = $editingProject['year'] ?? '';
$formThumbnail = $editingProject['thumbnail'] ?? '';
$formIframe = isset($editingProject['iframe']) ? $editingProject['iframe'] : true; // Default to true
$formDescription = $editingDescription;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Editor</title>
    <style>
        body { font-family: sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; background: #f4f4f4; }
        .container { display: flex; gap: 20px; }
        .sidebar { flex: 1; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto; }
        .main { flex: 2; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        .project-list { list-style: none; padding: 0; }
        .project-list li { margin-bottom: 10px; }
        .project-list a { text-decoration: none; color: #007bff; display: block; padding: 5px; border-radius: 4px; }
        .project-list a:hover { background: #eef; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], textarea, input[type="file"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        textarea { height: 100px; }
        .btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #218838; }
        .btn-new { background: #007bff; text-decoration: none; display: inline-block; margin-bottom: 15px; color: white; padding: 10px; border-radius: 4px;}
        .message { padding: 10px; margin-bottom: 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .thumbnail-preview { margin-top: 10px; max-width: 200px; display: block; border: 1px solid #ddd; padding: 5px;}
        .tags-suggestions { margin-top: 5px; font-size: 0.9em; color: #666; }
        .tag-pill { background: #eee; padding: 2px 6px; border-radius: 10px; margin-right: 5px; cursor: pointer; display: inline-block;}
    </style>
</head>
<body>

<h1>Portfolio Project Editor</h1>

<div class="container">
    <div class="sidebar">
        <h2>Projects</h2>
        <a href="editor.php" class="btn-new">+ New Project</a>
        <a href="blogEditor.php" class="btn-new" style="background-color: #6610f2; margin-left: 5px;">Go to Blog Editor</a>
        <ul class="project-list">
            <?php foreach ($projects as $p): ?>
                <li>
                    <a href="?edit=<?= urlencode($p['title']) ?>">
                        <?= htmlspecialchars($p['title']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="main">
        <?php if ($message): ?>
            <div class="message <?= strpos($message, 'Error') !== false ? 'error' : '' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <h2><?= $originalTitle ? 'Edit Project: ' . htmlspecialchars($originalTitle) : 'Create New Project' ?></h2>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="original_title" value="<?= htmlspecialchars($originalTitle) ?>">
            <input type="hidden" name="existing_thumbnail" value="<?= htmlspecialchars($formThumbnail) ?>">

            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($formTitle) ?>" required>
            </div>

            <div class="form-group">
                <label for="link">Project Link *</label>
                <input type="text" id="link" name="link" value="<?= htmlspecialchars($formLink) ?>" required>
                <div style="margin-top: 5px;">
                    <label style="display:inline; font-weight:normal;">
                        <input type="checkbox" name="iframe" <?= $formIframe ? 'checked' : '' ?>> Open in Iframe (default checked)
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="tags">Tags (comma separated) *</label>
                <input type="text" id="tags" name="tags" value="<?= htmlspecialchars($formTags) ?>" list="tag-list" required>
                <datalist id="tag-list">
                    <?php foreach ($allTags as $tag): ?>
                        <option value="<?= htmlspecialchars($tag) ?>">
                    <?php endforeach; ?>
                </datalist>
                <div class="tags-suggestions">
                    Existing tags: 
                    <?php foreach ($allTags as $tag): ?>
                        <span class="tag-pill" onclick="addTag('<?= htmlspecialchars($tag) ?>')"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="contributors">Contributors</label>
                <input type="text" id="contributors" name="contributors" value="<?= htmlspecialchars($formContributors) ?>" placeholder="Name 1, Name 2...">
            </div>

            <div class="form-group">
                <label for="year">Year</label>
                <input type="text" id="year" name="year" value="<?= htmlspecialchars($formYear) ?>" placeholder="e.g. 2025">
            </div>

            <div class="form-group">
                <label for="thumbnail">Thumbnail</label>
                <p style="font-size: 0.8em; color: #666; margin: 0 0 5px 0;">Recommended resolution: 600x400px</p>
                <input type="file" id="thumbnail" name="thumbnail" accept="image/*">
                <?php if ($formThumbnail): ?>
                    <p>Current: <?= htmlspecialchars($formThumbnail) ?></p>
                    <img src="<?= htmlspecialchars($formThumbnail) ?>" alt="Thumbnail Preview" class="thumbnail-preview">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= htmlspecialchars($formDescription) ?></textarea>
            </div>

            <button type="submit" class="btn">Save Project</button>
        </form>
    </div>
</div>

<script>
    function addTag(tag) {
        const input = document.getElementById('tags');
        const currentVal = input.value.trim();
        if (currentVal) {
            // Check if tag already exists in list to avoid duplicates
            const parts = currentVal.split(',').map(s => s.trim());
            if (!parts.includes(tag)) {
                input.value = currentVal + ', ' + tag;
            }
        } else {
            input.value = tag;
        }
    }
</script>

</body>
</html>