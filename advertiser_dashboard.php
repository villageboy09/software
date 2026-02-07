<?php
session_start();
include '../config.php';

// --- Auth Check ---
if (!isset($_SESSION['advertiser_id'])) {
    header("Location: advertiser_login.php");
    exit();
}

$adv_id = $_SESSION['advertiser_id'];
$adv_name = $_SESSION['advertiser_name'];

// Initials for Avatar
$words = explode(" ", $adv_name);
$initials = "";
foreach ($words as $w) { $initials .= $w[0]; }
$initials = strtoupper(substr($initials, 0, 2));

// --- 0. HELPER FUNCTIONS ---
function redirectWithToast($msg, $isSuccess = true) {
    $_SESSION['toast'] = ['message' => $msg, 'type' => $isSuccess];
    header("Location: advertiser_dashboard.php");
    exit();
}

// Fixed Time Ago Helper (PHP 8.2+ Compatible)
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculate weeks manually to avoid modifying DateInterval
    $weeks = floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $string = array(
        'y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day',
        'h' => 'hour', 'i' => 'minute', 's' => 'second',
    );
    
    // Map values
    $values = [
        'y' => $diff->y, 'm' => $diff->m, 'w' => $weeks, 
        'd' => $days, 'h' => $diff->h, 'i' => $diff->i, 's' => $diff->s
    ];

    foreach ($string as $k => &$v) {
        if ($values[$k]) {
            $v = $values[$k] . ' ' . $v . ($values[$k] > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// File Upload Helper
function handleFileUpload($fileInputName, $existingUrl = null) {
    $targetDir = "../products/"; 
    $baseUrl = "https://kiosk.cropsync.in/products/"; 
    
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] === 0) {
        if (!empty($existingUrl)) {
            $oldFilename = basename($existingUrl);
            $oldFilePath = $targetDir . $oldFilename;
            if (file_exists($oldFilePath) && is_file($oldFilePath)) unlink($oldFilePath); 
        }
        $fileExt = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'avi'];
        
        if (in_array($fileExt, $allowed)) {
            $newFilename = time() . '_' . rand(1000, 9999) . '.' . $fileExt;
            $targetPath = $targetDir . $newFilename;
            if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $targetPath)) {
                return $baseUrl . $newFilename;
            }
        }
    }
    return $existingUrl;
}

// --- 1. HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. AJAX STATUS UPDATE
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'update_status') {
        $enq_id = $_POST['enquiry_id'];
        $new_status = $_POST['status'];
        $stmt = $conn->prepare("UPDATE enquiries SET status = ? WHERE enquiry_id = ? AND advertiser_id = ?");
        $stmt->bind_param("sii", $new_status, $enq_id, $adv_id);
        echo ($stmt->execute()) ? "success" : "error";
        exit; 
    }

    // B. PRODUCT MANAGEMENT (ADD / EDIT)
    if (isset($_POST['action']) && ($_POST['action'] == 'add_product' || $_POST['action'] == 'edit_product')) {
        
        $p_name = $_POST['product_name'];
        $p_cat = $_POST['category'];
        $p_price = $_POST['price'];
        $p_mrp = !empty($_POST['mrp']) ? $_POST['mrp'] : NULL;
        $p_desc = $_POST['description'];
        $p_region = !empty($_POST['region_id']) ? $_POST['region_id'] : NULL;
        
        // Toggles
        $p_stock = isset($_POST['in_stock']) ? 1 : 0;
        $p_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Files
        $old_img = $_POST['existing_image_1'] ?? null;
        $old_video = $_POST['existing_video'] ?? null;
        $p_img = handleFileUpload('image_file', $old_img);
        $p_video = handleFileUpload('video_file', $old_video);

        if ($_POST['action'] == 'add_product') {
            $p_code = 'TG' . rand(1000,9999) . 'P' . rand(10,99);
            $stmt = $conn->prepare("INSERT INTO products (product_code, category, advertiser_id, product_name, price, mrp, product_description, image_url_1, product_video_url, region_id, in_stock, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisddsssiii", $p_code, $p_cat, $adv_id, $p_name, $p_price, $p_mrp, $p_desc, $p_img, $p_video, $p_region, $p_stock, $p_active);
            if ($stmt->execute()) redirectWithToast("Product listed successfully!");
            else redirectWithToast("Error: " . $conn->error, false);
        } 
        else {
            $p_id = $_POST['product_id'];
            $check = $conn->prepare("SELECT product_id FROM products WHERE product_id = ? AND advertiser_id = ?");
            $check->bind_param("ii", $p_id, $adv_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE products SET product_name=?, category=?, price=?, mrp=?, product_description=?, image_url_1=?, product_video_url=?, region_id=?, in_stock=?, is_active=? WHERE product_id=?");
                $stmt->bind_param("ssddsssiiii", $p_name, $p_cat, $p_price, $p_mrp, $p_desc, $p_img, $p_video, $p_region, $p_stock, $p_active, $p_id);
                if ($stmt->execute()) redirectWithToast("Product updated successfully!");
                else redirectWithToast("Update failed.", false);
            } else redirectWithToast("Unauthorized access.", false);
        }
    }
}

