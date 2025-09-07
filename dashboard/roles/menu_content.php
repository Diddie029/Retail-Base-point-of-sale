<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM permissions p 
        JOIN role_permissions rp ON p.id = rp.permission_id 
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check permissions - Admin has full access, others need specific permissions
$role_name = $_SESSION['role_name'] ?? 'User';
$isAdmin = (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator' ||
    hasPermission('manage_roles', $permissions) ||
    hasPermission('manage_users', $permissions)
);

if (!$isAdmin && !hasPermission('manage_roles', $permissions) && !hasPermission('manage_menu_content', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_content') {
        $section_key = trim($_POST['section_key']);
        $page_name = trim($_POST['page_name']);
        $icon = trim($_POST['icon']);
        $label = trim($_POST['label']);
        
        // Get URL from page name
        $url = isset($available_pages[$page_name]) ? $available_pages[$page_name] : '';
        
        // Validation
        $errors = [];
        if (empty($section_key)) {
            $errors[] = "Section key is required";
        }
        if (empty($page_name)) {
            $errors[] = "Page selection is required";
        }
        if (empty($url)) {
            $errors[] = "Selected page is not valid";
        }
        if (empty($label)) {
            $errors[] = "Label is required";
        }
        
        if (empty($errors)) {
            // Check if section exists
            $stmt = $conn->prepare("SELECT id FROM menu_sections WHERE section_key = :key");
            $stmt->bindParam(':key', $section_key);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                $errors[] = "Menu section does not exist";
            } else {
                try {
                    // Add to dynamic_navigation.php content mappings
                    $dynamicFile = __DIR__ . '/../../include/dynamic_navigation.php';
                    $content = file_get_contents($dynamicFile);
                    
                    // Find the section content mappings array
                    $pattern = '/\$sectionContentMappings\s*=\s*\[(.*?)\];/s';
                    if (preg_match($pattern, $content, $matches)) {
                        $mappingsContent = $matches[1];
                        
                        // Check if section key exists in mappings
                        if (strpos($mappingsContent, "'$section_key'") !== false) {
                            // Add new item to existing section
                            $newItem = "['$url', '$icon', '$label']";
                            $sectionPattern = "/'$section_key'\s*=>\s*\[(.*?)\]/s";
                            
                            if (preg_match($sectionPattern, $mappingsContent, $sectionMatches)) {
                                $sectionContent = $sectionMatches[1];
                                $updatedSectionContent = $sectionContent . ",\n                " . $newItem;
                                $updatedMappings = str_replace($sectionMatches[0], "'$section_key' => [$updatedSectionContent]", $mappingsContent);
                                $updatedContent = str_replace($mappingsContent, $updatedMappings, $content);
                                
                                file_put_contents($dynamicFile, $updatedContent);
                                $success_message = "Menu content added successfully!";
                            } else {
                                $error_message = "Could not find section in mappings";
                            }
                        } else {
                            // Add new section with content
                            $newSection = "'$section_key' => [\n                ['$url', '$icon', '$label']\n            ],";
                            $updatedMappings = $mappingsContent . ",\n        " . $newSection;
                            $updatedContent = str_replace($mappingsContent, $updatedMappings, $content);
                            
                            file_put_contents($dynamicFile, $updatedContent);
                            $success_message = "New section and content added successfully!";
                        }
                    } else {
                        $error_message = "Could not parse dynamic navigation file";
                    }
                } catch (Exception $e) {
                    $error_message = "Error adding menu content: " . $e->getMessage();
                }
            }
        }
        
        if (!empty($errors)) {
            $error_message = implode('<br>', $errors);
        }
    }
}

