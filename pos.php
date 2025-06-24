<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

// “pos” izni olmayanlar erişemesin
if (!user_has_perm('pos')) {
    http_response_code(403);
    exit('<h1>403 – Yetki Yok</h1>');
}

// — Ajax endpoint’leri 

// Ürün autocomplete (isim ve SKU ile arama)
if (isset($_GET['action']) && $_GET['action'] === 'products') {
    header('Content-Type: application/json');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("
        SELECT id, name, price, sku
        FROM products
        WHERE is_active = 1
          AND (name LIKE ? OR sku LIKE ?)
        ORDER BY name
        LIMIT 10
    ");
    $stmt->execute([$q, $q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Müşteri autocomplete
if (isset($_GET['action']) && $_GET['action'] === 'customers') {
    header('Content-Type: application/json');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("
        SELECT id, name
        FROM customers
        WHERE name LIKE ?
        ORDER BY name
        LIMIT 10
    ");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- AJAX: Get Open Tables (masa durumlarını sorgula) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_open_tables') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("
      SELECT DISTINCT p.table_id
      FROM POSorders p
      WHERE p.status = 'open'
        AND EXISTS (
          SELECT 1
          FROM POSorder_items poi
          WHERE poi.posorder_id = p.id
        )
    ");
    $stmt->execute();
    $open = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($open);
    exit;
}

// --- AJAX: Get Empty Tables (boş masaları sorgula) ---
if (isset($_GET['action']) && $_GET['action'] === 'getEmptyTables') {
    header('Content-Type: application/json');
    $tables = $pdo
      ->query("SELECT id, table_number AS name FROM tables WHERE is_occupied = 0 ORDER BY table_number")
      ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'tables' => $tables]);
    exit;
}

// --- AJAX: Transfer Table (masa aktar) ---
if (isset($_POST['action']) && $_POST['action'] === 'transferTable') {
    header('Content-Type: application/json');
    $from = intval($_POST['from_table'] ?? 0);
    $to   = intval($_POST['to_table']   ?? 0);

    try {
        $pdo->beginTransaction();

        // 1) POSorders tablosunu güncelle
        $stmt = $pdo->prepare("
          UPDATE POSorders
          SET table_id = ?
          WHERE table_id = ?
            AND status = 'open'
        ");
        $stmt->execute([$to, $from]);

        // 2) Mutfak kuyruğundaki table_id’leri de yeni masaya taşı
        $pdo->prepare("
          UPDATE kitchen_queue
          SET table_id = ?
          WHERE table_id = ?
        ")->execute([$to, $from]);

        // 3) Masaların doluluk bilgisini güncelle
        $pdo->prepare("UPDATE tables SET is_occupied = 0 WHERE id = ?")
            ->execute([$from]);
        $pdo->prepare("UPDATE tables SET is_occupied = 1 WHERE id = ?")
            ->execute([$to]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// --- AJAX: Hold Order (open_account olarak işaretle) ---
if (isset($_POST['action']) && $_POST['action'] === 'hold_order') {
    header('Content-Type: application/json');
    $orderId = intval($_POST['order_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE POSorders SET payment_type = 'open_account' WHERE id = ?");
    $stmt->execute([$orderId]);
    echo json_encode(['success' => true]);
    exit;
}

// --- AJAX: Cancel Order (siparişi iptal et & nedene göre kaydet) ---
if (isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    $orderId = intval($_POST['order_id'] ?? 0);
    $tableId = intval($_POST['table_id']  ?? 0);
    $reason  = trim($_POST['reason']     ?? '');
    $stmt = $pdo->prepare("
      UPDATE POSorders
      SET status        = 'cancelled',
          is_cancelled  = 1,
          cancel_reason = ?
      WHERE id = ?
    ");
    $stmt->execute([$reason, $orderId]);
    $pdo->prepare("UPDATE tables SET is_occupied = 0 WHERE id = ?")->execute([$tableId]);
    echo json_encode(['success' => true]);
    exit;
}

// Kategori listesi
if (isset($_GET['action']) && $_GET['action'] === 'categories') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("
        SELECT
          c.id,
          c.name,
          c.image,
          COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p
          ON p.category_id = c.id AND p.is_active = 1
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Alt-kategori listesi
if (isset($_GET['action']) && $_GET['action'] === 'subcategories') {
    header('Content-Type: application/json');
    if (!empty($_GET['category_id'])) {
        $stmt = $pdo->prepare("
            SELECT id, name
            FROM subcategories
            WHERE category_id = ?
            ORDER BY name
        ");
        $stmt->execute([intval($_GET['category_id'])]);
    } else {
        $stmt = $pdo->query("
            SELECT id, name, category_id
            FROM subcategories
            ORDER BY name
        ");
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

//  Masa seçimi / açık POSorder yükleme AJAX handler’ı —
if (isset($_GET['action']) && $_GET['action'] === 'select_table') {
    header('Content-Type: application/json');
    $tableId = intval($_GET['table_id'] ?? 0);
    $stmt = $pdo->prepare("
      SELECT id, invoice_no, status, payment_type
      FROM POSorders
      WHERE table_id = ? AND status = 'open'
      LIMIT 1
    ");
    $stmt->execute([$tableId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
      $invoiceNo = 'POS'.date('YmdHis');
      $ins = $pdo->prepare("
        INSERT INTO POSorders
          (invoice_no, table_id, status, payment_type, created_at)
        VALUES
          (?, ?, 'open', 'cash', NOW())
      ");
      $ins->execute([$invoiceNo, $tableId]);
      $order = [
        'id'           => $pdo->lastInsertId(),
        'invoice_no'   => $invoiceNo,
        'status'       => 'open',
        'payment_type' => 'cash'
      ];
    }
    $stmt2 = $pdo->prepare("
      SELECT
        poi.product_id,
        p.name        AS product_name,
        poi.quantity,
        poi.unit_price
      FROM POSorder_items poi
      JOIN products p ON p.id = poi.product_id
      WHERE poi.posorder_id = ?
    ");
    $stmt2->execute([$order['id']]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
      'success'      => true,
      'order_id'     => (int)$order['id'],
      'invoice_no'   => $order['invoice_no'],
      'status'       => $order['status'],
      'payment_type' => $order['payment_type'],
      'items'        => $items
    ]);
    exit;
}

// — Sayfa yüklemede çekilecek veriler —

// 1) Masalar listesi
$stmtTables = $pdo->prepare("
    SELECT id, table_number
    FROM tables
    ORDER BY table_number
");
$stmtTables->execute();
$tables = $stmtTables->fetchAll(PDO::FETCH_ASSOC);

// 1.a) Her masann en son POSorders kaydın al; eğer o kayıt status='open' ise dolu say
$stmtOpen = $pdo->prepare("
    SELECT p.table_id
    FROM POSorders p
    JOIN (
      SELECT table_id, MAX(id) AS max_id
      FROM POSorders
      GROUP BY table_id
    ) latest
      ON p.table_id = latest.table_id
     AND p.id       = latest.max_id
    -- Sadece içinde gerekten kalem olan açık siparileri al
    WHERE p.status = 'open'
      AND EXISTS (
        SELECT 1
        FROM POSorder_items poi
        WHERE poi.posorder_id = p.id
        LIMIT 1
      )
");
$stmtOpen->execute();
$openTables = array_map('intval', array_column($stmtOpen->fetchAll(PDO::FETCH_ASSOC), 'table_id'));

// 2) Kategoriler
$stmtCats = $pdo->prepare("
    SELECT
      c.id,
      c.name,
      c.image,
      COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p
      ON p.category_id = c.id AND p.is_active = 1
    GROUP BY c.id
    ORDER BY c.name
");
$stmtCats->execute();
$categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// 3) Alt-kategoriler
$stmtSubcats = $pdo->prepare("
    SELECT id, category_id, name
    FROM subcategories
    ORDER BY name
");
$stmtSubcats->execute();
$subcategories = $stmtSubcats->fetchAll(PDO::FETCH_ASSOC);

// 4) Ürünler (isteğe bağlı filtre)
$where = ['is_active = 1'];
$params = [];
if (!empty($_GET['category_id'])) {
    $where[]  = 'category_id = ?';
    $params[] = intval($_GET['category_id']);
}
if (!empty($_GET['sub_category'])) {
    $where[]  = 'sub_category = ?';
    $params[] = intval($_GET['sub_category']);
}
$sql = "
    SELECT id, name, price, image_path, category_id, sub_category
    FROM products
    WHERE " . implode(' AND ', $where) . "
    ORDER BY name
";
$stmtProds = $pdo->prepare($sql);
$stmtProds->execute($params);
$products = $stmtProds->fetchAll(PDO::FETCH_ASSOC);



// 2) Kategoriler
$stmtCats = $pdo->prepare("
    SELECT
      c.id,
      c.name,
      c.image,
      COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p
      ON p.category_id = c.id AND p.is_active = 1
    GROUP BY c.id
    ORDER BY c.name
");
$stmtCats->execute();
$categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// 3) Alt-kategoriler
$stmtSubcats = $pdo->prepare("
    SELECT id, category_id, name
    FROM subcategories
    ORDER BY name
");
$stmtSubcats->execute();
$subcategories = $stmtSubcats->fetchAll(PDO::FETCH_ASSOC);

// 4) Ürünler (istee bağlı filtre ile)
$where = ['is_active = 1'];
$params = [];
if (!empty($_GET['category_id'])) {
    $where[]  = 'category_id = ?';
    $params[] = intval($_GET['category_id']);
}
if (!empty($_GET['sub_category'])) {
    $where[]  = 'sub_category = ?';
    $params[] = intval($_GET['sub_category']);
}
$sql = "
    SELECT id, name, price, image_path, category_id, sub_category
    FROM products
    WHERE " . implode(' AND ', $where) . "
    ORDER BY name
";
$stmtProds = $pdo->prepare($sql);
$stmtProds->execute($params);
$products = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

// --- PRINT RECEIPT VERİ HAZIRLAMA (modal HTMLden hemen önce) ---

// 5) Firma bilgileri
$company = [
    'name'  => 'Dreamguys Technologies Pvt Ltd.',
    'phone' => '+1 5656665656',
    'email' => 'example@gmail.com',
    'logo'  => 'assets/img/logo.png',
];

// 6) Sipariş ID’si
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
if ($orderId) {
    // 6a) POSorders ana kaydı
    $s = $pdo->prepare("
        SELECT
          invoice_no,
          customer_name,
          created_at,
          sub_total,
          discount,
          shipping,
          order_tax,
          total_amount
        FROM POSorders
        WHERE id = ?
    ");
    $s->execute([$orderId]);
    $order = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    // 6b) Müşteri adı
    $customerName = $order['customer_name'] ?: 'Nakit Müşteri';

    // 6c) POSorder kalemleri
    $s2 = $pdo->prepare("
        SELECT
          poi.quantity,
          poi.unit_price,
          poi.sub_total,
          p.name AS product_name
        FROM POSorder_items poi
        JOIN products p
          ON p.id = poi.product_id
        WHERE poi.posorder_id = ?
    ");
    $s2->execute([$orderId]);
    $items = $s2->fetchAll(PDO::FETCH_ASSOC);

    // 6d) Tarih formatlama
    $formattedDate = isset($order['created_at'])
        ? date('d.m.Y', strtotime($order['created_at']))
        : date('d.m.Y');
} else {
    // Defaults
    $order = [
        'invoice_no'    => '',
        'customer_name' => '',
        'created_at'    => null,
        'sub_total'     => 0,
        'discount'      => 0,
        'shipping'      => 0,
        'order_tax'     => 0,
        'total_amount'  => 0,
    ];
    $items         = [];
    $customerName  = '';
    $formattedDate = date('d.m.Y');
}
?>




<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
        <meta name="description" content="POS - Bootstrap Admin Template">
		<meta name="keywords" content="admin, estimates, bootstrap, business, corporate, creative, invoice, html5, responsive, Projects">
        <meta name="author" content="Dreamguys - Bootstrap Admin Template">
        <meta name="robots" content="noindex, nofollow">
        <title>CAfe Sat Ekranı</title>
		
		<!-- Favicon -->
        <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
		
		<!-- Bootstrap CSS -->
        <link rel="stylesheet" href="assets/css/bootstrap.min.css">

		<!-- Datetimepicker CSS -->
		<link rel="stylesheet" href="assets/css/bootstrap-datetimepicker.min.css">
		
		<!-- animation CSS -->
        <link rel="stylesheet" href="assets/css/animate.css">

		<!-- Select2 CSS -->
		<link rel="stylesheet" href="assets/plugins/select2/css/select2.min.css">

		<!-- Datatable CSS -->
		<link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
		
        <!-- Fontawesome CSS -->
		<link rel="stylesheet" href="assets/plugins/fontawesome/css/fontawesome.min.css">
		<link rel="stylesheet" href="assets/plugins/fontawesome/css/all.min.css">

		<!-- Daterangepikcer CSS -->
		<link rel="stylesheet" href="assets/plugins/daterangepicker/daterangepicker.css">

		<!-- Owl Carousel CSS -->
		<link rel="stylesheet" href="assets/plugins/owlcarousel/owl.carousel.min.css">
		<link rel="stylesheet" href="assets/plugins/owlcarousel/owl.theme.default.min.css">
		
		<!-- Main CSS -->
        
        <link rel="stylesheet" href="assets/css/style.css?v=1.1">
        


		
	</head>
	
	<body>
		<div id="global-loader" >
			<div class="whirly-loader"> </div>
		</div>
		<!-- Main Wrapper -->
		<div class="main-wrapper">

			<!-- Header -->
			<div class="header">
			
				<!-- Logo -->
				 <div class="header-left active">
					<a href="https://selimurgun.com.tr/cafe/" class="logo logo-normal">
						<img src="assets/img/logo.png"  alt="">
					</a>
					<a href="index.html" class="logo logo-white">
						<img src="assets/img/logo-white.png"  alt="">
					</a>
					<a href="index.html" class="logo-small">
						<img src="assets/img/logo-small.png"  alt="">
					</a>
				</div>
				<!-- /Logo -->
				
				<a id="mobile_btn" class="mobile_btn d-none" href="#sidebar">
					<span class="bar-icon">
						<span></span>
						<span></span>
						<span></span>
					</span>
				</a>
				
				<!-- Header Menu -->
				<ul class="nav user-menu">

					<!-- Search -->
					<li class="nav-item nav-searchinputs">
						<div class="top-nav-search">
							
							<a href="javascript:void(0);" class="responsive-search">
								<i class="fa fa-search"></i>
							</a>
							<form action="#" class="dropdown">
								<div class="searchinputs dropdown-toggle" id="dropdownMenuClickable" data-bs-toggle="dropdown" data-bs-auto-close="false">
									<input type="text" placeholder="Search">
									<div class="search-addon">
										<span><i data-feather="x-circle" class="feather-14"></i></span>
									</div>
								</div>
								<div class="dropdown-menu search-dropdown" aria-labelledby="dropdownMenuClickable">
								    <div class="search-info">
								    	<h6><span><i data-feather="search" class="feather-16"></i></span>Recent Searches</h6>
								    	<ul class="search-tags">
								    		<li><a href="javascript:void(0);">Products</a></li>
								    		<li><a href="javascript:void(0);">Sales</a></li>
								    		<li><a href="javascript:void(0);">Applications</a></li>
								    	</ul>
								    </div>
								    <div class="search-info">
								    	<h6><span><i data-feather="help-circle" class="feather-16"></i></span>Help</h6>
								    	<p>How to Change Product Volume from 0 to 200 on Inventory management</p>
								    	<p>Change Product Name</p>
								    </div>
								    <div class="search-info">
								    	<h6><span><i data-feather="user" class="feather-16"></i></span>Customers</h6>
								    	<ul class="customers">
								    		<li><a href="javascript:void(0);">Aron Varu<img src="assets/img/profiles/avator1.jpg" alt="" class="img-fluid"></a></li>
								    		<li><a href="javascript:void(0);">Jonita<img src="assets/img/profiles/avator1.jpg" alt="" class="img-fluid"></a></li>
								    		<li><a href="javascript:void(0);">Aaron<img src="assets/img/profiles/avator1.jpg" alt="" class="img-fluid"></a></li>
								    	</ul>
								    </div>
								</div>
								<!-- <a class="btn"  id="searchdiv"><img src="assets/img/icons/search.svg" alt="img"></a> -->
							</form>
						</div>
					</li>
					<!-- /Search -->

					
				
				

					<li class="nav-item nav-item-box">
						<a href="javascript:void(0);" id="btnFullscreen">
							<i data-feather="maximize"></i>
						</a>
					</li>
					
					<!-- Notifications -->
					<li class="nav-item dropdown nav-item-box">
						<a href="javascript:void(0);" class="dropdown-toggle nav-link" data-bs-toggle="dropdown">
							<i data-feather="bell"></i><span class="badge rounded-pill">2</span>
						</a>
					
					</li>
					<!-- /Notifications -->
					
					<li class="nav-item nav-item-box">
						<a href="general-settings.html"><i data-feather="settings"></i></a>
					</li>
					<li class="nav-item dropdown has-arrow main-drop">
						<a href="javascript:void(0);" class="dropdown-toggle nav-link userset" data-bs-toggle="dropdown">
							<span class="user-info">
								<span class="user-letter">
									<img src="assets/img/profiles/avator1.jpg" alt="" class="img-fluid">
								</span>
								<span class="user-detail">
									<span class="user-name">John Smilga</span>
									<span class="user-role">Super Admin</span>
								</span>
							</span>
						</a>
						<div class="dropdown-menu menu-drop-user">
							<div class="profilename">
								<div class="profileset">
									<span class="user-img"><img src="assets/img/profiles/avator1.jpg" alt="">
									<span class="status online"></span></span>
									<div class="profilesets">
										<h6>John Smilga</h6>
										<h5>Super Admin</h5>
									</div>
								</div>
								<hr class="m-0">
								<a class="dropdown-item" href="profile.html"> <i class="me-2"  data-feather="user"></i> My Profile</a>
								<a class="dropdown-item" href="general-settings.html"><i class="me-2" data-feather="settings"></i>Settings</a>
								<hr class="m-0">
								<a class="dropdown-item logout pb-0" href="signin.html"><img src="assets/img/icons/log-out.svg" class="me-2" alt="img">Logout</a>
							</div>
						</div>
					</li>
				</ul>
				<!-- /Header Menu -->
				
				<!-- Mobile Menu -->
				<div class="dropdown mobile-user-menu">
					<a href="javascript:void(0);" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
					<div class="dropdown-menu dropdown-menu-right">
						<a class="dropdown-item" href="profile.html">My Profile</a>
						<a class="dropdown-item" href="general-settings.html">Settings</a>
						<a class="dropdown-item" href="signin.html">Logout</a>
					</div>
				</div>
				<!-- /Mobile Menu -->
			</div>
			<!-- Header -->
			
			<div class="page-wrapper pos-pg-wrapper ms-0">
				<div class="content pos-design p-0">
				    
					<!--<div class="btn-row d-sm-flex align-items-center">
						<a href="javascript:void(0);" class="btn btn-secondary mb-xs-3" data-bs-toggle="modal" data-bs-target="#orders"><span class="me-1 d-flex align-items-center"><i data-feather="shopping-cart" class="feather-16"></i></span>View Orders</a>
						<a href="javascript:void(0);" class="btn btn-info"><span class="me-1 d-flex align-items-center"><i data-feather="rotate-cw" class="feather-16"></i></span>Reset</a>
						<a href="javascript:void(0);" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recents"><span class="me-1 d-flex align-items-center"><i data-feather="refresh-ccw" class="feather-16"></i></span>Transaction</a>
					</div> -->
					
					
<!-- Table Selection Modal (body içine yerleştirin) -->
<div class="modal fade" id="tableSelectModal" tabindex="-1" aria-labelledby="tableSelectModal" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-sm-down modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Masa Seçimi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <p>Lütfen önce bir masa seçin:</p>
        <div class="table-grid">
          <?php foreach($tables as $t):
              $isOccupied = in_array($t['id'], $openTables, true);
              $cls        = $isOccupied ? 'occupied' : 'empty';
              $label      = $isOccupied ? 'Dolu' : 'Boş';
          ?>
            <div class="table-box <?= $cls ?>" data-id="<?= $t['id'] ?>">
  <!-- ↓ Masa Aktar Butonu -->
<button type="button" class="btn-transfer" data-id="<?= $t['id'] ?>" title="Masa Aktar">
  <i data-feather="move"></i>
</button>

  <!-- ↑ Eklendi -->
  <?= htmlspecialchars($t['table_number']) ?>
  <span class="status-label"><?= $label ?></span>
</div>

          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

					<div class="row align-items-start pos-wrapper">
						<div class="col-md-12 col-lg-8">
							<div class="pos-categories tabs_wrapper">
								
								<ul class="tabs owl-carousel pos-category">
    <!-- 1. "All Categories" butonu sabit bırakıld -->
    <li id="cat-all" class="cat-filter active" data-cat="all">
    <a href="javascript:void(0);">
        <img src="assets/img/categories/category-01.png" alt="All Categories">
     </a>
     <h6><a href="javascript:void(0);">Tüm Kategoriler</a></h6>
 </li>

    <!-- 2. Veritabanından gelen kategorilerle dngü -->
     <?php foreach ($categories as $cat): ?>
<li id="cat-<?= $cat['id'] ?>" class="cat-filter" data-cat="<?= $cat['id'] ?>">
  <a href="javascript:void(0);">
    <img src="<?= htmlspecialchars($cat['image'] ?: 'assets/img/categories/default.png') ?>"
         alt="<?= htmlspecialchars($cat['name']) ?>">
  </a>
  <h6><a href="javascript:void(0);"><?= htmlspecialchars($cat['name']) ?></a></h6>
</li>

    <?php endforeach; ?>
</ul>
 <!-- ÜRÜN ARAMA -->
  <div class="input-block position-relative">
    <input type="text"
           id="productSearch"
           class="form-control"
           placeholder="Ürün ad veya barkod oku">
    <div id="productSuggestions"
         class="list-group position-absolute w-100"
         style="z-index:1000; display:none;"></div>
  </div>
								<!-- Ürünler -->
<div class="pos-products">
  <div class="d-flex align-items-center justify-content-between">
    <h5 class="mb-3">Ürnler</h5>
    
  </div>
  <div class="row" id="productsRow">
    <?php foreach($products as $p): ?>
  <div class="col-sm-2 col-md-6 col-lg-3 col-xl-3 product-card" data-category-id="<?= $p['category_id'] ?>">
    <div class="product-info default-cover card prod"
         data-id="<?= $p['id'] ?>"
         data-name="<?= htmlspecialchars($p['name']) ?>"
         data-price="<?= $p['price'] ?>">
      <a href="javascript:void(0);" class="img-bg">
        <img src="<?= htmlspecialchars($p['image_path'] ?: 'assets/img/products/default.png') ?>"
             alt="<?= htmlspecialchars($p['name']) ?>">
      </a>
      <div class="info">
        <h6 class="cat-name"><a href="javascript:void(0);"><?= htmlspecialchars($p['name']) ?></a></h6>
      </div>
      <div class="price">
        $<?= number_format($p['price'],2) ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>

  </div>
</div>

							</div>
						</div>
						<div class="col-md-12 col-lg-4 ps-0">
							<aside class="product-order-list">
								<div class="head d-flex align-items-center justify-content-between w-100">
  <div>
    <h5>Sipari Listesi</h5>
    <span id="transactionId">İşlem No: <strong>#<span id="txId">00000</span></strong></span>
  </div>
  <div>
    <a id="btnCancelOrder" href="javascript:void(0);">
  <i data-feather="trash-2" class="feather-16 text-danger"></i>
</a>

    <a href="javascript:void(0);" class="text-default"><i data-feather="more-vertical" class="feather-16"></i></a>
  </div>
</div>
<!-- Cancel Reason Modal -->
<div class="modal fade" id="cancelReasonModal" tabindex="-1" aria-labelledby="cancelReasonLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cancelReasonLabel">Sipari ptal Nedeni</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="cancelReasonInput" class="form-label">Ltfen iptal nedenini belirtin</label>
          <textarea id="cancelReasonInput" class="form-control" rows="3" required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
        <button type="button" class="btn btn-danger" id="confirmCancelBtn">İptal Et</button>
      </div>
    </div>
  </div>
</div>


								<div class="order-search block-section position-relative">
  <h6>MASA SEÇİNİZ</h6>
<div class="btn-row d-sm-flex align-items-center my-2">
  <button id="btnTables" class="btn btn-warning">
    <i data-feather="grid" class="me-1"></i> Masalar
  </button>
  <span id="selectedTableLabel" style="font-size:1.5em;color:red;margin-left:.75rem;font-weight:bold;"></span>

  <!-- varsa diğer butonlar -->
</div>
  <!-- MÜŞTERİ ARAMA -->
 <!-- <div class="input-block mb-3 position-relative">
    <input type="text"
           id="customerSearch"
           class="form-control"
           placeholder="Mşteri ara veya bo brak">
    <div id="customerSuggestions"
         class="list-group position-absolute w-100"
         style="z-index:1000; display:none;"></div>
  </div> -->
</div>


<div class="product-added block-section">
  <div class="head-text d-flex align-items-center justify-content-between">
    <h6 class="d-flex align-items-center mb-0">
      Eklenen Ürnler
      <span class="count" id="cartCount">0</span>
    </h6>
    <a href="javascript:void(0);" class="d-flex align-items-center text-danger" id="clearCart">
      <span class="me-1"><i data-feather="x" class="feather-16"></i></span>Ürünleri Kaldır
    </a>
  </div>
  <div class="product-wrap" id="cartList">
    <!-- Buraya JS ile dinamik olarak <div class="product-list"></div> bloklar eklenecek -->
  </div>
</div>

<?php
// rneğin config veya sabit tanım olarak
$taxRates      = [0, 5, 10, 15, 20, 25, 30];   // %
$shippingFees  = [0, 15, 20, 25, 30];        // tutarlar
$discountRates = [0, 5, 10, 15, 20, 25, 30];  // %
?>
<div class="block-section">
  <div class="selling-info">
    <div class="row">
      <!-- Vergi % girişi -->
      <div class="col-12 col-sm-3">
        <div class="input-block">
          <label for="orderTax">Vergi (%)</label>
          <input type="number"
                 id="orderTax"
                 class="form-control form-control-sm"
                 min="0" step="0.01"
                 value="0">
        </div>
      </div>

      <!-- Ek Hizmet TL girişi -->
      <div class="col-12 col-sm-3">
        <div class="input-block">
          <label for="orderShipping">Ek Hizmet (₺)</label>
          <input type="number"
                 id="orderShipping"
                 class="form-control form-control-sm"
                 min="0" step="0.01"
                 value="0">
        </div>
      </div>

      <!-- İndirim % girişi -->
      <div class="col-12 col-sm-3">
        <div class="input-block">
          <label for="orderDiscount">İndirim (%)</label>
          <input type="number"
                 id="orderDiscount"
                 class="form-control form-control-sm"
                 min="0" step="0.01"
                 value="0">
        </div>
      </div>

      <!-- Ek İndirim TL girişi -->
      <div class="col-12 col-sm-3">
        <div class="input-block">
          <label for="extraDiscount">Ek İndirim (₺)</label>
          <input type="number"
                 id="extraDiscount"
                 class="form-control form-control-sm"
                 min="0" step="0.01"
                 value="0">
        </div>
      </div>
    </div>
  </div>

  <!-- Vergi hesaplama modu -->
  <div class="form-check form-check-inline mt-2">
    <input class="form-check-input" type="radio" name="taxMode" id="taxBefore" value="before" checked>
    <label class="form-check-label" for="taxBefore">Vergi → İndir. Öncesi</label>
  </div>
  <div class="form-check form-check-inline mt-2">
    <input class="form-check-input" type="radio" name="taxMode" id="taxAfter" value="after">
    <label class="form-check-label" for="taxAfter">Vergi → İndir. Sonrası</label>
  </div>

  <!-- Toplam Tablosu -->
  <div class="order-total mt-3">
    <table class="table table-borderless">
      <tr>
        <td>Ara Toplam</td>
        <td class="text-end" id="subtotal">₺0.00</td>
      </tr>
      <tr>
        <td>Vergi Tutar</td>
        <td class="text-end" id="taxAmount">₺0.00</td>
      </tr>
      <tr>
        <td>Ek Hizmet</td>
        <td class="text-end" id="shippingAmount">₺0.00</td>
      </tr>
      <tr>
        <td class="text-danger">İndirim Tutarı</td>
        <td class="text-danger text-end" id="discountAmount">₺0.00</td>
      </tr>
      <tr>
        <td class="text-danger">Ek İndirim</td>
        <td class="text-danger text-end" id="extraDiscountAmount">0.00</td>
      </tr>
      <tr>
        <td class="fw-bold">Genel Toplam</td>
        <td class="fw-bold text-end" id="grandTotal">₺0.00</td>
      </tr>
    </table>
  </div>
</div>



							<div class="btn-row d-sm-flex align-items-center justify-content-between">
  <button type="button" id="holdBtn" class="btn btn-info btn-icon flex-fill">
    <span class="me-1 d-flex align-items-center">
      <i data-feather="pause" class="feather-16"></i>
    </span>
    Siparişi Ekle
  </button>

<button type="button"
        id="voidBtn"
        class="btn btn-danger btn-icon flex-fill">
  <span class="me-1 d-flex align-items-center">
    <i data-feather="credit-card" class="feather-16"></i>
  </span>
  Kredi Kartı
</button>

  <button type="button"
          id="checkoutBtn"
          class="btn btn-success btn-icon flex-fill">
    <span class="me-1 d-flex align-items-center">
      <i data-feather="credit-card" class="feather-16"></i>
    </span>
    Nakit
  </button>
  
  <button type="button"
        id="printOrderBtn"
        class="btn btn-primary btn-icon flex-fill">
  <span class="me-1 d-flex align-items-center">
    <i data-feather="printer" class="feather-16"></i>
  </span>
  Siparişi Yazdır
</button>
</div>


							</aside>
						</div>
					</div>
				</div>
			</div>

		</div>
		<!-- /Main Wrapper -->





	    <!-- 	<div class="customizer-links" id="setdata">
			<ul class="sticky-sidebar">
				<li class="sidebar-icons">
					<a href="#" class="navigation-add" data-bs-toggle="tooltip" data-bs-placement="left"
						data-bs-original-title="Theme">
						<i data-feather="settings" class="feather-five"></i>
					</a>
				</li>
			</ul>
		</div> -->
		
		<!-- jQuery -->
		<script src="assets/js/jquery-3.7.1.min.js"></script>

		<!-- Feather Icon JS -->
		<script src="assets/js/feather.min.js"></script>

		<!-- Slimscroll JS -->
		<script src="assets/js/jquery.slimscroll.min.js"></script>

		<!-- Datatable JS -->
		<script src="assets/js/jquery.dataTables.min.js"></script>
		<script src="assets/js/dataTables.bootstrap5.min.js"></script>
		
		<!-- Bootstrap Core JS -->
		<script src="assets/js/bootstrap.bundle.min.js"></script>

		<!-- Chart JS -->
		<script src="assets/plugins/apexchart/apexcharts.min.js"></script>
		<script src="assets/plugins/apexchart/chart-data.js"></script>

		<!-- Daterangepikcer JS -->
		<script src="assets/js/moment.min.js"></script>
		<script src="assets/plugins/daterangepicker/daterangepicker.js"></script>

		<!-- Owl JS -->
		<script src="assets/plugins/owlcarousel/owl.carousel.min.js"></script>

		<!-- Select2 JS -->
		<script src="assets/plugins/select2/js/select2.min.js"></script>

		<!-- Sweetalert 2 -->
		<script src="assets/plugins/sweetalert/sweetalert2.all.min.js"></script>
		<script src="assets/plugins/sweetalert/sweetalerts.min.js"></script>
		
		<!-- Custom JS --><script src="assets/js/theme-script.js"></script>	
		<script src="assets/js/script.js"></script>

	
	</body>
</html>
<script>
$(function(){
  // Sayfa aılr açılmaz Siparişi Yazdır butonunu pasifleştir
  $('#printOrderBtn').prop('disabled', true);
  // --- 0) GLOBAL STATE ---
  let selectedTableId          = null;
  let currentOrderId           = null;
  let currentOrderStatus       = null;
  let currentOrderPaymentType  = null;
  const cart                   = [];
  const currency               = '₺';
  let selectedMethod           = 'cash';
  let selectedCustomer         = null;

  // --- MASA SEÇİMİ MODALI VE AJAX ---
  const $tableModal = new bootstrap.Modal($('#tableSelectModal')[0]);
  let transferFromTableId = null;
  const cancelModal = new bootstrap.Modal($('#cancelReasonModal')[0]);

  // Masa sekmesi butonu (üçgen ikon) açar
  $('#btnTables').on('click', () => {
    transferFromTableId = null;
    // göstermek istediğimiz kutucukları resetle
    $('#tableSelectModal .table-box').show();
    $tableModal.show();
  });

 // Masa Aktar butonuna tklandığnda
$(document).on('click', '.btn-transfer', function(e){
  e.stopPropagation();
  transferFromTableId = $(this).data('id');
  $('#tableSelectModal .table-box').show();
  $('#tableSelectModal .table-box.occupied').hide();
  $tableModal.show();
  
  // <-- add this line -->
  if (window.feather) feather.replace();
});


  // Modal içindeki masa kutucuklarına tıklama handler
$(document).on('click', '#tableSelectModal .table-box', function(){
  const clickedId = +$(this).data('id');

  // — Transfer akışı 
  if (transferFromTableId !== null) {
    const from = transferFromTableId, to = clickedId;
    transferFromTableId = null;
    $.post('pos.php', {
      action:     'transferTable',
      from_table: from,
      to_table:   to
    }, function(res){
      if (res.success) {
        Swal.fire('Başarılı','Masa aktarıldı','success');
        refreshTableStatuses();
      } else {
        Swal.fire('Hata', res.error || 'Aktarma başarsız','error');
      }
    }, 'json');
    $tableModal.hide();
    return;
  }

  //  Normal masa seçimi akış 
  selectedTableId = clickedId;
  const tableName = $(this).clone().children().remove().end().text().trim();
  $('#selectedTableLabel').text('Seçili masa: ' + tableName);
  $tableModal.hide();
  $('#productSearch').trigger('input');

  $.getJSON('pos.php', {
    action:   'select_table',
    table_id: selectedTableId
  }, function(res){
    if (!res.success) {
      return Swal.fire('Hata','Açık sipariş yüklenemedi','error');
    }
    // sipariş verilerini yükle
    currentOrderId          = res.order_id;
    currentOrderStatus      = res.status;
    currentOrderPaymentType = res.payment_type;
    selectedMethod          = res.payment_type;
    $('#txId').text(res.invoice_no.replace(/^POS/, ''));
    // Sepeti sıfırla ve verileri ekle
    cart.length = 0;
    res.items.forEach(item => {
      cart.push({
        id:    item.product_id,
        name:  item.product_name,
        price: parseFloat(item.unit_price),
        qty:   parseInt(item.quantity, 10)
      });
    });
    // Sepeti render et
    renderCart();
    // “Siparişi Yazdır” butonunu sadece dolu masalarda aktif et
    $('#printOrderBtn').prop('disabled', cart.length === 0);
  });
});


  // --- 1) DEBOUNCE FONKSİYONU ---
  function debounce(fn, delay){
    let timer;
    return function(...args){
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  // --- 2) TRANSACTION ID ÜRETİMİ ---
  function generateTransactionID() {
    return Math.floor(100000 + Math.random() * 900000);
  }
  $('#txId').text(generateTransactionID());

  // --- 3) RN EKLEME (.prod) ---
  $(document).on('click', '.prod', function(){
    if (!selectedTableId) {
      $tableModal.show();
      return;
    }
    const id    = +$(this).data('id');
    const name  = $(this).data('name');
    const price = parseFloat($(this).data('price'));
    let item = cart.find(i => i.id === id);
    if (item) item.qty++;
    else      cart.push({ id, name, price, qty: 1 });
    renderCart();
  });

  // --- 4) SEPETİ TEMZLE ---
  $('#clearCart').on('click', () => { cart.length = 0; renderCart(); });

  // --- 5) KATEGORİ FLTRELEME ---
  $('.pos-category').on('click', function(e){
    const $li = $(e.target).closest('li.cat-filter');
    if (!$li.length) return;
    const cat = $li.data('cat').toString();
    $('li.cat-filter').removeClass('active');
    $li.addClass('active');
    $('#productsRow .product-card').each(function(){
      const cid = $(this).data('category-id').toString();
      $(this).toggle(cat === 'all' || cid === cat);
    });
    if ($('#productsRow .product-card:visible').length === 0) {
      if (!$('#noProducts').length) {
        $('#productsRow').append('<div id="noProducts" class="col-12 text-center py-4">Bu kategoride ürün yok.</div>');
      }
    } else {
      $('#noProducts').remove();
    }
    updateProductPrices();
  });

  // --- 6) HOLD BUTONU (Siparişi Ekle) ---
$('#holdBtn').off('click').on('click', function(){
  if (!selectedTableId) {
    return Swal.fire('Uyarı','Lütfen önce bir masa seçin','warning');
  }
  selectedMethod = 'open_account';
  $('#checkoutBtn').trigger('click');

  // Bu satırların YERİ
  $(document).one('ajaxSuccess', function(e, xhr, settings){
    if (settings.url.includes('pos.php') && settings.data.includes('action=hold_order')) {
      $('#printOrderBtn').prop('disabled', false);
    }
  });
});

// --- 6b) VOID BUTONU (Kredi Kartı) ---
$('#voidBtn').off('click').on('click', function(){
  if (!selectedTableId) {
    return Swal.fire('Uyarı','Ltfen önce bir masa seçin','warning');
  }
  selectedMethod = 'credit_card';
  $('#checkoutBtn').trigger('click');
});


  // --- 7) TUTAR GNCELLEME ---
  $('#orderTax, #orderShipping, #orderDiscount').on('input change', renderCart);
  $('input[name="taxMode"]').on('change', renderCart);

  // --- 8) MÜŞTERİ AUTOCOMPLETE ---
  $('#customerSearch').on('input', debounce(function(){
    const q = $(this).val().trim();
    if (!q) {
      $('#customerSuggestions').hide().empty();
      selectedCustomer = null;
      return;
    }
    $.getJSON('pos.php?action=customers', { q }, function(list){
      const $ul = $('#customerSuggestions').empty();
      list.forEach(cust => {
        $ul.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${cust.id}" data-name="${cust.name}">${cust.name}</button>`);
      });
      $ul.show();
    });
  }, 300));
  $('#customerSuggestions').on('click', 'button', function(){
    selectedCustomer = { id: $(this).data('id'), name: $(this).data('name') };
    $('#customerSearch').val(selectedCustomer.name);
    $('#customerSuggestions').hide().empty();
  });

// --- 9) ÜRÜN AUTOCOMPLETE & EKLEME ---
$('#productSearch').on('input', debounce(function(){
  const q = $(this).val().trim();
  if (!q) {
    $('#productSuggestions').hide().empty();
    return;
  }
  if (!selectedTableId) {
    $tableModal.show();
    return;
  }
  $.getJSON('pos.php?action=products', { q }, function(list){
    const $ul = $('#productSuggestions').empty();
    list.forEach(prod => {
      $ul.append(
        `<button type="button" 
                 class="list-group-item list-group-item-action prod" 
                 data-id="${prod.id}" 
                 data-name="${prod.name}" 
                 data-price="${prod.price}">
           ${prod.name} (${prod.sku})
         </button>`
      );
    });
    $ul.show();
  });
}, 200));

// Tekrar tekrar eklemeyi engellemek için önce off sonra on ve propagation’ı durdur
$('#productSuggestions')
  .off('click', 'button')
  .on('click', 'button', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();

    if (!selectedTableId) {
      $('#productSuggestions').hide();
      return Swal.fire('Uyarı','Lütfen önce bir masa seçin','warning');
    }

    const id    = +$(this).data('id');
    const name  = $(this).data('name');
    const price = parseFloat($(this).data('price'));
    let item = cart.find(i => i.id === id);

    if (item) item.qty++;
    else      cart.push({ id, name, price, qty: 1 });

    renderCart();
    $('#productSearch').val('').focus();
    $('#productSuggestions').hide().empty();
  });


  // --- 10) AUTOCOMPLETE KUTULARINI KAPAT ---
  $(document).on('click', function(e){
    if (!$(e.target).closest('#customerSearch, #customerSuggestions').length) {
      $('#customerSuggestions').hide();
    }
    if (!$(e.target).closest('#productSearch, #productSuggestions').length) {
      $('#productSuggestions').hide();
    }
  });

  // --- 11) SIPARI İPTAL İİN MODAL AÇ ---
  $('#btnCancelOrder').off('click').on('click', function(){
    if (!selectedTableId) {
      return Swal.fire('Uyarı','Önce bir masa seçin','warning');
    }
    if (currentOrderPaymentType !== 'open_account') {
      return Swal.fire('Uyarı','Yalnızca Ak Hesap siparişler iptal edilebilir','warning');
    }
    $('#cancelReasonInput').val('');
    cancelModal.show();
  });

  // --- 12) MODALIN “İptal Et BUTONU ---
  $('#confirmCancelBtn').off('click').on('click', function(){
    const reason = $('#cancelReasonInput').val().trim();
    if (!reason) {
      return Swal.fire('Uyarı','Lütfen iptal nedenini girin','warning');
    }
    cancelModal.hide();
    $.post('pos.php', {
      action:   'cancel_order',
      order_id: currentOrderId,
      table_id: selectedTableId,
      reason:   reason
    }, function(res){
      if (!res.success) {
        return Swal.fire('Hata','Sipariş iptal edilemedi','error');
      }
      cart.length = 0;
      renderCart();
      $('.table-box[data-id="'+selectedTableId+'"]')
        .removeClass('occupied').addClass('empty')
        .find('.status-label').text('Boş');
      currentOrderId  = null;
      selectedTableId = null;
      $('#txId').text(generateTransactionID());
      Swal.fire('İptal Edildi','Sipariş başarıyla iptal edildi','success');
    }, 'json');
  });

// --- 13) CHECKOUT & SPARİŞ YAZDIR ---
$('#voidBtn').off('click').on('click', function(){
  if (!selectedTableId) {
    return Swal.fire('Uyarı','Lütfen önce bir masa seçin','warning');
  }
  selectedMethod = 'credit_card';
  $('#checkoutBtn').trigger('click');
});

$('#checkoutBtn').off('click').on('click', function(e){
  if (!selectedTableId) {
    return Swal.fire('Uyarı','Lütfen önce bir masa seçin','warning');
  }
  if (e.originalEvent) {
    selectedMethod = 'cash';
  }

  const payload = {
    table_id:       selectedTableId,
    order_id:       currentOrderId,
    customer_name:  selectedCustomer ? selectedCustomer.name : null,
    cart:           JSON.stringify(cart),
    payment_method: selectedMethod,
    order_tax:      parseFloat($('#orderTax').val())      || 0,
    discount:       parseFloat($('#orderDiscount').val()) || 0,
    shipping:       parseFloat($('#orderShipping').val()) || 0,
    tax_mode:       $('input[name="taxMode"]:checked').val()
  };

  $.post('save_order.php', payload, 'json')
    .done(function(res){
      if (!res.success) {
        return Swal.fire('Hata', res.error || 'Sipariş kaydedilemedi','error');
      }
      const orderId = res.order_id;
      const $box    = $('.table-box[data-id="'+selectedTableId+'"]');

      // Açık hesap ise sadece sakla
      if (selectedMethod === 'open_account') {
        $.post('pos.php', { action:'hold_order', order_id:orderId }, function(r){
          if (r.success) {
            currentOrderPaymentType = 'open_account';
            Swal.fire('Başarıl','Açık hesap kaydedildi','success');
            cart.length = 0; renderCart();
            $box.removeClass('occupied').addClass('empty').find('.status-label').text('Boş');
            selectedTableId = null; currentOrderId = null; $('#selectedTableLabel').text('');
            refreshTableStatuses();
            $('#printOrderBtn').prop('disabled', false);
          } else {
            Swal.fire('Hata','Açk hesap kaydedilemedi','error');
          }
        }, 'json');
        return;
      }

      // Normal kapatma
      cart.length = 0; renderCart();
      $box.removeClass('empty').addClass('occupied').find('.status-label').text('Dolu');

      $.post('close_order.php', { order_id:orderId, table_id:selectedTableId }, function(r2){
        if (!r2.success) {
          return Swal.fire('Uyarı','Masa kapatlamadı: '+r2.error,'warning');
        }

        Swal.fire({
          icon: 'success',
          title: 'Masa kapatıldı',
          text:  'Fi yazdırmak ister misiniz?',
          showCancelButton: true,
          confirmButtonText: 'Evet, yazdır',
          cancelButtonText: 'Hayr'
        }).then(result => {
          if (result.isConfirmed) {
            // Gizli iframe ile otomatik yazdır
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            iframe.src = 'print_receipt.php?order_id=' + orderId;
            document.body.appendChild(iframe);
            iframe.onload = () => {
              iframe.contentWindow.focus();
              iframe.contentWindow.print();
              setTimeout(() => document.body.removeChild(iframe), 1000);
            };
          }
          $('#printOrderBtn').prop('disabled', true);
        });

        // Masa UI güncellemesi
        $box.removeClass('occupied').addClass('empty').find('.status-label').text('Bo');
        selectedTableId = null;
        currentOrderId  = null;
        $('#selectedTableLabel').text('');
      }, 'json');
    })
    .fail(function(xhr){
      let msg = 'Sunucu hatası';
      try {
        const j = JSON.parse(xhr.responseText);
        if (j.error) msg = j.error;
      } catch(_) {}
      Swal.fire('Hata', msg, 'error');
    });
});

// --- 14) SİPARİİ YAZDIR BUTONU (ÖDEMESİZ ÖN ZLEME) ---
$('#printOrderBtn').off('click').on('click', function(){
  if (!selectedTableId) {
    return Swal.fire('Uyarı','Lütfen önce bir masa seçin','warning');
  }
  if (!currentOrderId) {
    return Swal.fire('Uyarı','Yazdırılacak sipariş bulunamad','warning');
  }
  const iframe = document.createElement('iframe');
  iframe.style.position = 'fixed';
  iframe.style.right = '0';
  iframe.style.bottom = '0';
  iframe.style.width = '0';
  iframe.style.height = '0';
  iframe.style.border = '0';
  iframe.src = 'print_receipt.php?order_id=' + currentOrderId;
  document.body.appendChild(iframe);
  iframe.onload = () => {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
    setTimeout(() => document.body.removeChild(iframe), 1000);
  };
  $('#printOrderBtn').prop('disabled', true);
});


// --- 14) SİPARİŞİ YAZDIR BUTONU (ÖDEMESZ ÖN İZLEME) ---
$('#printOrderBtn').off('click').on('click', function(){
  if (!selectedTableId) {
    return Swal.fire('Uyarı','Ltfen önce bir masa sein','warning');
  }
  if (!currentOrderId) {
    return Swal.fire('Uyarı','Yazdırılacak sipariş bulunamadı','warning');
  }
  const w = window.open(
    'print_receipt.php?order_id=' + currentOrderId,
    '_blank',
    'width=300,height=600'
  );
  w.focus();
  w.onload = function() {
    w.print();
    w.close();
  };
  $('#printOrderBtn').prop('disabled', true);
});

  // --- 14) ÜRÜN FİYATLARINI DÖNÜŞTÜR ---
  function updateProductPrices(){
    $('.product-card .price p, .product-card .price').each(function(){
      let txt = $(this).text().trim();
      if (txt.startsWith('$')) $(this).text(currency + txt.slice(1));
    });
  }

  //  Sepetten tek ürnü sil —
  $(document).on('click', '.remove-item-btn', function(){
    const id = +$(this).data('id');
    const idx = cart.findIndex(i => i.id===id);
    if (idx>-1) cart.splice(idx,1);
    renderCart();
    if (window.feather) feather.replace();
  });

  // --- 15) SEPETİ VE TUTARLARI HESAPLA & GÖSTER ---
function renderCart(){
  const $list = $('#cartList').empty();

  // 1) Başlık satır
  $list.append(`
    <div class="product-list d-flex align-items-center justify-content-between fw-bold mb-2">
      <div class="flex-grow-1">Ürün Adı</div>
      <div class="text-center" style="width:70px">Ürün Fiyatı</div>
      <div style="width:20px"></div>
      <div class="text-center" style="width:60px">Miktar</div>
      <div class="text-end" style="width:80px">Toplam</div>
      <div style="width:40px"></div>
    </div>
  `);

  // 2) Ürün satırları
  let subtotal = 0;
  cart.forEach(item => {
    const lineTotal = item.price * item.qty;
    subtotal += lineTotal;

    $list.append(`
      <div class="product-list d-flex align-items-center justify-content-between mb-2" data-id="${item.id}">
        <div class="info flex-grow-1">${item.name}</div>
        <div class="unit-price text-center" style="width:70px">${currency}${item.price.toFixed(2)}</div>
        <div class="multiply px-2 text-center" style="width:20px">×</div>
        <div class="qty-item text-center" style="width:60px">
          <input type="number" min="1"
                 class="form-control form-control-sm item-qty-input"
                 data-id="${item.id}"
                 value="${item.qty}">
        </div>
        <div class="line-total text-end" style="width:80px">${currency}${lineTotal.toFixed(2)}</div>
        <button class="btn btn-sm btn-outline-danger remove-item-btn ms-2" data-id="${item.id}">
          <i data-feather="trash-2" class="feather-16"></i>
        </button>
      </div>
    `);
  });

  // 3) Sepet Boşsa Uyarı
  if (!cart.length) {
    $list.html('<div class="text-center py-4">Sipariş listesine ürün ekleyin.</div>');
  }

  // 4) Hesaplamalar
  // Ara Toplam
  $('#subtotal').text(currency + subtotal.toFixed(2));

  // Vergi (%)
  const taxRate       = parseFloat($('#orderTax').val())       || 0;
  const taxAmount     = subtotal * taxRate / 100;
  $('#taxAmount').text(currency + taxAmount.toFixed(2));

  // Ek Hizmet (TL)
  const shipping      = parseFloat($('#orderShipping').val())  || 0;
  $('#shippingAmount').text(currency + shipping.toFixed(2));

  // İndirim (%)
  const discountRate  = parseFloat($('#orderDiscount').val())  || 0;
  const mode          = $('input[name="taxMode"]:checked').val();
  let discountAmount;
  if (mode === 'before') {
    // Vergi & Hizmet öncesi yüzde indir
    discountAmount = (subtotal + shipping) * discountRate / 100;
  } else {
    // Vergi & Hizmet sonrası yüzde indir
    discountAmount = (subtotal + taxAmount + shipping) * discountRate / 100;
  }
  $('#discountAmount').text('-' + currency + discountAmount.toFixed(2));

  // Ek ndirim (TL)
  const extraDiscount = parseFloat($('#extraDiscount').val())  || 0;
  $('#extraDiscountAmount').text('-' + currency + extraDiscount.toFixed(2));

  // Genel Toplam
  const grandTotal = subtotal
                   + taxAmount
                   + shipping
                   - discountAmount
                   - extraDiscount;
  $('#grandTotal').text(currency + grandTotal.toFixed(2));

  // Sepet Sayacı
  $('#cartCount').text(cart.reduce((s,i) => s + i.qty, 0));

  if (window.feather) feather.replace();
}

// --- 16) ADET DEĞİŞİKLİĞNİ YAKALA ---
$(document).off('change', '.item-qty-input')
           .on('change', '.item-qty-input', function(){
  const id     = +$(this).data('id');
  const newQty = Math.max(1, +$(this).val());
  $(this).val(newQty);
  const item = cart.find(i => i.id === id);
  if (item) {
    item.qty = newQty;
    renderCart();
  }
});

// --- 17) TUTAR GÜNCELLEME: Input & Radio Değişimlerinde ---
$('#orderTax, #orderShipping, #orderDiscount, #extraDiscount')
  .off('input change')
  .on('input change', renderCart);
$('input[name="taxMode"]')
  .off('change')
  .on('change', renderCart);


// --- 16) ADET DEĞİŞKLİĞİNİ YAKALA ---
$(document).on('change', '.item-qty-input', function(){
  const id     = +$(this).data('id');
  const newQty = Math.max(1, +$(this).val());
  $(this).val(newQty);
  const item = cart.find(i => i.id === id);
  if (item) {
    item.qty = newQty;
    renderCart();
  }
});


  // --- Masa durumlarını güncelle ---
  function refreshTableStatuses(){
    $.getJSON('pos.php?action=get_open_tables', ids=>{
      $('.table-box').each(function(){
        const $b=$(this), id=+$b.data('id');
        if (ids.includes(id)) {
          $b.removeClass('empty').addClass('occupied').find('.status-label').text('Dolu');
        } else {
          $b.removeClass('occupied').addClass('empty').find('.status-label').text('Boş');
        }
      });
    });
  }
  refreshTableStatuses();
  setInterval(refreshTableStatuses,5000);

  // --- 16) SAYFA İLK YÜKLEMESNDE ---
  updateProductPrices();
  renderCart();

  // --- 17) PRINT RECEIPT BUTONU ---
  $(document).on('click','#triggerPrint',function(){
    const html = $('#print-receipt .modal-body').html();
    const w=window.open('','_blank');
    w.document.write(`
      <html><head><title>Receipt</title>
      <link rel="stylesheet" href="assets/css/print.css" media="all">
      </head><body>${html}</body></html>`);
    w.document.close(); w.focus();
    setTimeout(()=>{ w.print(); w.close(); location.reload(); },300);
  });

});
</script>