// --- 2. FILTERS SETUP ---
$filter_days = isset($_GET['days']) ? intval($_GET['days']) : 30;
$filter_region = isset($_GET['region']) ? $_GET['region'] : '';
$filter_product = isset($_GET['product']) ? $_GET['product'] : '';

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

// Build SQL Conditions
$where_clauses = ["e.advertiser_id = ?"];
$params = [$adv_id];
$types = "i";

// Date Condition for Analytics Table
$date_condition = "AND stats_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
if ($filter_days == 3650) $date_condition = "AND 1=1";

// Date Condition for Enquiries
if ($filter_days != 3650) {
    $where_clauses[] = "e.enquiry_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params[] = $filter_days;
    $types .= "i";
}
if (!empty($filter_region)) {
    $where_clauses[] = "u.district = ?";
    $params[] = $filter_region;
    $types .= "s";
}
if (!empty($filter_product)) {
    $where_clauses[] = "e.product_id = ?";
    $params[] = $filter_product;
    $types .= "i";
}
$sql_condition = " WHERE " . implode(" AND ", $where_clauses);

// --- 3. ANALYTICS ---

// A. Traffic Stats (Views/Clicks from product_daily_stats)
// We need a separate query for this because it comes from a different table
$sql_traffic = "SELECT 
                SUM(view_count) as views, 
                SUM(click_count) as clicks 
                FROM product_daily_stats 
                WHERE advertiser_id = ? $date_condition";
$stmt = $conn->prepare($sql_traffic);
if ($filter_days != 3650) $stmt->bind_param("ii", $adv_id, $filter_days);
else $stmt->bind_param("i", $adv_id);
$stmt->execute();
$traffic = $stmt->get_result()->fetch_assoc();
$total_views = $traffic['views'] ?? 0;
$total_clicks = $traffic['clicks'] ?? 0;

// B. Enquiry Stats (Leads from enquiries table)
$sql_kpi = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN e.status = 'Interested' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN e.status IN ('Contacted', 'Purchased') THEN 1 ELSE 0 END) as converted
            FROM enquiries e 
            JOIN users u ON e.farmer_id = u.user_id
            $sql_condition";
$stmt = $conn->prepare($sql_kpi);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$kpi = $stmt->get_result()->fetch_assoc();

$total_leads = $kpi['total'] ?? 0;
$pending_leads = $kpi['pending'] ?? 0;
$converted_leads = $kpi['converted'] ?? 0;

// C. Calculated Ratios
$action_rate = ($total_leads > 0) ? round(($converted_leads / $total_leads) * 100) : 0;
$ctr = ($total_views > 0) ? round(($total_clicks / $total_views) * 100, 1) : 0;
$conversion_rate = ($total_clicks > 0) ? round(($total_leads / $total_clicks) * 100, 1) : 0;

// D. Lead Trend Graph
$sql_graph = "SELECT DATE(e.enquiry_date) as date, COUNT(*) as count 
              FROM enquiries e JOIN users u ON e.farmer_id = u.user_id
              $sql_condition 
              GROUP BY DATE(e.enquiry_date) ORDER BY date ASC";
$stmt = $conn->prepare($sql_graph);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$graph_res = $stmt->get_result();
$graph_data = [];
while($row = $graph_res->fetch_assoc()) { $graph_data[$row['date']] = $row['count']; }

$chart_labels = []; $chart_values = [];
for ($i = $filter_days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('M d', strtotime($d));
    $chart_values[] = isset($graph_data[$d]) ? $graph_data[$d] : 0;
}

// E. Product Breakdown (Pie Chart)
$sql_prod_pie = "SELECT p.product_name, COUNT(*) as count 
                 FROM enquiries e 
                 JOIN products p ON e.product_id = p.product_id
                 JOIN users u ON e.farmer_id = u.user_id
                 $sql_condition
                 GROUP BY p.product_id
                 ORDER BY count DESC LIMIT 5";