// Get all menu sections
$stmt = $conn->query("
    SELECT section_key, section_name 
    FROM menu_sections 
    WHERE is_active = 1 
    ORDER BY sort_order, section_name
");
$menu_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sync pages with database (discover new pages)
syncPagesWithDatabase($conn);

// Get available pages based on user permissions
$available_pages = getAvailablePagesForUser($conn, $isAdmin, $permissions);

// Get pages grouped by section for filtering
$pages_by_section = getAvailablePagesBySection($conn, $isAdmin, $permissions);

// Get section to category mapping
$section_category_mapping = getSectionToCategoryMapping();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Content Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .content-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Include Sidebar -->
            <?php include '../../include/navmenu.php'; ?>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-gear me-2"></i>Menu Content Management
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="menu_sections.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i>Back to Menu Sections
                        </a>
                        <?php if ($isAdmin): ?>
                        <a href="manage_pages.php" class="btn btn-outline-primary">
                            <i class="bi bi-gear me-1"></i>Manage Pages
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Add New Content -->
                    <div class="col-12 mb-4">
                        <div class="content-card">
                            <h5 class="mb-3">
                                <i class="bi bi-plus-circle me-2"></i>Add Menu Content
                            </h5>
                            <p class="text-muted mb-4">Add new menu items to existing sections or create content for new sections.</p>
                            
                            <?php if ($isAdmin): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-shield-check me-2"></i>
                                <strong>Admin Access:</strong> You have access to all available pages in the system.
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-lock me-2"></i>
                                <strong>Limited Access:</strong> You can only add menu items for pages you have permission to access. 
                                Contact your administrator to request access to additional pages.
                            </div>
                            <?php endif; ?>
                            
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="action" value="add_content">
                                
                                <div class="col-md-3">
                                    <label class="form-label">Section <span class="text-danger">*</span></label>
                                    <select class="form-select" name="section_key" required>
                                        <option value="">Select Section</option>
                                        <?php foreach ($menu_sections as $section): ?>
                                        <option value="<?php echo htmlspecialchars($section['section_key']); ?>">
                                            <?php echo htmlspecialchars($section['section_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Page <span class="text-danger">*</span></label>
                                    <select class="form-select" name="page_name" required>
                                        <option value="">Select Page</option>
                                    </select>
                                    <div class="form-text" id="pageHelperText">
                                        Choose a section first to see available pages
                                        <?php if (!$isAdmin): ?>
                                        <br><small class="text-muted">Limited by your permissions</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Icon</label>
                                    <input type="text" class="form-control" name="icon" 
                                           placeholder="e.g., bi-graph-up" value="bi-circle">
                                    <div class="form-text">Bootstrap Icons class</div>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Label <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="label" 
                                           placeholder="e.g., Sales Reports" required>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-1"></i>Add Menu Content
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Current Content Overview -->
                    <div class="col-12">
                        <div class="content-card">
                            <h5 class="mb-3">
                                <i class="bi bi-list-ul me-2"></i>Current Menu Content
                            </h5>
                            <p class="text-muted mb-4">Overview of all menu sections and their content items.</p>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Note:</strong> Menu content is managed through the dynamic navigation system. 
                                New content added here will appear in the navigation for all roles that have access to the respective section.
                            </div>
                            
                            <div class="row">
                                <?php foreach ($menu_sections as $section): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="bi bi-folder me-2"></i>
                                                <?php echo htmlspecialchars($section['section_name']); ?>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <p class="text-muted small mb-2">
                                                Section: <code><?php echo htmlspecialchars($section['section_key']); ?></code>
                                            </p>
                                            <p class="text-muted small">
                                                Content items are defined in the dynamic navigation system. 
                                                Use the form above to add new items to this section.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Page selection functionality with section filtering
        document.addEventListener('DOMContentLoaded', function() {
            const sectionSelect = document.querySelector('select[name="section_key"]');
            const pageSelect = document.querySelector('select[name="page_name"]');
            const formText = document.getElementById('pageHelperText');
            
            if (pageSelect && formText) {
                // Available pages data
                const availablePages = <?php echo json_encode($available_pages); ?>;
                const pagesBySection = <?php echo json_encode($pages_by_section); ?>;
                const sectionCategoryMapping = <?php echo json_encode($section_category_mapping); ?>;
                const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
                
                // Function to filter pages based on selected section
                function filterPagesBySection(selectedSection) {
                    // Show loading state
                    pageSelect.innerHTML = '<option value="">Loading pages...</option>';
                    pageSelect.disabled = true;
                    formText.innerHTML = 'Filtering pages for selected section...';
                    formText.className = 'form-text text-info';
                    
                    // Small delay to show loading state
                    setTimeout(() => {
                        // Clear current options except the first one
                        pageSelect.innerHTML = '<option value="">Select Page</option>';
                        pageSelect.disabled = false;
                        
                        if (!selectedSection) {
                            // If no section selected, show all pages
                            Object.keys(availablePages).forEach(pageName => {
                                const option = document.createElement('option');
                                option.value = pageName;
                                option.textContent = pageName;
                                pageSelect.appendChild(option);
                            });
                            formText.innerHTML = 'Choose from ' + Object.keys(availablePages).length + ' available pages' +
                                (isAdmin ? '' : '<br><small class="text-muted">Limited by your permissions</small>');
                            formText.className = 'form-text';
                            return;
                        }
                    
                        // Get categories for the selected section
                        const categories = sectionCategoryMapping[selectedSection] || [];
                        let filteredPages = [];
                        
                        // Collect pages from relevant categories
                        categories.forEach(category => {
                            if (pagesBySection[category]) {
                                Object.keys(pagesBySection[category]).forEach(pageName => {
                                    filteredPages.push({
                                        name: pageName,
                                        url: pagesBySection[category][pageName]
                                    });
                                });
                            }
                        });
                        
                        // Sort pages alphabetically
                        filteredPages.sort((a, b) => a.name.localeCompare(b.name));
                        
                        // Add filtered pages to dropdown
                        filteredPages.forEach(page => {
                            const option = document.createElement('option');
                            option.value = page.name;
                            option.textContent = page.name;
                            pageSelect.appendChild(option);
                        });
                        
                        // Update helper text
                        const count = filteredPages.length;
                        formText.innerHTML = 'Choose from ' + count + ' pages in this section' +
                            (isAdmin ? '' : '<br><small class="text-muted">Limited by your permissions</small>');
                        formText.className = 'form-text';
                        
                        // Reset page selection
                        pageSelect.value = '';
                    }, 100); // Small delay for better UX
                }
                
                // Section change handler
                if (sectionSelect) {
                    sectionSelect.addEventListener('change', function() {
                        filterPagesBySection(this.value);
                    });
                }
                
                // Page change handler
                pageSelect.addEventListener('change', function() {
                    const selectedPage = this.value;
                    if (selectedPage && availablePages[selectedPage]) {
                        formText.innerHTML = 'URL: ' + availablePages[selectedPage] + 
                            (isAdmin ? '' : '<br><small class="text-muted">Limited by your permissions</small>');
                        formText.className = 'form-text text-success';
                    } else {
                        // Get current section to show appropriate message
                        const currentSection = sectionSelect ? sectionSelect.value : '';
                        if (currentSection) {
                            const categories = sectionCategoryMapping[currentSection] || [];
                            let filteredPages = [];
                            categories.forEach(category => {
                                if (pagesBySection[category]) {
                                    Object.keys(pagesBySection[category]).forEach(pageName => {
                                        filteredPages.push(pageName);
                                    });
                                }
                            });
                            formText.innerHTML = 'Choose from ' + filteredPages.length + ' pages in this section' +
                                (isAdmin ? '' : '<br><small class="text-muted">Limited by your permissions</small>');
                        } else {
                            formText.innerHTML = 'Choose from ' + Object.keys(availablePages).length + ' available pages' +
                                (isAdmin ? '' : '<br><small class="text-muted">Limited by your permissions</small>');
                        }
                        formText.className = 'form-text';
                    }
                });
                
                // Initialize with all pages if no section is pre-selected
                if (!sectionSelect || !sectionSelect.value) {
                    filterPagesBySection('');
                }
                
                // Show total available pages count
                const totalPages = Object.keys(availablePages).length;
                console.log('Available pages for user:', totalPages, isAdmin ? '(Admin - Full Access)' : '(Limited Access)');
            }
        });
    </script>
</body>
</html>
