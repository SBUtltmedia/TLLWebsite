<?php
// --- CONFIGURATION ---
$blogDataFile = 'BlogData.json';
$snippetsFile = 'Snippets.json';
$blogsDir = 'blogs/';
$imagesDir = 'assets/blog_images/';

// --- HELPER FUNCTIONS ---
function getJson($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?? [];
}

function saveJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function slugify($text) {
    // replace non letter or digits by -
    $text = preg_replace('~[^\p{L}\p{N}]+~u', '-', $text);

    // transliterate
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

    // remove unwanted characters
    $text = preg_replace('~[^-\w]+~', '', $text);

    // trim
    $text = trim($text, '-');

    // remove duplicate -
    $text = preg_replace('~-+~', '-', $text);

    // lowercase
    $text = strtolower($text);

    return empty($text) ? 'n-a' : $text;
}

// --- INITIALIZE ---
if (!is_dir($blogsDir)) mkdir($blogsDir, 0755, true);
if (!is_dir($imagesDir)) mkdir($imagesDir, 0755, true);

$blogData = getJson($blogDataFile);
$snippets = getJson($snippetsFile);
$message = '';
$editorState = null; // For loading existing data

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $fileName = $_POST['fileName'] ?? '';
        if ($fileName) {
            // Remove from BlogData
            $blogData = array_values(array_filter($blogData, fn($p) => $p['fileName'] !== $fileName));
            saveJson($blogDataFile, $blogData);

            // Remove from Snippets
            if (isset($snippets[$fileName])) {
                unset($snippets[$fileName]);
                saveJson($snippetsFile, $snippets);
            }

            // Delete HTML file
            $filePath = $blogsDir . $fileName . '.html';
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $message = "Blog post deleted successfully.";
        }
    } 
    elseif ($action === 'save') {
        $title = $_POST['title'] ?? '';
        $authors = $_POST['authors'] ?? '';
        $date = $_POST['date'] ?? '';
        $existingFileName = $_POST['existingFileName'] ?? '';
        $blocksJson = $_POST['blocks'] ?? '[]';
        $thumbnailUrl = $_POST['thumbnail'] ?? '';
        
        if (!$title || !$authors) {
            $message = "Error: Title and Authors are required.";
        } else {
            // 1. Determine Filename
            $fileName = $existingFileName ?: slugify($title);
            // Ensure uniqueness if new
            if (!$existingFileName) {
                $originalSlug = $fileName;
                $counter = 1;
                while (file_exists($blogsDir . $fileName . '.html')) {
                    $fileName = $originalSlug . '-' . $counter++;
                }
            }

            // 2. Handle Image Uploads (from Editor)
            $blocks = json_decode($blocksJson, true);
            
            foreach ($_FILES as $key => $file) {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $tmpName = $file['tmp_name'];
                    $name = time() . '_' . preg_replace('/[^a-zA-Z0-9À-ÿĀ-ſƀ-ɏḀ-ỿ._-]/', '', basename($file['name'])); // More specific unicode range
                    $target = $imagesDir . $name;
                    
                    if (move_uploaded_file($tmpName, $target)) {
                        $blockId = str_replace('image_', '', $key);
                        foreach ($blocks as &$block) {
                            if ($block['id'] === $blockId) {
                                $block['content']['src'] = $target;
                            }
                        }
                    }
                }
            }
            // Re-encode blocks with new image paths
            $blocksJson = json_encode($blocks);


            // 3. Generate HTML Content & Snippet
            $htmlContent = '<div class="prose max-w-none text-lg">';
            $snippetText = '';
            
            foreach ($blocks as $block) {
                $c = $block['content'];
                switch ($block['type']) {
                    case 'header':
                        $htmlContent .= "<{$c['level']} class=\"text-2xl font-bold mt-6 mb-4 text-[var(--primary-color)]\">" . htmlspecialchars($c['text']) . "</{$c['level']}>";
                        break;
                    case 'paragraph':
                        // Simple NL2BR for paragraph, more complex logic could go here for WYSIWYG
                        $htmlContent .= '<p class="mb-4 leading-relaxed">' . nl2br($c['text']) . '</p>'; // Removed htmlspecialchars to allow simple HTML from wysiwyg
                        if (!$snippetText) $snippetText = strip_tags($c['text']);
                        break;
                    case 'code':
                         $htmlContent .= '<pre class="bg-gray-100 p-4 rounded-lg overflow-x-auto my-4 text-sm font-mono"><code>' . htmlspecialchars($c['text']) . '</code></pre>';
                        break;
                    case 'image':
                        $htmlContent .= '<figure class="my-6"><img src="' . htmlspecialchars($c['src']) . '" alt="' . htmlspecialchars($c['caption']) . '" class="w-full rounded-lg shadow-md">';
                        if (!empty($c['caption'])) $htmlContent .= '<figcaption class="text-sm text-[var(--accent-color)] mt-2 text-center">' . htmlspecialchars($c['caption']) . '</figcaption>';
                        $htmlContent .= '</figure>';
                        break;
                    case 'text-image-left':
                        $htmlContent .= '<div class="flex flex-col md:flex-row gap-6 my-6 items-center">';
                        $htmlContent .= '<div class="md:w-1/2"><img src="' . htmlspecialchars($c['src']) . '" class="w-full rounded-lg shadow-md"></div>';
                        $htmlContent .= '<div class="md:w-1/2"><p>' . nl2br($c['text']) . '</p></div>';
                        $htmlContent .= '</div>';
                        if (!$snippetText) $snippetText = strip_tags($c['text']);
                        break;
                    case 'text-image-right':
                        $htmlContent .= '<div class="flex flex-col md:flex-row-reverse gap-6 my-6 items-center">';
                        $htmlContent .= '<div class="md:w-1/2"><img src="' . htmlspecialchars($c['src']) . '" class="w-full rounded-lg shadow-md"></div>';
                        $htmlContent .= '<div class="md:w-1/2"><p>' . nl2br($c['text']) . '</p></div>';
                        $htmlContent .= '</div>';
                        if (!$snippetText) $snippetText = strip_tags($c['text']);
                        break;
                    case 'iframe':
                        $htmlContent .= '<div class="my-6 w-full h-64 md:h-96"><iframe src="' . htmlspecialchars($c['src']) . '" class="w-full h-full border-0 rounded-lg shadow-md"></iframe></div>';
                        break;
                }
            }
            $htmlContent .= '</div>';
            
            $htmlContent .= "\n<!-- EDITOR_DATA\n" . $blocksJson . "\n-->";

            // 4. Save File
            file_put_contents($blogsDir . $fileName . '.html', $htmlContent);

            // 5. Update Snippets
            $snippetText = mb_substr($snippetText, 0, 300);
            if (strlen($snippetText) >= 300) $snippetText .= '...';
            $snippets[$fileName] = $snippetText;
            saveJson($snippetsFile, $snippets);

            // 6. Update BlogData
            $found = false;
            $authorsArray = array_map('trim', explode(',', $authors));
            
            // Validate thumbnail: don't save blob URLs
            if (strpos($thumbnailUrl, 'blob:') === 0) {
                $thumbnailUrl = '';
            }

            if (!$thumbnailUrl) {
                foreach ($blocks as $block) {
                    if (isset($block['content']['src']) && $block['content']['src'] && strpos($block['content']['src'], 'blob:') !== 0) {
                        $thumbnailUrl = $block['content']['src'];
                        break;
                    }
                }
                if (!$thumbnailUrl) $thumbnailUrl = 'https://via.placeholder.com/400x300?text=No+Image';
            }

            $newEntry = [
                "title" => $title,
                "authors" => $authorsArray,
                "date" => $date,
                "fileName" => $fileName,
                "thumbnail" => $thumbnailUrl
            ];

            foreach ($blogData as &$entry) {
                if ($entry['fileName'] === $fileName) {
                    $entry = $newEntry;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $blogData[] = $newEntry;
            }
            saveJson($blogDataFile, $blogData);

            $message = "Blog saved successfully!";
            header("Location: blogEditor.php?edit=" . urlencode($fileName));
            exit;
        }
    }
}