$stmt = $conn->prepare($sql_prod_pie);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$pie_res = $stmt->get_result();
$pie_labels = []; $pie_data = [];
while($row = $pie_res->fetch_assoc()) {
    $pie_labels[] = $row['product_name'];
    $pie_data[] = $row['count'];
}

// --- 4. LISTS & DATA ---

// NEW: FETCH LIVE INTERACTIONS
$sql_live = "SELECT i.interaction_type, i.created_at,
             u.name as farmer_name, u.profile_image_url, u.village,
             p.product_name, p.image_url_1
             FROM product_interactions i
             JOIN users u ON i.user_id = u.user_id
             JOIN products p ON i.product_id = p.product_id
             WHERE i.advertiser_id = ? 
             ORDER BY i.created_at DESC LIMIT 5"; // Last 5 actions
$stmt_live = $conn->prepare($sql_live);
$stmt_live->bind_param("i", $adv_id);
$stmt_live->execute();
$live_feed = $stmt_live->get_result();

// Dropdowns
$districts = $conn->query("SELECT DISTINCT u.district FROM enquiries e JOIN users u ON e.farmer_id = u.user_id WHERE e.advertiser_id = $adv_id AND u.district IS NOT NULL ORDER BY u.district");
$my_products_list = $conn->query("SELECT product_id, product_name FROM products WHERE advertiser_id = $adv_id ORDER BY product_name");

// Products Table Data
$sql_prods_table = "SELECT p.*, r.region_name,
                    COALESCE(SUM(s.view_count), 0) as total_views,
                    COALESCE(SUM(s.click_count), 0) as total_clicks
                    FROM products p 
                    LEFT JOIN regions r ON p.region_id = r.id 
                    LEFT JOIN product_daily_stats s ON p.product_id = s.product_id
                    WHERE p.advertiser_id = ? 
                    GROUP BY p.product_id
                    ORDER BY p.created_at DESC";
$stmt = $conn->prepare($sql_prods_table);
$stmt->bind_param("i", $adv_id);
$stmt->execute();
$products_table = $stmt->get_result();

// Regions for Modal
$regions = $conn->query("SELECT * FROM regions ORDER BY region_name ASC");

// Paginated Leads
$sql_count = "SELECT COUNT(*) as total FROM enquiries e JOIN users u ON e.farmer_id = u.user_id $sql_condition";
$stmt = $conn->prepare($sql_count);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_rows = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

$sql_feed = "SELECT e.enquiry_id, e.status, e.enquiry_date,
             u.name as farmer_name, u.phone_number, u.village, u.district, u.profile_image_url,
             p.product_name, p.image_url_1
             FROM enquiries e
             JOIN users u ON e.farmer_id = u.user_id
             JOIN products p ON e.product_id = p.product_id
             $sql_condition
             ORDER BY e.enquiry_date DESC LIMIT ? OFFSET ?";
$params_feed = $params; $params_feed[] = $limit; $params_feed[] = $offset;
$types_feed = $types . "ii";
$stmt = $conn->prepare($sql_feed);
$stmt->bind_param($types_feed, ...$params_feed);
$stmt->execute();
$leads = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?= htmlspecialchars($adv_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --accent: #22c55e;
            --accent-warm: #f97316;
            --accent-blue: #0ea5e9;
            --bg: #f5f7fb;
            --card: #ffffff;
            --text: #0f172a;
            --text-muted: #6b7280;
            --border: rgba(148, 163, 184, 0.25);
            --shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at top, #ffffff 0%, #f5f7fb 45%, #eef2ff 100%);
            color: var(--text);
            padding-bottom: 60px;
        }
        a { color: inherit; }
        
        /* Navbar */
        .navbar {
            background: rgba(255, 255, 255, 0.92);
            border-bottom: 1px solid var(--border);
            padding: 18px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }
        .brand-section { display: flex; align-items: center; gap: 16px; }
        .partner-logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(140deg, var(--primary), #7c3aed);
            color: white;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            letter-spacing: 1px;
            box-shadow: 0 12px 20px rgba(79, 70, 229, 0.35);
        }
        .partner-info { display: flex; flex-direction: column; }
        .partner-name { font-weight: 700; font-size: 16px; color: var(--text); }
        .partner-role { font-size: 12px; color: var(--text-muted); font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; }
        .logout-btn {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 999px;
            background: #f8fafc;
            transition: 0.2s ease;
            border: 1px solid var(--border);
        }
        .logout-btn:hover { background: #fee2e2; color: #ef4444; border-color: rgba(239, 68, 68, 0.4); }

        .container { max-width: 1240px; margin: 0 auto; padding: 30px 24px; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
            background: var(--card);
            border-radius: var(--radius-lg);
            padding: 28px 30px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 26px;
        }
        .page-header h1 { margin: 6px 0 8px; font-size: 28px; letter-spacing: -0.5px; }
        .page-header p { margin: 0; color: var(--text-muted); font-size: 14px; }
        .eyebrow { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--primary); font-weight: 700; }
        .header-metrics { display: flex; gap: 16px; flex-wrap: wrap; }
        .header-pill {
            background: #eef2ff;
            color: var(--primary);
            font-weight: 700;
            font-size: 12px;
            padding: 8px 14px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Modern Dropdown UI */
        .toolbar {
            background: white;
            padding: 18px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 30px;
            align-items: center;
            box-shadow: var(--shadow);
        }
        .filter-group { position: relative; }
        .filter-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
        .filter-select { 
            appearance: none; -webkit-appearance: none;
            padding: 12px 16px 12px 36px; border-radius: var(--radius-sm); border: 1px solid var(--border); 
            font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px; background: #f8fafc; 
            cursor: pointer; outline: none; transition: 0.2s; color: var(--text); min-width: 160px; font-weight: 600;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 256 256'%3E%3Cpath fill='%2364748b' d='M213.66,101.66l-80,80a8,8,0,0,1-11.32,0l-80-80A8,8,0,0,1,53.66,90.34L128,164.69l74.34-74.35a8,8,0,0,1,11.32,11.32Z'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px;
        }
        .filter-select:hover, .filter-select:focus { border-color: var(--primary); background-color: white; }
        .btn-apply {
            background: var(--text);
            color: white;
            border: none;
            padding: 12px 26px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            margin-left: auto;
            transition: 0.2s;
        }
        .btn-apply:hover { background: var(--primary); transform: translateY(-1px); }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            position: relative;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .stat-card:before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), #7c3aed, var(--accent));
        }
        .stat-title { color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; }
        .stat-value { font-size: 34px; font-weight: 800; color: var(--text); margin-top: 8px; }
        .stat-sub { font-size: 13px; font-weight: 600; margin-top: 4px; color: var(--text-muted); }
        .stat-icon { position: absolute; right: 24px; top: 24px; font-size: 24px; opacity: 0.2; color: var(--primary); }

        /* Charts */
        .charts-row { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 30px; }
        .chart-box {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            height: 340px;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow);
        }
        .chart-header { margin-bottom: 15px; font-weight: 700; font-size: 16px; }
        
        /* Tables */
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; margin-top: 40px; }
        .section-title { font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .add-btn {
            background: var(--text);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            font-size: 13px;
        }
        .add-btn:hover { background: var(--primary); }

        .table-wrapper { background: white; border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 16px 24px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); letter-spacing: 1px; }
        td { padding: 16px 24px; border-bottom: 1px solid var(--border); vertical-align: middle; font-size: 14px; }
        
        .user-cell { display: flex; align-items: center; gap: 12px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: #e2e8f0; border: 2px solid white; box-shadow: 0 0 0 1px var(--border); }
        .status-select { padding: 8px 12px; border-radius: 10px; border: 1px solid var(--border); background: white; font-size: 13px; font-weight: 700; cursor: pointer; font-family: 'Plus Jakarta Sans', sans-serif; }

        /* Badges & Pagination */
        .badge { font-size: 10px; padding: 4px 8px; border-radius: 100px; font-weight: 700; text-transform: uppercase; margin-right: 5px; }
        .badge-stock { background: #dcfce7; color: #15803d; }
        .badge-out { background: #fee2e2; color: #991b1b; }
        .badge-hidden { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
        .prod-row.hidden-product { opacity: 0.6; background: #f9fafb; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 30px; }
        .page-btn { padding: 8px 14px; border: 1px solid var(--border); background: white; border-radius: 8px; text-decoration: none; color: var(--text); font-size: 13px; font-weight: 600; transition: 0.2s; }
        .page-btn.active { background: var(--text); color: white; border-color: var(--text); }
        
        /* Modal, Toggles, Toast */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; width: 100%; max-width: 520px; padding: 30px; border-radius: 24px; max-height: 90vh; overflow-y: auto; position: relative; box-shadow: var(--shadow);}
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; }
        .form-input { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; box-sizing: border-box; background: #f8fafc; font-weight: 600; }
        .form-row { display: flex; gap: 15px; }
        .file-upload-box { border: 2px dashed var(--border); padding: 20px; text-align: center; border-radius: 12px; cursor: pointer; transition: 0.2s; background: #f8fafc; }
        .close-modal { position: absolute; top: 20px; right: 20px; cursor: pointer; font-size: 24px; color: var(--text-muted); }
        .toggle-wrapper { display: flex; align-items: center; gap: 10px; margin-top: 5px; }
        .switch { position: relative; display: inline-block; width: 40px; height: 22px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(18px); }
        .toast { position: fixed; top: 20px; right: 20px; background: #1e293b; color: white; padding: 12px 24px; border-radius: 50px; display: flex; align-items: center; gap: 10px; transform: translateY(-100px); transition: 0.3s; z-index: 2000; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .toast.show { transform: translateY(0); }
        .toast-success { background: #064e3b; border: 1px solid #10b981; }
        .toast-error { background: #7f1d1d; border: 1px solid #ef4444; }
        .trend-pill { font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #eef2ff; color: var(--primary); font-weight: 700; margin-left: 5px; }

        .feed-card {
            background: white;
            padding: 14px 20px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: 0.2s;
            box-shadow: var(--shadow);
        }
        .feed-card.is-click { background: #eef2ff; }
        .feed-card.is-view { background: #ffffff; }
        .feed-meta { flex-grow: 1; }
        .feed-meta strong { font-weight: 700; }
        .feed-time { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .feed-product { font-weight: 700; }

        @media (max-width: 960px) {
            .charts-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 720px) {
            .toolbar { flex-direction: column; align-items: stretch; }
            .btn-apply { margin-left: 0; width: 100%; }
            .form-row { flex-direction: column; }
        }
        
        @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(1.1); } 100% { opacity: 1; transform: scale(1); } }
    </style>
</head>
<body>

    <div id="toast" class="toast"><i class="ph ph-check-circle" style="font-size: 20px; color: #34d399;"></i><span id="toastMsg">Action Successful</span></div>
    <?php if(isset($_SESSION['toast'])): ?>
    <script>
        document.getElementById('toastMsg').innerText = "<?= $_SESSION['toast']['message'] ?>";
        document.getElementById('toast').className = "<?= $_SESSION['toast']['type'] ? 'toast toast-success show' : 'toast toast-error show' ?>";
        setTimeout(() => { document.getElementById('toast').classList.remove('show'); }, 3000);
    </script>
    <?php unset($_SESSION['toast']); endif; ?>

    <nav class="navbar">
        <div class="brand-section">
            <div class="partner-logo"><?= $initials ?></div>
            <div class="partner-info">
                <span class="partner-name"><?= htmlspecialchars($adv_name) ?></span>
                <span class="partner-role">Authorized Partner</span>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="ph ph-sign-out"></i> Logout</a>
    </nav>

    <div class="container">
        <div class="page-header">
            <div>
                <div class="eyebrow">Performance Overview</div>
                <h1>Welcome back, <?= htmlspecialchars($adv_name) ?></h1>
                <p>Track real-time engagement, product performance, and lead momentum in one premium workspace.</p>
            </div>
            <div class="header-metrics">
                <span class="header-pill"><i class="ph ph-sparkle"></i> <?= number_format($total_views) ?> impressions</span>
                <span class="header-pill"><i class="ph ph-users"></i> <?= number_format($total_leads) ?> leads</span>
                <span class="header-pill"><i class="ph ph-chart-line-up"></i> <?= $conversion_rate ?>% conversion</span>
            </div>
        </div>
        
        <form method="GET" class="toolbar">
            <div class="filter-group">
                <i class="ph ph-calendar-blank filter-icon"></i>
                <select name="days" class="filter-select">
                    <option value="7" <?= $filter_days == 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $filter_days == 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90" <?= $filter_days == 90 ? 'selected' : '' ?>>Last 3 Months</option>
                    <option value="3650" <?= $filter_days == 3650 ? 'selected' : '' ?>>All Time</option>
                </select>
            </div>
            
            <div class="filter-group">
                <i class="ph ph-map-pin filter-icon"></i>
                <select name="region" class="filter-select">
                    <option value="">All Regions</option>
                    <?php while($d = $districts->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($d['district']) ?>" <?= $filter_region == $d['district'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['district']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="filter-group">
                <i class="ph ph-package filter-icon"></i>
                <select name="product" class="filter-select">
                    <option value="">All Products</option>
                    <?php while($p = $my_products_list->fetch_assoc()): ?>
                        <option value="<?= $p['product_id'] ?>" <?= $filter_product == $p['product_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['product_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button type="submit" class="btn-apply">Apply Filters</button>
        </form>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Total Views</div>
                <div class="stat-value"><?= number_format($total_views) ?></div>
                <div class="stat-sub">Product Impressions</div>
                <i class="ph ph-eye stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-title">Total Leads</div>
                <div class="stat-value"><?= number_format($total_leads) ?></div>
                <div class="stat-sub">Interested Farmers</div>
                <i class="ph ph-users stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-title">CTR</div>
                <div class="stat-value"><?= $ctr ?>%</div>
                <div class="stat-sub">Views to Click</div>
                <i class="ph ph-cursor-click stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-title">Conversion</div>
                <div class="stat-value"><?= $conversion_rate ?>%</div>
                <div class="stat-sub">Lead to Contact</div>
                <i class="ph ph-check-circle stat-icon"></i>
            </div>
        </div>

        <div class="charts-row">
            <div class="chart-box">
                <div class="chart-header">Daily Lead Trend</div>
                <div style="flex-grow:1; position:relative;"><canvas id="trendChart"></canvas></div>
            </div>
            <div class="chart-box">
                <div class="chart-header">Top 5 Products</div>
                <div style="flex-grow:1; position:relative; display:flex; justify-content:center;"><canvas id="prodChart"></canvas></div>
            </div>
        </div>

        <div class="section-header" style="margin-top: 40px;">
            <div class="section-title">
                <i class="ph ph-broadcast" style="color:#f97316; animation: pulse 2s infinite;"></i> 
                Live Visitor Feed
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 40px;">
            <?php if($live_feed->num_rows > 0): ?>
                <?php while($feed = $live_feed->fetch_assoc()): 
                    $is_click = ($feed['interaction_type'] == 'click');
                    $action_text = $is_click ? "clicked on" : "viewed";
                    $icon = $is_click ? "ph-cursor-click" : "ph-eye";
                    $color = $is_click ? "#4f46e5" : "#64748b"; 
                    $card_class = $is_click ? "feed-card is-click" : "feed-card is-view";
                    $time_ago = time_elapsed_string($feed['created_at']);
                ?>
                <div class="<?= $card_class ?>">
                    <img src="<?= $feed['profile_image_url'] ?: 'https://ui-avatars.com/api/?name='.$feed['farmer_name'] ?>" 
                         style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid white; box-shadow: 0 0 0 1px #e2e8f0;">
                    
                    <div class="feed-meta">
                        <div style="font-size: 14px; color: var(--text);">
                            <strong><?= htmlspecialchars($feed['farmer_name']) ?></strong>
                            <span style="color: var(--text-muted);">from <?= htmlspecialchars($feed['village']) ?></span>
                            <span style="color: <?= $color ?>; font-weight: 700; margin: 0 4px;">
                                <i class="ph <?= $icon ?>"></i> <?= $action_text ?>
                            </span>
                            <span class="feed-product"><?= htmlspecialchars($feed['product_name']) ?></span>
                        </div>
                        <div class="feed-time"><?= $time_ago ?></div>
                    </div>
                    <img src="<?= htmlspecialchars($feed['image_url_1']) ?>" style="width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid var(--border);">
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 20px; color: var(--text-muted); background: white; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow);">
                    No recent activity detected.
                </div>
            <?php endif; ?>
        </div>

        <div class="section-header">
            <div class="section-title"><i class="ph ph-address-book" style="color:var(--primary);"></i> Enquiries List</div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Farmer Details</th>
                        <th>Product Interest</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($leads->num_rows > 0): ?>
                        <?php while($lead = $leads->fetch_assoc()): ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:13px; font-weight:500;">
                                <?= date('M d, Y', strtotime($lead['enquiry_date'])) ?><br>
                                <span style="font-size:11px;"><?= date('h:i A', strtotime($lead['enquiry_date'])) ?></span>
                            </td>
                            <td>
                                <div class="user-cell">
                                    <img src="<?= $lead['profile_image_url'] ?: 'https://ui-avatars.com/api/?name='.$lead['farmer_name'] ?>" class="avatar">
                                    <div>
                                        <div style="font-weight:600;"><?= htmlspecialchars($lead['farmer_name']) ?></div>
                                        <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($lead['village']) ?>, <?= htmlspecialchars($lead['district']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="display:flex; align-items:center;">
                                    <img src="<?= htmlspecialchars($lead['image_url_1']) ?>" style="width:30px; height:30px; border-radius:4px; margin-right:8px; object-fit:cover; border:1px solid var(--border);">
                                    <span style="font-weight:500; font-size:13px;"><?= htmlspecialchars($lead['product_name']) ?></span>
                                </div>
                            </td>
                            <td>
                                <select class="status-select" onchange="updateStatus(this, <?= $lead['enquiry_id'] ?>)" 
                                    style="color: <?= $lead['status']=='Interested'?'#ea580c':($lead['status']=='Purchased'?'#7c3aed':'#059669') ?>; border-color:var(--border);">
                                    <option value="Interested" <?= $lead['status']=='Interested'?'selected':'' ?>>Interested</option>
                                    <option value="Contacted" <?= $lead['status']=='Contacted'?'selected':'' ?>>Contacted</option>
                                    <option value="Purchased" <?= $lead['status']=='Purchased'?'selected':'' ?>>Purchased</option>
                                </select>
                            </td>
                            <td>
                                <a href="tel:<?= $lead['phone_number'] ?>" style="color:var(--text); text-decoration:none; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:5px; background:#f1f5f9; padding:6px 12px; border-radius:6px; transition:0.2s;">
                                    <i class="ph ph-phone"></i> Call
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--text-muted);">No leads found for these filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php $q = $_GET; ?>
            <?php if($page > 1): $q['page'] = $page-1; ?>
                <a href="?<?= http_build_query($q) ?>" class="page-btn">Previous</a>
            <?php endif; ?>
            <?php for($i=1; $i<=$total_pages; $i++): $q['page'] = $i; ?>
                <a href="?<?= http_build_query($q) ?>" class="page-btn <?= $i==$page ? 'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if($page < $total_pages): $q['page'] = $page+1; ?>
                <a href="?<?= http_build_query($q) ?>" class="page-btn">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="section-header">
            <div class="section-title"><i class="ph ph-package" style="color:var(--accent-blue);"></i> Product Catalog</div>
            <button class="add-btn" onclick="openModal('add')"><i class="ph ph-plus-circle"></i> Add New Product</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Status</th>
                        <th>Views <span class="trend-pill">All Time</span></th>
                        <th>Clicks <span class="trend-pill">All Time</span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($prod = $products_table->fetch_assoc()): ?>
                    <tr class="prod-row <?= ($prod['is_active'] == 0) ? 'hidden-product' : '' ?>">
                        <td>
                            <div class="user-cell">
                                <img src="<?= htmlspecialchars($prod['image_url_1']) ?>" style="width:40px; height:40px; border-radius:8px; object-fit:cover; border:1px solid var(--border);">
                                <div>
                                    <div style="font-weight:600;"><?= htmlspecialchars($prod['product_name']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($prod['category']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if($prod['in_stock'] == 1): ?>
                                <span class="badge badge-stock">In Stock</span>
                            <?php else: ?>
                                <span class="badge badge-out">Out of Stock</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;"><?= $prod['total_views'] ?></td>
                        <td><?= $prod['total_clicks'] ?></td>
                        <td><div style="cursor:pointer; color:var(--text-muted);" onclick='openModal("edit", <?= json_encode($prod) ?>)'><i class="ph ph-pencil-simple" style="font-size:20px;"></i></div></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

    </div>

    <div id="productModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-top:0;">List New Product</h2>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add_product">
                <input type="hidden" name="product_id" id="prodId">
                <input type="hidden" name="existing_image_1" id="existImg">
                <input type="hidden" name="existing_video" id="existVid">
                
                <div class="form-row" style="background: #f8fafc; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">In Stock?</label>
                        <div class="toggle-wrapper">
                            <label class="switch"><input type="checkbox" name="in_stock" id="pStock" checked><span class="slider"></span></label>
                            <span style="font-size:13px;">Yes</span>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:0; border-left: 1px solid #e2e8f0; padding-left: 20px;">
                        <label class="form-label">Visible?</label>
                        <div class="toggle-wrapper">
                            <label class="switch"><input type="checkbox" name="is_active" id="pActive" checked><span class="slider"></span></label>
                            <span style="font-size:13px;">Yes</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Product Name</label>
                    <input type="text" name="product_name" id="pName" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Price (₹)</label>
                        <input type="number" name="price" id="pPrice" class="form-input" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">MRP (₹)</label>
                        <input type="number" name="mrp" id="pMrp" class="form-input">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Category</label>
                        <select name="category" id="pCat" class="form-input">
                            <option value="మెషీన్">Machinery</option>
                            <option value="పనిముట్లు">Tools</option>
                            <option value="విత్తనాలు/ఎరువులు">Inputs</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Region</label>
                        <select name="region_id" id="pRegion" class="form-input">
                            <option value="">All India</option>
                            <?php 
                            $regions->data_seek(0); 
                            while($reg = $regions->fetch_assoc()): ?>
                                <option value="<?= $reg['id'] ?>"><?= htmlspecialchars($reg['region_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Image</label>
                    <div class="file-upload-box" onclick="document.getElementById('imgInput').click()">
                        <i class="ph ph-camera" style="font-size:24px; color:var(--primary);"></i>
                        <div id="imgPreviewText" style="font-size:13px; margin-top:5px; color:var(--text-muted);">Tap to Upload</div>
                    </div>
                    <input type="file" name="image_file" id="imgInput" accept="image/*" style="display:none;" onchange="previewFile(this, 'imgPreviewText')">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Video (Optional)</label>
                    <div class="file-upload-box" onclick="document.getElementById('vidInput').click()">
                        <i class="ph ph-video-camera" style="font-size:24px; color:var(--accent-blue);"></i>
                        <div id="vidPreviewText" style="font-size:13px; margin-top:5px; color:var(--text-muted);">Tap to Upload</div>
                    </div>
                    <input type="file" name="video_file" id="vidInput" accept="video/*" style="display:none;" onchange="previewFile(this, 'vidPreviewText')">
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="pDesc" class="form-input" rows="3" required></textarea>
                </div>

                <button type="submit" id="submitBtn" class="add-btn" style="width:100%; justify-content:center;">List Product</button>
            </form>
        </div>
    </div>

    <script>
        // Charts
        const fontConfig = { family: 'Plus Jakarta Sans', size: 11 };
        
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Leads', data: <?= json_encode($chart_values) ?>,
                    borderColor: '#4f46e5', backgroundColor: 'rgba(79, 70, 229, 0.12)',
                    tension: 0.3, fill: true, pointRadius: 2
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: fontConfig } }, y: { beginAtZero: true, border: { display: false }, ticks: { font: fontConfig } } } }
        });

        new Chart(document.getElementById('prodChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($pie_labels) ?>,
                datasets: [{
                    data: <?= json_encode($pie_data) ?>,
                    backgroundColor: ['#4f46e5', '#22c55e', '#f97316', '#0ea5e9', '#f43f5e'],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: fontConfig, usePointStyle: true, padding: 15 } } }, cutout: '75%' }
        });

        // UI Logic
        function updateStatus(select, id) {
            select.style.opacity = 0.5;
            const fd = new FormData(); fd.append('ajax_action', 'update_status'); fd.append('enquiry_id', id); fd.append('status', select.value);
            fetch('', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
                select.style.opacity = 1;
                const colors = {'Interested':'#ea580c', 'Purchased':'#7c3aed', 'Contacted':'#059669'};
                select.style.color = colors[select.value];
            });
        }

        function openModal(mode, product = null) {
            document.getElementById('productModal').classList.add('active');
            document.querySelector('form').reset();
            const title = document.getElementById('modalTitle');
            const action = document.getElementById('formAction');
            const btn = document.getElementById('submitBtn');
            
            document.getElementById('pStock').checked = true;
            document.getElementById('pActive').checked = true;
            document.getElementById('imgPreviewText').innerText = "Tap to Upload";

            if (mode === 'edit' && product) {
                title.innerText = "Edit Product"; action.value = "edit_product"; btn.innerText = "Update";
                document.getElementById('prodId').value = product.product_id;
                document.getElementById('pName').value = product.product_name;
                document.getElementById('pPrice').value = product.price;
                document.getElementById('pMrp').value = product.mrp;
                document.getElementById('pCat').value = product.category;
                document.getElementById('pRegion').value = product.region_id || "";
                document.getElementById('pDesc').value = product.product_description;
                document.getElementById('existImg').value = product.image_url_1;
                document.getElementById('existVid').value = product.product_video_url;
                document.getElementById('pStock').checked = (product.in_stock == 1);
                document.getElementById('pActive').checked = (product.is_active == 1);
                if(product.image_url_1) document.getElementById('imgPreviewText').innerText = "Current Image Selected";
            } else {
                title.innerText = "List New Product"; action.value = "add_product"; btn.innerText = "List";
            }
        }
        function closeModal() { document.getElementById('productModal').classList.remove('active'); }
        function previewFile(input, textId) { if(input.files[0]) document.getElementById(textId).innerText = input.files[0].name; }
        window.onclick = function(e) { if (e.target == document.getElementById('productModal')) closeModal(); }
    </script>
</body>
</html>
 