// --- HANDLE GET (Load Data) ---
$currentPost = null;
if (isset($_GET['edit'])) {
    $editFile = $_GET['edit'];
    foreach ($blogData as $p) {
        if ($p['fileName'] === $editFile) {
            $currentPost = $p;
            break;
        }
    }
    
    $filePath = $blogsDir . $editFile . '.html';
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        if (preg_match('/<!-- EDITOR_DATA\s+(.*?)\s+-->/s', $content, $matches)) {
            $editorState = $matches[1];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TLL Blog Editor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Roboto+Mono:wght@400;500&family=Inter:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --glass-opacity: 0.9;
            --bg-color: #ffffff;
            --text-color: #1a1a1a;
            --primary-color: #df3030;
            --secondary-color: #3556ff;
            --card-border: #e0e0e0;
        }
        body { background-color: #f0f2f5; color: var(--text-color); font-family: 'Poppins', sans-serif; overflow: hidden; }
        
        /* Glass Panel */
        .glass-panel {
            background-color: rgba(255, 255, 255, var(--glass-opacity));
            backdrop-filter: blur(12px);
            border-right: 1px solid var(--card-border);
            box-shadow: 4px 0 24px rgba(0,0,0,0.05);
        }

        /* Editor Transition */
        #editor-sidebar {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .dragging { opacity: 0.5; border: 2px dashed var(--secondary-color); background: #f0f9ff; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Block Hover Effects */
        .editor-block {
            transition: all 0.2s ease;
        }
        .editor-block:hover {
            box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }
        
        /* Toggle Handle */
        .toggle-handle {
            writing-mode: vertical-lr;
            text-orientation: mixed;
        }
    </style>
</head>
<body class="h-screen w-full relative bg-[var(--bg-color)]">
    
    <iframe src="background.html" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; border: none; pointer-events: none; opacity: 0.5;"></iframe>

    <?php if (!isset($_GET['edit'])): ?>
    <!-- Selection Modal -->
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
        <div class="glass-panel w-full max-w-2xl rounded-lg shadow-2xl p-8 flex flex-col max-h-[80vh]">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold">Select a Blog Post to Edit</h1>
                <a href="editor.php" class="text-sm font-bold text-[var(--secondary-color)] hover:underline">Back to Projects</a>
            </div>
            
            <div class="grid gap-4 mb-6">
                <a href="?edit=" class="flex items-center gap-4 p-4 rounded-lg border-2 border-dashed border-gray-300 hover:border-[var(--primary-color)] hover:bg-white/50 transition-all group">
                    <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-400 group-hover:bg-[var(--primary-color)] group-hover:text-white transition-colors">
                        <i data-lucide="plus" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg group-hover:text-[var(--primary-color)]">Create New Post</h3>
                        <p class="text-sm text-gray-500">Start from scratch with a blank canvas</p>
                    </div>
                </a>
            </div>

            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Existing Posts</h3>
            <div class="flex-1 overflow-y-auto space-y-2 pr-2">
                <?php foreach (array_reverse($blogData) as $post): ?>
                <a href="?edit=<?= urlencode($post['fileName']) ?>" class="flex items-center gap-4 p-3 rounded-lg bg-white/60 hover:bg-white border border-transparent hover:border-gray-200 transition-all shadow-sm">
                    <div class="w-16 h-12 rounded bg-gray-200 overflow-hidden flex-shrink-0">
                        <img src="<?= htmlspecialchars($post['thumbnail']) ?>" class="w-full h-full object-cover" onerror="this.style.display='none'">
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-sm truncate"><?= htmlspecialchars($post['title']) ?></h4>
                        <p class="text-xs text-gray-500 flex gap-2">
                            <span><?= htmlspecialchars($post['date']) ?></span>
                            <span>•</span>
                            <span><?= htmlspecialchars(implode(', ', $post['authors'])) ?></span>
                        </p>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-400"></i>
                </a>
                <?php endforeach; ?>
                <?php if (empty($blogData)): ?>
                    <p class="text-center text-gray-400 py-8 italic">No existing posts found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>lucide.createIcons();</script>
    <?php exit; endif; ?>

    <!-- Main Wrapper -->
    <div class="flex h-full relative z-10">

        <!-- LEFT PANEL: Editor Sidebar -->
        <div id="editor-sidebar" class="absolute z-30 h-full flex flex-col glass-panel w-full md:w-[400px] transform translate-x-0">
            
            <!-- Header -->
            <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-white/50 backdrop-blur-sm">
                <div class="flex items-center gap-3">
                    <a href="blogEditor.php" class="p-1 text-gray-500 hover:text-[var(--primary-color)] transition-colors" title="Back to Menu">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <h1 class="font-bold tracking-tight text-lg">BLOG EDITOR</h1>
                </div>
                <div class="flex gap-2">
                     <a href="blog.html" target="_blank" title="View Blog" class="p-2 text-gray-400 hover:text-[var(--primary-color)] transition-colors"><i data-lucide="external-link" class="w-4 h-4"></i></a>
                    <button onclick="savePost()" class="bg-[var(--primary-color)] text-white px-4 py-1.5 rounded text-xs font-bold hover:bg-red-700 transition-colors flex items-center gap-1 shadow-sm">
                        <i data-lucide="save" class="w-3 h-3"></i> SAVE
                    </button>
                </div>
            </div>

            <!-- Block List Container -->
            <div id="block-list-container" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50/50">
                <!-- SETTINGS BLOCK (Fixed) -->
                <div class="bg-white p-4 rounded-lg shadow-sm border-l-4 border-[var(--primary-color)] mb-4">
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <i data-lucide="settings" class="w-3 h-3"></i> Post Settings
                    </h3>
                    <form id="meta-form" class="space-y-3">
                         <input type="hidden" name="existingFileName" value="<?= htmlspecialchars($currentPost['fileName'] ?? '') ?>">
                        <div>
                            <input type="text" name="title" id="post-title" value="<?= htmlspecialchars($currentPost['title'] ?? '') ?>" class="w-full text-sm font-bold border-b border-gray-200 focus:border-[var(--primary-color)] outline-none bg-transparent py-1 placeholder-gray-400" placeholder="Enter Post Title..." required>
                        </div>
                        <div class="flex gap-3">
                             <div class="flex-1">
                                <input type="text" name="authors" value="<?= htmlspecialchars(implode(', ', $currentPost['authors'] ?? [])) ?>" class="w-full text-xs border-b border-gray-200 focus:border-[var(--primary-color)] outline-none bg-transparent py-1 placeholder-gray-400" placeholder="Authors (comma sep)" required>
                            </div>
                            <div class="w-1/3">
                                <input type="text" name="date" value="<?= htmlspecialchars($currentPost['date'] ?? date('F j, Y')) ?>" class="w-full text-xs border-b border-gray-200 focus:border-[var(--primary-color)] outline-none bg-transparent py-1 text-gray-500">
                            </div>
                        </div>
                        <div class="pt-2 flex items-center justify-between">
                            <input type="hidden" name="thumbnail" id="post-thumbnail" value="<?= htmlspecialchars($currentPost['thumbnail'] ?? '') ?>">
                             <span class="text-[10px] text-gray-400 uppercase">Thumbnail Auto-Selected</span>
                             <?php if ($currentPost): ?>
                                <button type="button" onclick="confirmDelete('<?= $currentPost['fileName'] ?>')" class="text-red-400 text-[10px] hover:text-red-600 hover:underline">DELETE POST</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- DYNAMIC BLOCKS -->
                <div id="block-list" class="space-y-3 pb-8"></div>
            </div>
            
            <!-- Toggle Handle (Desktop) -->
            <div id="sidebar-toggle" class="absolute top-1/2 -right-6 w-6 h-24 bg-white border border-l-0 border-gray-200 rounded-r-lg shadow-sm flex items-center justify-center cursor-pointer hover:bg-gray-50 z-50 hidden md:flex" onclick="toggleSidebar()">
                <i data-lucide="chevron-left" class="w-4 h-4 text-gray-400" id="toggle-icon"></i>
            </div>
        </div>

        <!-- RIGHT PANEL: Preview -->
        <div id="preview-panel" class="flex-1 h-full overflow-y-auto relative transition-all duration-500 md:ml-[400px]">
            <div class="max-w-4xl mx-auto py-12 px-8 md:px-16 min-h-full">
                
                <div class="glass-panel p-8 rounded-lg">
                    <!-- Preview Header -->
                    <header class="mb-12 border-b border-[var(--card-border)] pb-6">
                        <h1 class="text-4xl md:text-5xl font-bold mb-4 text-[var(--text-color)]" id="preview-title">New Blog Post</h1>
                        <div class="flex items-center gap-4 text-sm font-mono text-gray-500">
                            <span id="preview-date"><?= date('F j, Y') ?></span>
                            <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                            <span id="preview-authors">Unknown Author</span>
                        </div>
                    </header>

                    <div id="preview-content" class="prose prose-lg max-w-none prose-headings:font-bold prose-headings:text-[var(--text-color)] prose-p:text-gray-600">
                        <!-- Live Preview Content -->
                    </div>
                </div>
                
                 <div class="h-32"></div> <!-- Spacer -->
            </div>
        </div>
    </div>

    <!-- ADD BLOCK MODAL -->
    <dialog id="add-block-modal" class="p-0 rounded-xl shadow-2xl border-0 backdrop:bg-black/20 open:animate-in open:fade-in open:zoom-in-95 duration-200">
        <div class="w-[300px] bg-white rounded-xl overflow-hidden">
            <div class="bg-gray-50 p-3 border-b flex justify-between items-center">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Insert Block</span>
                <button onclick="document.getElementById('add-block-modal').close()" class="text-gray-400 hover:text-gray-600"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <div class="p-2 grid grid-cols-3 gap-2">
                <button onclick="app.insertBlock('paragraph')" class="flex flex-col items-center gap-2 p-3 rounded hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-gray-800 text-white flex items-center justify-center"><i data-lucide="type" class="w-4 h-4"></i></div>
                    <span class="text-[10px] font-bold text-gray-600">Text</span>
                </button>
                <button onclick="app.insertBlock('header')" class="flex flex-col items-center gap-2 p-3 rounded hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-[var(--primary-color)] text-white flex items-center justify-center"><i data-lucide="heading" class="w-4 h-4"></i></div>
                    <span class="text-[10px] font-bold text-gray-600">Header</span>
                </button>
                 <button onclick="app.insertBlock('image')" class="flex flex-col items-center gap-2 p-3 rounded hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-[var(--secondary-color)] text-white flex items-center justify-center"><i data-lucide="image" class="w-4 h-4"></i></div>
                    <span class="text-[10px] font-bold text-gray-600">Image</span>
                </button>
                 <button onclick="app.insertBlock('text-image-right')" class="flex flex-col items-center gap-2 p-3 rounded hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-amber-500 text-white flex items-center justify-center"><i data-lucide="layout-template" class="w-4 h-4"></i></div>
                    <span class="text-[10px] font-bold text-gray-600">Split</span>
                </button>
                <button onclick="app.insertBlock('code')" class="flex flex-col items-center gap-2 p-3 rounded hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-gray-500 text-white flex items-center justify-center"><i data-lucide="code" class="w-4 h-4"></i></div>
                    <span class="text-[10px] font-bold text-gray-600">Code</span>
                </button>
                <button onclick="app.insertBlock('iframe')" class="flex flex-col items-center gap-2 p-3 rounded hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-emerald-500 text-white flex items-center justify-center"><i data-lucide="globe" class="w-4 h-4"></i></div>
                    <span class="text-[10px] font-bold text-gray-600">Embed</span>
                </button>
            </div>
        </div>
    </dialog>

    <!-- HIDDEN FORMS -->
    <form id="main-form" method="POST" enctype="multipart/form-data" style="display:none;">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="title" id="hidden-title">
        <input type="hidden" name="date" id="hidden-date">
        <input type="hidden" name="authors" id="hidden-authors">
        <input type="hidden" name="thumbnail" id="hidden-thumbnail">
        <input type="hidden" name="existingFileName" id="hidden-fileName">
        <input type="hidden" name="blocks" id="hidden-blocks">
    </form>
    
    <form id="delete-form" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="fileName" id="delete-fileName">
    </form>
    
    <!-- Confirm Delete Modal -->
     <dialog id="delete-modal" class="p-6 rounded-lg shadow-xl border border-red-200 backdrop:bg-black/50">
        <h3 class="text-xl font-bold text-red-600 mb-2">Delete Blog Post?</h3>
        <p class="mb-6 text-sm text-gray-600">Are you sure you want to delete this post?<br>This action cannot be undone.</p>
        <div class="flex justify-end gap-3">
            <button onclick="document.getElementById('delete-modal').close()" class="px-4 py-2 rounded text-sm font-medium hover:bg-gray-100">Cancel</button>
            <button id="confirm-delete-btn" class="px-4 py-2 rounded bg-red-600 text-white text-sm font-bold hover:bg-red-700">Delete Permanently</button>
        </div>
    </dialog>

    <script>
        // --- HELPERS ---
        const generateId = () => Math.random().toString(36).substr(2, 9);

        // --- DATA ---
        const PHP_DATA = <?= $editorState ? $editorState : 'null' ?>;
        
        const DEFAULT_CONTENT = {
            'header': { text: 'New Header', level: 'h2' },
            'paragraph': { text: 'Start writing your story...', align: 'left' },
            'code': { text: '// Your code here' },
            'image': { src: '', caption: '' },
            'text-image-left': { text: 'Descriptive text...', src: '', caption: '' },
            'text-image-right': { text: 'Descriptive text...', src: '', caption: '' },
            'iframe': { src: 'https://', showPreview: true }
        };

        const INITIAL_BLOCKS = [
            { id: generateId(), type: 'header', content: { text: 'Welcome to the Editor', level: 'h2' } },
            { id: generateId(), type: 'paragraph', content: { text: 'Click the + button below to add more content blocks.' } }
        ];

        class App {
            constructor() {
                this.blocks = PHP_DATA || [...INITIAL_BLOCKS];
                this.draggedBlockIndex = null;
                this.fileInputs = {};
                this.insertIndex = null; // Track where to insert new block
                
                // Elements
                this.blockList = document.getElementById('block-list');
                this.previewContent = document.getElementById('preview-content');
                this.previewTitle = document.getElementById('preview-title');
                this.previewDate = document.getElementById('preview-date');
                this.previewAuthors = document.getElementById('preview-authors');
                
                // Form Inputs
                this.titleInput = document.getElementById('post-title');
                this.dateInput = document.querySelector('input[name="date"]');
                this.authorsInput = document.querySelector('input[name="authors"]');

                this.init();
            }

            init() {
                this.renderAll();
                this.updateMetaPreview();
                this.setupListeners();
                lucide.createIcons();
            }

            setupListeners() {
                [this.titleInput, this.dateInput, this.authorsInput].forEach(el => {
                    el.addEventListener('input', () => this.updateMetaPreview());
                });
            }

            updateMetaPreview() {
                this.previewTitle.textContent = this.titleInput.value || 'Untitled Post';
                this.previewDate.textContent = this.dateInput.value;
                this.previewAuthors.textContent = this.authorsInput.value || 'Unknown Author';
            }

            // --- RENDER ---
            
            renderAll() {
                this.renderEditorList();
                this.renderPreviewList();
                lucide.createIcons();
            }

            renderEditorList() {
                this.blockList.innerHTML = '';
                
                this.blocks.forEach((block, index) => {
                    // Create Block Element
                    const el = document.createElement('div');
                    el.className = 'editor-block bg-white p-3 rounded-lg shadow-sm border border-gray-100 group relative mb-2';
                    // el.draggable = true; // Removed to fix text selection
                    el.dataset.id = block.id;
                    el.innerHTML = this.getEditorBlockHTML(block, index);

                    // Add Events
                    this.attachBlockEvents(el, block, index);
                    
                    // Bind Inputs
                    el.querySelectorAll('[data-field]').forEach(input => {
                        input.addEventListener('input', (e) => {
                            block.content[e.target.dataset.field] = e.target.value;
                            this.renderPreviewList();
                        });
                    });
                     // Bind File Inputs
                    const fileInput = el.querySelector('input[type="file"]');
                    if (fileInput) {
                        fileInput.addEventListener('change', (e) => this.handleFileUpload(e, block));
                    }

                    this.blockList.appendChild(el);

                    // Add "Insert Between" Button
                    const insertBtn = document.createElement('button');
                    insertBtn.className = "w-full h-4 opacity-0 hover:opacity-100 flex items-center justify-center transition-opacity group/insert";
                    insertBtn.innerHTML = "<div class=\"h-0.5 bg-blue-200 w-full group-hover/insert:w-1/2 transition-all\"></div><div class=\"bg-blue-500 text-white rounded-full p-0.5 shadow-sm transform scale-0 group-hover/insert:scale-100 transition-transform\"><i data-lucide=\"plus\" class=\"w-3 h-3\"></i></div><div class=\"h-0.5 bg-blue-200 w-full group-hover/insert:w-1/2 transition-all\"></div>";
                    insertBtn.onclick = () => this.openAddModal(index + 1);
                    this.blockList.appendChild(insertBtn);
                });

                // Final Add Button if empty
                if (this.blocks.length === 0) {
                     const emptyState = document.createElement('div');
                     emptyState.className = "text-center p-8 text-gray-400 border-2 border-dashed border-gray-200 rounded-lg cursor-pointer hover:border-blue-400 hover:text-blue-500 transition-colors";
                     emptyState.innerHTML = "<p class='text-sm'>Post is empty. Click to add content.</p>";
                     emptyState.onclick = () => this.openAddModal(0);
                     this.blockList.appendChild(emptyState);
                }
            }

            getEditorBlockHTML(block, index) {
                const c = block.content;
                let body = '';
                
                const handle = `
                    <div class="flex justify-between items-center mb-2 handle cursor-move text-gray-400 hover:text-gray-600 p-1 bg-gray-50 rounded" draggable="true">
                         <div class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-wider">
                            <i data-lucide="grip-vertical" class="w-3 h-3"></i> ${block.type}
                        </div>
                        <button onclick="app.deleteBlock('${block.id}')" class="text-gray-300 hover:text-red-500 transition-colors"><i data-lucide="x" class="w-3 h-3"></i></button>
                    </div>
                `;

                const toolbar = `
                    <div class="flex gap-1 mb-1 border-b border-gray-100 pb-1">
                        <button onmousedown="event.preventDefault(); app.formatText('bold')" class="p-1 hover:bg-gray-200 rounded text-gray-600" title="Bold"><i data-lucide="bold" class="w-3 h-3"></i></button>
                        <button onmousedown="event.preventDefault(); app.formatText('italic')" class="p-1 hover:bg-gray-200 rounded text-gray-600" title="Italic"><i data-lucide="italic" class="w-3 h-3"></i></button>
                        <button onmousedown="event.preventDefault(); app.formatText('underline')" class="p-1 hover:bg-gray-200 rounded text-gray-600" title="Underline"><i data-lucide="underline" class="w-3 h-3"></i></button>
                        <button onmousedown="event.preventDefault(); {const url=prompt('URL:'); if(url) app.formatText('createLink', url)}" class="p-1 hover:bg-gray-200 rounded text-gray-600" title="Link"><i data-lucide="link" class="w-3 h-3"></i></button>
                    </div>
                `;

                if (block.type === 'header') {
                    body = `
                        <div class="flex gap-2">
                             <select onchange="app.updateBlockContent('${block.id}', 'level', this.value)" class="text-xs bg-gray-50 border-none rounded font-bold text-gray-600 focus:ring-0 cursor-pointer">
                                <option value="h1" ${c.level=='h1'?'selected':''}>H1</option>
                                <option value="h2" ${c.level=='h2'?'selected':''}>H2</option>
                                <option value="h3" ${c.level=='h3'?'selected':''}>H3</option>
                            </select>
                            <input data-field="text" value="${c.text}" class="w-full text-sm font-bold border-none outline-none focus:ring-0 bg-transparent placeholder-gray-300" placeholder="Header Title...">
                        </div>
                    `;
                } else if (block.type === 'paragraph') {
                    body = `${toolbar}<div contenteditable="true" class="w-full text-sm min-h-[60px] outline-none text-gray-600 empty:before:content-[attr(placeholder)] empty:before:text-gray-300 p-2 cursor-text" placeholder="Type paragraph text..." oninput="app.handleContentEditable('${block.id}', 'text', this)">${c.text}</div>`;
                } else if (block.type === 'code') {
                    body = `<textarea data-field="text" class="w-full bg-gray-900 text-green-400 font-mono text-xs p-2 rounded h-20 resize-y outline-none border-none" placeholder="// Code here...">${c.text}</textarea>`;
                } else if (block.type === 'image' || block.type.includes('text-image')) {
                     body = `
                        <div class="space-y-2">
                             ${block.type.includes('text') ? `
                                <div class="flex gap-2 mb-2">
                                    <button onclick="app.changeBlockType('${block.id}', 'text-image-left')" class="flex-1 py-1 text-[10px] font-bold border rounded ${block.type === 'text-image-left' ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-200 text-gray-500'}">Img Left</button>
                                    <button onclick="app.changeBlockType('${block.id}', 'text-image-right')" class="flex-1 py-1 text-[10px] font-bold border rounded ${block.type === 'text-image-right' ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-200 text-gray-500'}">Img Right</button>
                                </div>
                                ${toolbar}
                                <div contenteditable="true" class="w-full text-sm min-h-[40px] outline-none text-gray-600 mb-2 border-b border-gray-100 pb-2 p-1 cursor-text" oninput="app.handleContentEditable('${block.id}', 'text', this)">${c.text}</div>
                             ` : ''}
                             
                             <div class="flex gap-2 items-center">
                                <label class="cursor-pointer bg-gray-100 hover:bg-gray-200 px-2 py-1 rounded text-[10px] font-bold text-gray-600 flex items-center gap-1 transition-colors">
                                    <i data-lucide="upload" class="w-3 h-3"></i> Upload
                                    <input type="file" accept="image/*" class="hidden">
                                </label>
                                <input data-field="src" value="${c.src}" class="text-[10px] border-none bg-gray-50 rounded w-full px-2 py-1 text-gray-500 focus:ring-0" placeholder="Or paste image URL...">
                             </div>
                             
                             ${c.src ? `
                                <div class="relative group/img mt-2 rounded overflow-hidden bg-gray-100 border border-gray-200">
                                    <img src="${c.src}" class="h-24 w-full object-cover">
                                    <div class="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover/img:opacity-100 transition-opacity">
                                         <button onclick="app.setAsThumbnail('${c.src}')" class="text-[10px] bg-white text-black px-2 py-1 rounded font-bold hover:scale-105 transition-transform">Set as Thumbnail</button>
                                    </div>
                                </div>
                             ` : ''}
                             <input data-field="caption" value="${c.caption}" class="w-full text-[10px] italic text-center border-none bg-transparent outline-none text-gray-400 focus:text-gray-600" placeholder="Add a caption...">
                        </div>
                     `;
                } else if (block.type === 'iframe') {
                    body = `
                         <div class="flex items-center gap-2 bg-gray-50 p-1 rounded">
                            <i data-lucide="globe" class="w-3 h-3 text-gray-400 ml-1"></i>
                            <input data-field="src" value="${c.src}" class="w-full text-xs bg-transparent border-none focus:ring-0 text-gray-600" placeholder="https://...">
                        </div>
                    `;
                }

                return handle + body;
            }

            renderPreviewList() {
                 let html = '';
                this.blocks.forEach(block => {
                    const c = block.content;
                    if (block.type === 'header') {
                        const sizes = { h1: 'text-3xl', h2: 'text-2xl', h3: 'text-xl' };
                         html += `<${c.level} class="${sizes[c.level]} font-bold mt-8 mb-4 text-[var(--text-color)]">${c.text}</${c.level}>`;
                    } else if (block.type === 'paragraph') {
                        // Simple preservation of line breaks from wysiwyg divs
                        let text = c.text.replace(/<div>/g, '<br>').replace(/<\/div>/g, ''); 
                        html += `<p class="mb-4 leading-relaxed">${text}</p>`;
                    } else if (block.type === 'code') {
                         html += `<pre class="bg-gray-100 p-4 rounded-lg overflow-x-auto my-4 text-sm font-mono text-gray-800"><code>${c.text}</code></pre>`;
                    } else if (block.type === 'image') {
                        html += `<figure class="my-8"><img src="${c.src || 'https://via.placeholder.com/600x400'}" class="w-full rounded-lg shadow-md"><figcaption class="text-sm text-gray-500 mt-2 text-center">${c.caption}</figcaption></figure>`;
                    } else if (block.type === 'text-image-left') {
                        html += `<div class="flex flex-col md:flex-row gap-8 my-8 items-center">
                                    <div class="md:w-1/2"><img src="${c.src || 'https://via.placeholder.com/400'}" class="w-full rounded-lg shadow-md object-cover aspect-video"></div>
                                    <div class="md:w-1/2"><p class="leading-relaxed">${c.text}</p></div>
                                 </div>`;
                    } else if (block.type === 'text-image-right') {
                        html += `<div class="flex flex-col md:flex-row-reverse gap-8 my-8 items-center">
                                    <div class="md:w-1/2"><img src="${c.src || 'https://via.placeholder.com/400'}" class="w-full rounded-lg shadow-md object-cover aspect-video"></div>
                                    <div class="md:w-1/2"><p class="leading-relaxed">${c.text}</p></div>
                                 </div>`;
                    } else if (block.type === 'iframe') {
                         html += `<div class="my-8 w-full h-64 md:h-96 border-2 border-dashed border-gray-200 rounded-lg flex items-center justify-center text-gray-400 bg-gray-50 overflow-hidden">
                                    ${c.src ? `<iframe src="${c.src}" class="w-full h-full border-0"></iframe>` : '<div class="flex flex-col items-center gap-2"><i data-lucide="monitor" class="w-8 h-8"></i><span class="text-xs font-mono">EMBED PREVIEW</span></div>'}
                                  </div>`;
                    }
                });
                this.previewContent.innerHTML = html;
            }

            // --- ACTIONS ---

            formatText(cmd, value = null) {
                document.execCommand(cmd, false, value);
            }

            changeBlockType(id, type) {
                const block = this.blocks.find(b => b.id === id);
                if (block) {
                    block.type = type;
                    this.renderAll();
                }
            }

            handleContentEditable(id, field, el) {
                const block = this.blocks.find(b => b.id === id);
                if (block) {
                    block.content[field] = el.innerHTML; // Save HTML for WYSIWYG
                    // No re-render needed for preview as it's debounced or on-demand usually, 
                    // but here we just update preview content.
                    // We DO NOT re-render editor list to avoid cursor jump.
                    this.renderPreviewList();
                }
            }

            openAddModal(index) {
                this.insertIndex = index;
                document.getElementById('add-block-modal').showModal();
            }

            insertBlock(type) {
                const newBlock = { id: generateId(), type, content: { ...DEFAULT_CONTENT[type] } };
                this.blocks.splice(this.insertIndex, 0, newBlock);
                document.getElementById('add-block-modal').close();
                this.renderAll();
            }

            deleteBlock(id) {
                this.blocks = this.blocks.filter(b => b.id !== id);
                this.renderAll();
            }

            updateBlockContent(id, field, value) {
                const block = this.blocks.find(b => b.id === id);
                if(block) {
                    block.content[field] = value;
                    this.renderAll(); // Re-render needed for header level change
                }
            }
            
            handleFileUpload(e, block) {
                const file = e.target.files[0];
                if (!file) return;
                
                const reader = new FileReader();
                reader.onload = (e) => {
                    block.content.src = e.target.result;
                    this.renderAll();
                };
                reader.readAsDataURL(file);
                
                this.fileInputs[block.id] = file;
            }

            setAsThumbnail(src) {
                if (src.startsWith('blob:') || src.startsWith('data:')) {
                    alert("This image is currently being uploaded. It will be set as the thumbnail if it is the first image in the post, or you can select it after saving.");
                    document.getElementById('post-thumbnail').value = ''; 
                } else {
                    document.getElementById('post-thumbnail').value = src;
                }
            }

            // --- DRAG EVENTS ---
             attachBlockEvents(el, block, index) {
                el.addEventListener('dragstart', (e) => {
                    // Only allow drag if clicking the handle
                    if (!e.target.closest('.handle')) {
                        e.preventDefault();
                        return;
                    }
                    this.draggedBlockIndex = index;
                    el.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });
                el.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    if (this.draggedBlockIndex === null || this.draggedBlockIndex === index) return;
                    
                    const movedItem = this.blocks[this.draggedBlockIndex];
                    this.blocks.splice(this.draggedBlockIndex, 1);
                    this.blocks.splice(index, 0, movedItem);
                    
                    this.draggedBlockIndex = index;
                    this.renderEditorList();
                    this.renderPreviewList();
                });
                el.addEventListener('dragend', () => {
                    this.draggedBlockIndex = null;
                    document.querySelectorAll('.dragging').forEach(e => e.classList.remove('dragging'));
                });
            }
        }

        const app = new App();

        // --- GLOBAL FUNCTIONS ---

        function toggleSidebar() {
            const sidebar = document.getElementById('editor-sidebar');
            const preview = document.getElementById('preview-panel');
            const icon = document.getElementById('toggle-icon');
            const isOpen = sidebar.classList.contains('translate-x-0');

            if (isOpen) {
                sidebar.classList.remove('translate-x-0');
                sidebar.classList.add('-translate-x-full');
                preview.classList.remove('md:ml-[400px]');
                icon.setAttribute('data-lucide', 'chevron-right');
            } else {
                sidebar.classList.add('translate-x-0');
                sidebar.classList.remove('-translate-x-full');
                preview.classList.add('md:ml-[400px]');
                icon.setAttribute('data-lucide', 'chevron-left');
            }
            lucide.createIcons();
        }

        function savePost() {
            const title = document.getElementById('post-title').value;
            const authors = document.querySelector('input[name="authors"]').value;
            
            if(!title || !authors) {
                alert("Please add a Title and Author(s) before saving.");
                return;
            }

            document.getElementById('hidden-title').value = title;
            document.getElementById('hidden-date').value = document.querySelector('input[name="date"]').value;
            document.getElementById('hidden-authors').value = authors;
            document.getElementById('hidden-thumbnail').value = document.getElementById('post-thumbnail').value;
            document.getElementById('hidden-fileName').value = document.querySelector('input[name="existingFileName"]').value;
            
            // Cleanup contentEditable artifacts if necessary (handled mostly by browser)
            document.getElementById('hidden-blocks').value = JSON.stringify(app.blocks);

            const form = document.getElementById('main-form');
            for (const [blockId, file] of Object.entries(app.fileInputs)) {
                form.append('image_' + blockId, file);
            }
            form.submit();
        }
        
        function confirmDelete(fileName) {
            document.getElementById('delete-fileName').value = fileName;
            document.getElementById('delete-modal').showModal();
        }

        document.getElementById('confirm-delete-btn').addEventListener('click', () => {
             document.getElementById('delete-form').submit();
        });

    </script>
</body>
</html>