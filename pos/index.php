<?php
require __DIR__ . "/../middleware/auth_check.php";
require __DIR__ . "/../config/database.php";

$allowedRoles = ["admin", "manager", "cashier"];
require __DIR__ . "/../middleware/role_check.php";

// Fetch menu items with stock, barcode, and category
$sql = "
    SELECT m.item_id, m.item_name, m.barcode, m.price, m.cost_price, m.stock, m.is_available, m.image_path,
           c.category_id, c.category_name
    FROM menu_items m
    JOIN categories c ON m.category_id = c.category_id
    WHERE m.is_available = 1
    ORDER BY c.category_name, m.item_name
";
$result = $conn->query($sql);

$menuByCategory = [];
$allItemsJson = [];
$categories = [];

while ($row = $result->fetch_assoc()) {
    $menuByCategory[$row["category_name"]][] = $row;
    $allItemsJson[] = $row;
    if (!in_array($row["category_name"], $categories, true)) {
        $categories[] = $row["category_name"];
    }
}

// Fetch store settings for receipt defaults
$settings = [
    "store_name" => "Capuzan Kusina",
    "store_address" => "123 Main Street, Metro Manila",
    "store_contact" => "+63 912 345 6789",
    "tax_rate" => "12",
    "receipt_footer" => "Thank you for dining with us! Please come again."
];
$settRes = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($settRes) {
    while ($s = $settRes->fetch_assoc()) {
        $settings[$s["setting_key"]] = $s["setting_value"];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= time() ?>">
    <style>
        body {
            background-color: var(--cream);
            color: var(--dark-charcoal);
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pos-container {
            display: flex;
            flex: 1;
            height: calc(100vh - 64px - 40px); /* subtracting navbar and shortcut bar */
            overflow: hidden;
        }

        /* Catalog Panel */
        .catalog-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 16px 20px;
            overflow: hidden;
            background: #FFFDF9;
        }

        .pos-top-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 14px;
            align-items: center;
        }

        .barcode-box {
            position: relative;
            flex: 1;
            max-width: 420px;
        }

        .barcode-box input {
            padding-left: 40px;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            border: 2px solid var(--warm-orange);
            background: #fff;
        }

        .barcode-box .barcode-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
            color: var(--warm-orange);
        }

        .category-pills {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 4px 2px 10px 2px;
            margin-bottom: 12px;
            scrollbar-width: thin;
        }

        .cat-pill {
            background: #FFFDF8;
            border: 1.5px solid #E6D9C8;
            padding: 8px 18px;
            border-radius: 22px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #4A291C;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 1px 3px rgba(74, 41, 28, 0.05);
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .cat-pill:hover {
            background: #F4EADA;
            border-color: #C96B2C;
            color: #C96B2C;
            transform: translateY(-1px);
        }

        .cat-pill.active {
            background: #C96B2C !important;
            color: #FFFDF8 !important;
            border-color: #C96B2C !important;
            box-shadow: 0 4px 12px rgba(201, 107, 44, 0.35);
            font-weight: 800;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
            align-content: start;
            gap: 14px;
            overflow-y: auto;
            padding-right: 6px;
            flex: 1;
        }

        .product-card {
            background: #FFFDF8;
            border: 1px solid #E6D9C8;
            border-radius: var(--radius-md, 12px);
            padding: 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 220px;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            position: relative;
            box-shadow: 0 2px 6px rgba(74, 41, 28, 0.05);
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(74, 41, 28, 0.12);
            border-color: #C96B2C;
        }

        .prod-img-box {
            width: 100%;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            background: #F4EADA;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            border: 1px solid #EAE0D3;
        }

        .prod-img-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }

        .product-card:hover .prod-img-box img {
            transform: scale(1.06);
        }

        .prod-img-placeholder {
            font-size: 2.2rem;
            user-select: none;
        }

        .product-card.out-of-stock {
            opacity: 0.55;
            cursor: not-allowed;
            background: #F5EFEB;
            border-color: #E6D9C8;
        }

        .product-card.out-of-stock:hover {
            transform: none;
            box-shadow: none;
            border-color: #E6D9C8;
        }

        .prod-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--deep-brown);
            margin-bottom: 4px;
        }

        .prod-barcode {
            font-family: monospace;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .prod-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }

        .prod-price {
            font-weight: 800;
            color: var(--warm-orange);
            font-size: 1.05rem;
        }

        .prod-stock-tag {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
        }

        /* Cart Panel */
        .cart-panel {
            width: 440px;
            background: #fff;
            border-left: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            box-shadow: -2px 0 8px rgba(41, 37, 34, 0.04);
        }

        .cart-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #FAF4EB;
        }

        .cart-items-wrapper {
            flex: 1;
            overflow-y: auto;
            padding: 12px 16px;
        }

        .cart-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--gray-500);
            text-align: center;
        }

        .cart-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-100);
            gap: 10px;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--gray-800);
        }

        .cart-item-price {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .cart-qty-ctrl {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .qty-btn {
            width: 26px;
            height: 26px;
            border: 1px solid var(--gray-300);
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.1s;
        }

        .qty-btn:hover {
            background: var(--gray-200);
        }

        .qty-disp {
            font-weight: 700;
            font-size: 0.9rem;
            min-width: 24px;
            text-align: center;
        }

        .cart-item-sub {
            font-weight: 700;
            font-size: 0.95rem;
            min-width: 65px;
            text-align: right;
        }

        .cart-item-remove {
            color: var(--danger);
            cursor: pointer;
            padding: 4px 6px;
            font-size: 0.9rem;
        }

        /* Cart Summary & Action Area */
        .cart-summary-area {
            padding: 14px 18px;
            background: #f8fafc;
            border-top: 1px solid var(--gray-200);
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 0.88rem;
            color: var(--gray-600);
        }

        .summary-total {
            display: flex;
            justify-content: space-between;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--gray-800);
            margin-top: 8px;
            padding-top: 8px;
            border-top: 2px dashed var(--gray-300);
        }

        .checkout-btn {
            width: 100%;
            padding: 14px;
            margin-top: 12px;
            font-size: 1.05rem;
            font-weight: 700;
        }

        /* Shortcut Legend Bar */
        .shortcut-bar {
            height: 40px;
            background: var(--gray-800);
            color: var(--gray-300);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            font-size: 0.8rem;
            padding: 0 16px;
            border-top: 1px solid var(--gray-700);
        }

        .shortcut-badge {
            background: rgba(255,255,255,0.15);
            color: #fff;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            margin-right: 4px;
        }

        /* QR Code Display Styles */
        .qr-card-container {
            border: 2px dashed var(--gray-300);
            border-radius: 12px;
            padding: 18px;
            text-align: center;
            background: #fff;
            margin-bottom: 16px;
        }

        .qr-frame {
            width: 170px;
            height: 170px;
            margin: 0 auto 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid var(--gray-200);
            padding: 6px;
            background: #fff;
        }

        .qr-gcash { border-color: #007dfe; }
        .qr-maya { border-color: #00d664; }
        .qr-maribank { border-color: #ff6000; }
    </style>
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <div class="pos-container">
        <!-- Catalog & Search (Left) -->
        <section class="catalog-panel">
            <div class="pos-top-controls">
                <div class="barcode-box">
                    <span class="barcode-icon">🔍</span>
                    <input type="text" id="barcode-input" class="form-control" 
                           placeholder="Type / scan barcode (F1) + Enter..." 
                           autofocus autocomplete="off">
                </div>
                <input type="text" id="name-search" class="form-control" 
                       placeholder="Filter items by name..." 
                       style="max-width:240px;" onkeyup="filterItems()">
                <div style="font-size:0.85rem; color:var(--gray-500); margin-left:auto;">
                    Cashier: <strong><?= htmlspecialchars($_SESSION["full_name"]) ?></strong>
                </div>
            </div>

            <!-- Categories -->
            <div class="category-pills">
                <button class="cat-pill active" onclick="selectCategory('all', this)">🍽️ All Items</button>
                <?php foreach ($categories as $cat): ?>
                    <?php
                        $catIcon = "🍲";
                        $cLower = strtolower($cat);
                        if (strpos($cLower, 'drink') !== false) $catIcon = "🥤";
                        elseif (strpos($cLower, 'dessert') !== false) $catIcon = "🍰";
                        elseif (strpos($cLower, 'appetizer') !== false) $catIcon = "🥟";
                    ?>
                    <button class="cat-pill" onclick="selectCategory('<?= htmlspecialchars($cat) ?>', this)">
                        <?= $catIcon ?> <?= htmlspecialchars($cat) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Product Grid -->
            <div class="product-grid" id="product-grid">
                <?php foreach ($allItemsJson as $item): ?>
                    <?php 
                        $outOfStock = ((int)$item["stock"] <= 0);
                        $lowStock = ((int)$item["stock"] <= 10 && !$outOfStock);
                    ?>
                    <div class="product-card <?= $outOfStock ? 'out-of-stock' : '' ?>"
                         data-id="<?= $item["item_id"] ?>"
                         data-name="<?= htmlspecialchars($item["item_name"]) ?>"
                         data-barcode="<?= htmlspecialchars($item["barcode"] ?? '') ?>"
                         data-price="<?= $item["price"] ?>"
                         data-stock="<?= $item["stock"] ?>"
                         data-category="<?= htmlspecialchars($item["category_name"]) ?>"
                         onclick="addItemToCart(<?= $item['item_id'] ?>)">
                        
                        <!-- Product Photo / Thumbnail -->
                        <div class="prod-img-box">
                            <?php if (!empty($item["image_path"]) && file_exists(__DIR__ . "/../" . $item["image_path"])): ?>
                                <img src="../<?= htmlspecialchars($item["image_path"]) ?>?v=<?= time() ?>" alt="<?= htmlspecialchars($item["item_name"]) ?>">
                            <?php else: ?>
                                <span class="prod-img-placeholder">
                                    <?php
                                        $catL = strtolower($item["category_name"] ?? "");
                                        if (strpos($catL, "drink") !== false) echo "🥤";
                                        elseif (strpos($catL, "dessert") !== false) echo "🍰";
                                        elseif (strpos($catL, "appetizer") !== false) echo "🥟";
                                        else echo "🍲";
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="prod-barcode"><?= htmlspecialchars($item["barcode"] ? '#' . $item["barcode"] : '') ?></div>
                            <div class="prod-name"><?= htmlspecialchars($item["item_name"]) ?></div>
                        </div>
                        <div class="prod-footer">
                            <span class="prod-price">₱<?= number_format($item["price"], 2) ?></span>
                            <?php if ($outOfStock): ?>
                                <span class="prod-stock-tag badge-danger">Out of stock</span>
                            <?php elseif ($lowStock): ?>
                                <span class="prod-stock-tag badge-warning">Low: <?= $item["stock"] ?></span>
                            <?php else: ?>
                                <span class="prod-stock-tag badge-success"><?= $item["stock"] ?> left</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Cart Panel (Right) -->
        <section class="cart-panel">
            <div class="cart-header">
                <span class="card-title" style="font-size:1.05rem;">Current Sale</span>
                <button class="btn btn-secondary btn-sm" onclick="clearCart()">Clear (Esc)</button>
            </div>

            <div class="cart-items-wrapper" id="cart-items-list">
                <div class="cart-empty-state" id="cart-empty">
                    <span style="font-size:2.5rem; margin-bottom:10px;">🛒</span>
                    <p style="font-weight:600;">Cart is empty</p>
                    <small>Scan a barcode or click items to add to sale</small>
                </div>
            </div>

            <!-- Cart Adjustment & Totals -->
            <div class="cart-summary-area">
                <div class="form-row" style="margin-bottom:10px; gap:8px;">
                    <div class="form-group" style="margin-bottom:0; flex:1;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
                            <label style="font-size:0.78rem; margin-bottom:0;">Tax Rate (Line 10)</label>
                            <a href="javascript:void(0)" onclick="openTaxSettingsModal()" style="font-size:0.72rem; color:var(--primary); font-weight:700; text-decoration:none;" title="Edit Store Default Tax Rate">⚙️ Edit Tax</a>
                        </div>
                        <select id="tax-rate-select" class="form-control" style="padding:6px 10px; font-size:0.85rem;" onchange="onTaxRateChange()">
                            <option value="12" <?= $settings["tax_rate"] == "12" ? "selected" : "" ?>>12% Standard VAT</option>
                            <option value="16" <?= $settings["tax_rate"] == "16" ? "selected" : "" ?>>16% Tax</option>
                            <option value="20" <?= $settings["tax_rate"] == "20" ? "selected" : "" ?>>20% Tax</option>
                            <option value="0" <?= $settings["tax_rate"] == "0" ? "selected" : "" ?>>0% (Tax Exempt)</option>
                            <option value="custom" <?= (!in_array($settings["tax_rate"], ["12", "16", "20", "0"])) ? "selected" : "" ?>>✏️ Custom Rate...</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0; flex:1;">
                        <label style="font-size:0.78rem; margin-bottom:3px;">Discount (F3)</label>
                        <select id="discount-select" class="form-control" style="padding:6px 10px; font-size:0.85rem;" onchange="onDiscountChange()">
                            <option value="none">No Discount</option>
                            <option value="senior">Senior Citizen (20%)</option>
                            <option value="pwd">PWD (20%)</option>
                        </select>
                    </div>
                </div>

                <!-- Custom Tax Rate Input Box (Visible when 'custom' is selected) -->
                <div class="form-group" id="custom-tax-box" style="<?= (!in_array($settings["tax_rate"], ["12", "16", "20", "0"])) ? 'display:block;' : 'display:none;' ?> margin-bottom:10px;">
                    <label style="font-size:0.78rem; color:var(--primary); font-weight:700; margin-bottom:3px; display:block;">
                        Enter Custom Tax Rate (%)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="custom-tax-rate-input" class="form-control" 
                               style="padding:6px 10px; font-size:0.85rem;" 
                               min="0" max="100" step="0.01" 
                               value="<?= htmlspecialchars($settings["tax_rate"]) ?>" 
                               placeholder="e.g. 10.00" 
                               oninput="calculateCart()">
                        <span style="font-weight:700; font-size:0.9rem; color:var(--gray-600);">%</span>
                    </div>
                </div>

                <!-- Senior / PWD ID Input (Visible when selected) -->
                <div class="form-group" id="discount-id-box" style="display:none; margin-bottom:10px;">
                    <label style="font-size:0.78rem; color:#b45309; font-weight:700;">
                        Required Senior / PWD ID Number *
                    </label>
                    <input type="text" id="discount-id-no" class="form-control" placeholder="Enter OSCA or PWD ID Number">
                </div>

                <div class="summary-line">
                    <span>Subtotal:</span>
                    <span>₱<span id="disp-subtotal">0.00</span></span>
                </div>
                <div class="summary-line" id="disp-tax-row">
                    <span id="disp-tax-label">VAT / Tax (<span id="disp-tax-rate">12</span>%):</span>
                    <span>₱<span id="disp-tax-amount">0.00</span></span>
                </div>
                <div class="summary-line" id="disp-discount-row" style="color:#b45309; display:none;">
                    <span>Discount (20%):</span>
                    <span>-₱<span id="disp-discount-amount">0.00</span></span>
                </div>
                <div class="summary-total">
                    <span>Total Due:</span>
                    <span style="color:var(--primary);">₱<span id="disp-grand-total">0.00</span></span>
                </div>

                <button class="btn btn-primary checkout-btn" id="btn-checkout" onclick="openCheckoutModal()" disabled>
                    Proceed to Payment (F2) &rarr;
                </button>
            </div>
        </section>
    </div>

    <!-- Mouseless Shortcut Bar -->
    <footer class="shortcut-bar">
        <div><span class="shortcut-badge">F1</span> Barcode / Search</div>
        <div><span class="shortcut-badge">F2</span> Checkout / Pay</div>
        <div><span class="shortcut-badge">F3</span> Discount Toggle</div>
        <div><span class="shortcut-badge">F4</span> Payment Method</div>
        <div><span class="shortcut-badge">Esc</span> Clear / Close Modal</div>
    </footer>

    <!-- Modal: Checkout & Payment -->
    <div class="modal-overlay" id="checkout-modal">
        <div class="modal-card" style="max-width: 520px;">
            <div class="modal-header">
                <h3 class="modal-title">Payment & Checkout</h3>
                <button class="modal-close" onclick="closeModal('checkout-modal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background:#eff6ff; padding:12px 16px; border-radius:8px; margin-bottom:18px; text-align:center;">
                    <div style="font-size:0.85rem; color:#1e40af; font-weight:600;">TOTAL AMOUNT DUE</div>
                    <div style="font-size:2rem; font-weight:800; color:#1e40af;">₱<span id="modal-total-due">0.00</span></div>
                    <div id="modal-discount-tag" style="font-size:0.8rem; color:#92400e; font-weight:600; display:none;"></div>
                </div>

                <div class="form-group">
                    <label for="payment-method-select">Payment Method (F4)</label>
                    <select id="payment-method-select" class="form-control" style="font-weight:700;" onchange="onPaymentMethodChange()">
                        <option value="cash">💵 Cash</option>
                        <option value="gcash">📱 GCash (QR Display)</option>
                        <option value="maya">📱 Maya (QR Display)</option>
                        <option value="maribank">🏦 MariBank (QR Display)</option>
                        <option value="card">💳 Credit / Debit Card</option>
                    </select>
                </div>

                <!-- Cash Payment Fields -->
                <div id="cash-payment-fields">
                    <div class="form-group">
                        <label for="cash-tendered">Amount Tendered (₱) *</label>
                        <input type="number" step="0.01" id="cash-tendered" class="form-control" 
                               placeholder="Enter cash received" style="font-size:1.2rem; font-weight:700;" oninput="computeChange()">
                    </div>
                    <!-- Quick cash buttons -->
                    <div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setExactCash()">Exact</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addCash(100)">+100</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addCash(200)">+200</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addCash(500)">+500</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addCash(1000)">+1000</button>
                    </div>
                    <div style="background:#f8fafc; padding:12px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600;">Change:</span>
                        <span style="font-size:1.4rem; font-weight:800; color:var(--success);">₱<span id="cash-change">0.00</span></span>
                    </div>
                </div>

                <!-- QR Cashless Payment Display (GCash / Maya / MariBank) -->
                <div id="qr-payment-fields" style="display:none;">
                    <div class="qr-card-container">
                        <div id="qr-brand-header" style="font-weight:800; font-size:1.1rem; margin-bottom:8px;"></div>
                        <div class="qr-frame" id="qr-frame-box">
                            <!-- SVG QR Code -->
                            <svg width="150" height="150" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect width="100" height="100" fill="white"/>
                                <!-- QR Mock Patterns -->
                                <rect x="5" y="5" width="28" height="28" fill="#111" rx="3"/>
                                <rect x="9" y="9" width="20" height="20" fill="white"/>
                                <rect x="13" y="13" width="12" height="12" fill="#111"/>

                                <rect x="67" y="5" width="28" height="28" fill="#111" rx="3"/>
                                <rect x="71" y="9" width="20" height="20" fill="white"/>
                                <rect x="75" y="13" width="12" height="12" fill="#111"/>

                                <rect x="5" y="67" width="28" height="28" fill="#111" rx="3"/>
                                <rect x="9" y="71" width="20" height="20" fill="white"/>
                                <rect x="13" y="75" width="12" height="12" fill="#111"/>

                                <rect x="38" y="10" width="8" height="8" fill="#111"/>
                                <rect x="50" y="10" width="8" height="8" fill="#111"/>
                                <rect x="42" y="24" width="16" height="8" fill="#111"/>

                                <rect x="10" y="38" width="8" height="16" fill="#111"/>
                                <rect x="24" y="42" width="12" height="8" fill="#111"/>
                                <rect x="40" y="40" width="20" height="20" fill="#2563eb" rx="2"/>

                                <rect x="68" y="40" width="10" height="8" fill="#111"/>
                                <rect x="82" y="38" width="8" height="14" fill="#111"/>
                                <rect x="68" y="54" width="22" height="8" fill="#111"/>

                                <rect x="38" y="68" width="10" height="8" fill="#111"/>
                                <rect x="54" y="68" width="8" height="12" fill="#111"/>
                                <rect x="42" y="82" width="18" height="8" fill="#111"/>
                                <rect x="68" y="76" width="22" height="14" fill="#111"/>
                            </svg>
                        </div>
                        <div id="qr-account-info" style="font-size:0.85rem; color:var(--gray-700);"></div>
                        <small style="color:var(--gray-500); display:block; margin-top:4px;">Scan with your mobile wallet app to pay</small>
                    </div>

                    <div class="form-group">
                        <label for="reference-no">Transaction / Reference Number *</label>
                        <input type="text" id="reference-no" class="form-control" placeholder="e.g. 10029384812">
                    </div>
                </div>

                <!-- Card Payment Fields -->
                <div id="card-payment-fields" style="display:none;">
                    <div class="form-group">
                        <label for="card-ref-no">POS Terminal Trace / Approval Code *</label>
                        <input type="text" id="card-ref-no" class="form-control" placeholder="e.g. TRC-94821">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('checkout-modal')">Cancel (Esc)</button>
                <button type="button" class="btn btn-success" id="btn-confirm-order" onclick="submitFinalOrder()">
                    Confirm & Complete Sale &rarr;
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Soft-Copy Receipt UI (Line 13) -->
    <div class="modal-overlay" id="receipt-modal">
        <div class="modal-card" style="max-width: 380px;">
            <div class="modal-header">
                <h3 class="modal-title">Official Receipt Preview</h3>
                <button class="modal-close" onclick="closeModal('receipt-modal')">&times;</button>
            </div>
            <div class="modal-body" style="padding:14px; background:#f8fafc;">
                <!-- Printable Thermal Receipt -->
                <div class="receipt-wrapper" id="printable-receipt">
                    <div class="receipt-header">
                        <div class="receipt-title" id="rcpt-store-name">CAPUZAN KUSINA</div>
                        <div id="rcpt-store-address" style="font-size:11px;">123 Main Street, Metro Manila</div>
                        <div id="rcpt-store-contact" style="font-size:11px;">Tel: +63 912 345 6789</div>
                        <div class="receipt-double-divider"></div>
                        <div class="receipt-bold">SALES INVOICE</div>
                        <div class="receipt-row" style="font-size:11px;">
                            <span>Invoice #: <strong id="rcpt-order-id"></strong></span>
                            <span id="rcpt-date"></span>
                        </div>
                        <div class="receipt-row" style="font-size:11px;">
                            <span>Cashier: <strong id="rcpt-cashier"></strong></span>
                            <span>POS Terminal #1</span>
                        </div>
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Items Table -->
                    <div id="rcpt-items-list"></div>

                    <div class="receipt-divider"></div>

                    <!-- Computations -->
                    <div class="receipt-row">
                        <span>Subtotal:</span>
                        <span>₱<span id="rcpt-subtotal"></span></span>
                    </div>
                    <div class="receipt-row" id="rcpt-tax-row">
                        <span id="rcpt-tax-label">VAT (<span id="rcpt-tax-rate">12</span>%):</span>
                        <span>₱<span id="rcpt-tax-amount">0.00</span></span>
                    </div>
                    <div class="receipt-row" id="rcpt-discount-row" style="display:none;">
                        <span>Discount (<span id="rcpt-discount-type"></span>):</span>
                        <span>-₱<span id="rcpt-discount-amount"></span></span>
                    </div>
                    <div class="receipt-row" id="rcpt-id-row" style="display:none; font-size:11px;">
                        <span>ID #:</span>
                        <span id="rcpt-id-no"></span>
                    </div>

                    <div class="receipt-double-divider"></div>

                    <div class="receipt-row receipt-bold" style="font-size:15px;">
                        <span>TOTAL DUE:</span>
                        <span>₱<span id="rcpt-total"></span></span>
                    </div>

                    <div class="receipt-row" style="margin-top:6px;">
                        <span>Payment Method:</span>
                        <span id="rcpt-payment-method"></span>
                    </div>
                    <div class="receipt-row" id="rcpt-paid-row">
                        <span>Amount Paid:</span>
                        <span>₱<span id="rcpt-amount-paid"></span></span>
                    </div>
                    <div class="receipt-row" id="rcpt-change-row">
                        <span>Change:</span>
                        <span>₱<span id="rcpt-change"></span></span>
                    </div>
                    <div class="receipt-row" id="rcpt-ref-row" style="display:none;">
                        <span>Ref / Trace #:</span>
                        <span id="rcpt-reference-no"></span>
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- BIR Tax Breakdown (Option 3) -->
                    <div class="receipt-row receipt-bold" style="font-size:11px; margin-bottom:2px;">
                        <span>TAX SUMMARY</span>
                    </div>
                    <div class="receipt-row" style="font-size:11px;">
                        <span>VATable Sales:</span>
                        <span>₱<span id="rcpt-vatable-sales">0.00</span></span>
                    </div>
                    <div class="receipt-row" style="font-size:11px;">
                        <span>VAT-Exempt Sales:</span>
                        <span>₱<span id="rcpt-vatexempt-sales">0.00</span></span>
                    </div>
                    <div class="receipt-row" style="font-size:11px;">
                        <span>Zero-Rated Sales:</span>
                        <span>₱<span id="rcpt-zerorated-sales">0.00</span></span>
                    </div>
                    <div class="receipt-row" style="font-size:11px;">
                        <span id="rcpt-vat-breakdown-label">VAT Amount (12%):</span>
                        <span>₱<span id="rcpt-vat-amount">0.00</span></span>
                    </div>

                    <div class="receipt-footer">
                        <div class="receipt-divider"></div>
                        <div id="rcpt-footer-msg">Thank you for dining with us!</div>
                        <div style="font-size:10px; margin-top:4px;">Software Powered by Capuzan Kusina</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:space-between;">
                <button type="button" class="btn btn-secondary" onclick="printReceipt()">
                    🖨️ Print Receipt
                </button>
                <button type="button" class="btn btn-primary" onclick="startNewSale()">
                    ➕ New Sale
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Store Tax Settings -->
    <div class="modal-overlay" id="tax-settings-modal">
        <div class="modal-card" style="max-width:440px;">
            <div class="modal-header">
                <h3 class="modal-title">⚙️ Configure Store Tax Rate</h3>
                <button class="modal-close" onclick="closeModal('tax-settings-modal')">&times;</button>
            </div>
            <form id="tax-settings-form" onsubmit="saveTaxSettings(event)">
                <div class="modal-body" style="padding:20px;">
                    <p style="font-size:0.88rem; color:var(--gray-600); margin-bottom:16px;">
                        Update the store's default Value-Added Tax (VAT) rate. This setting is saved to the database and applies across the system.
                    </p>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="store_tax_rate" style="font-weight:700; font-size:0.85rem; margin-bottom:6px; display:block;">
                            Default Tax Rate (%) *
                        </label>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="number" id="store_tax_rate" class="form-control" 
                                   min="0" max="100" step="0.01" 
                                   value="<?= htmlspecialchars($settings['tax_rate']) ?>" required>
                            <span style="font-size:1.1rem; font-weight:800; color:var(--gray-700);">%</span>
                        </div>
                        <small style="color:var(--gray-500); font-size:0.75rem; display:block; margin-top:4px;">
                            Standard Philippine VAT is 12.00%. Enter 0 for Tax-Exempt.
                        </small>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('tax-settings-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-tax">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const catalogItems = <?= json_encode($allItemsJson) ?>;
        let cart = [];
        let currentTotal = 0;
        let activeCategory = 'all';

        // Keyboard Shortcuts Listeners
        window.addEventListener('keydown', function(e) {
            // F1: Focus Barcode Input
            if (e.key === 'F1') {
                e.preventDefault();
                const input = document.getElementById('barcode-input');
                input.focus();
                input.select();
            }
            // F2: Open Checkout Modal
            else if (e.key === 'F2') {
                e.preventDefault();
                if (cart.length > 0 && !document.getElementById('checkout-modal').classList.contains('active')) {
                    openCheckoutModal();
                } else if (document.getElementById('checkout-modal').classList.contains('active')) {
                    submitFinalOrder();
                }
            }
            // F3: Cycle Discount (None -> Senior -> PWD)
            else if (e.key === 'F3') {
                e.preventDefault();
                const discSelect = document.getElementById('discount-select');
                if (discSelect.value === 'none') discSelect.value = 'senior';
                else if (discSelect.value === 'senior') discSelect.value = 'pwd';
                else discSelect.value = 'none';
                onDiscountChange();
            }
            // F4: Cycle Payment Method
            else if (e.key === 'F4') {
                e.preventDefault();
                if (document.getElementById('checkout-modal').classList.contains('active')) {
                    const paySelect = document.getElementById('payment-method-select');
                    const methods = ['cash', 'gcash', 'maya', 'maribank', 'card'];
                    let nextIdx = (methods.indexOf(paySelect.value) + 1) % methods.length;
                    paySelect.value = methods[nextIdx];
                    onPaymentMethodChange();
                }
            }
            // Esc: Close Modal / Clear search
            else if (e.key === 'Escape') {
                if (document.getElementById('receipt-modal').classList.contains('active')) {
                    startNewSale();
                } else if (document.getElementById('checkout-modal').classList.contains('active')) {
                    closeModal('checkout-modal');
                } else {
                    document.getElementById('barcode-input').value = '';
                    document.getElementById('name-search').value = '';
                    filterItems();
                }
            }
        });

        // Barcode manual entry on Enter
        document.getElementById('barcode-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const code = this.value.trim();
                if (!code) return;
                
                // Lookup item by barcode or exact ID
                const item = catalogItems.find(i => (i.barcode && i.barcode.toLowerCase() === code.toLowerCase()) || i.item_id.toString() === code);
                if (item) {
                    addItemToCart(item.item_id);
                    this.value = '';
                } else {
                    posAlert(`No product found with barcode: "${code}"`, "Product Not Found", "warning");
                }
            }
        });

        function selectCategory(category, btn) {
            activeCategory = category;
            document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            filterItems();
        }

        function filterItems() {
            const query = document.getElementById('name-search').value.toLowerCase();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase();
                const barcode = (card.getAttribute('data-barcode') || '').toLowerCase();
                const category = card.getAttribute('data-category');

                const matchesCat = (activeCategory === 'all' || category === activeCategory);
                const matchesQuery = (!query || name.includes(query) || barcode.includes(query));

                card.style.display = (matchesCat && matchesQuery) ? 'flex' : 'none';
            });
        }

        function addItemToCart(itemId) {
            const product = catalogItems.find(p => p.item_id == itemId);
            if (!product) return;

            if (product.stock <= 0) {
                posAlert(`"${product.item_name}" is currently Out of Stock.`, "Item Unavailable", "danger");
                return;
            }

            const existing = cart.find(c => c.itemId == itemId);
            if (existing) {
                if (existing.qty + 1 > product.stock) {
                    posAlert(`Cannot add more. Available stock for "${product.item_name}" is ${product.stock}.`, "Stock Limit Reached", "warning");
                    return;
                }
                existing.qty++;
            } else {
                cart.push({
                    itemId: product.item_id,
                    itemName: product.item_name,
                    price: parseFloat(product.price),
                    stock: parseInt(product.stock),
                    qty: 1
                });
            }

            renderCart();
            posToast(`Added "${product.item_name}"`, "info", 1800);
        }

        function changeQty(itemId, delta) {
            const item = cart.find(c => c.itemId == itemId);
            if (!item) return;

            if (delta > 0 && item.qty + delta > item.stock) {
                posAlert(`Available stock limit (${item.stock}) reached for "${item.itemName}".`, "Stock Limit Reached", "warning");
                return;
            }

            item.qty += delta;
            if (item.qty <= 0) {
                cart = cart.filter(c => c.itemId != itemId);
            }
            renderCart();
        }

        function removeItem(itemId) {
            const it = cart.find(c => c.itemId == itemId);
            const name = it ? it.itemName : "Item";
            cart = cart.filter(c => c.itemId != itemId);
            renderCart();
            posToast(`Removed "${name}" from cart`, "warning", 2000);
        }

        function clearCart() {
            if (cart.length > 0) {
                posConfirm("Clear Current Cart?", "Are you sure you want to empty all items from the active order cart?", function() {
                    cart = [];
                    renderCart();
                    posToast("Order cart cleared.", "info");
                }, { confirmText: "Clear Cart", isDanger: true });
            }
        }

        function getEffectiveTaxRate() {
            const sel = document.getElementById('tax-rate-select').value;
            if (sel === 'custom') {
                const customIn = document.getElementById('custom-tax-rate-input');
                const val = parseFloat(customIn ? customIn.value : 0);
                return isNaN(val) ? 0 : Math.max(0, val);
            }
            return parseFloat(sel) || 0;
        }

        function onTaxRateChange() {
            const sel = document.getElementById('tax-rate-select').value;
            const box = document.getElementById('custom-tax-box');
            if (sel === 'custom') {
                box.style.display = 'block';
                const input = document.getElementById('custom-tax-rate-input');
                if (input) input.focus();
            } else {
                box.style.display = 'none';
            }
            calculateCart();
        }

        function openTaxSettingsModal() {
            document.getElementById('tax-settings-modal').classList.add('active');
            setTimeout(() => {
                const inEl = document.getElementById('store_tax_rate');
                if (inEl) inEl.focus();
            }, 100);
        }

        function saveTaxSettings(e) {
            e.preventDefault();
            const rateInput = document.getElementById('store_tax_rate');
            const newRate = parseFloat(rateInput.value);

            if (isNaN(newRate) || newRate < 0 || newRate > 100) {
                posAlert("Please enter a valid tax rate between 0% and 100%.", "Invalid Tax Rate", "warning");
                return;
            }

            const btn = document.getElementById('btn-save-tax');
            btn.disabled = true;
            btn.textContent = 'Saving...';

            fetch('update_tax_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ taxRate: newRate })
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = 'Save Changes';

                if (data.success) {
                    closeModal('tax-settings-modal');
                    posToast(`Default tax rate updated to ${data.taxRate}%!`, "success", 3000);

                    // Update UI select option or custom input
                    const taxSel = document.getElementById('tax-rate-select');
                    let foundMatch = false;
                    for (let opt of taxSel.options) {
                        if (parseFloat(opt.value) === parseFloat(data.taxRate)) {
                            taxSel.value = opt.value;
                            foundMatch = true;
                            break;
                        }
                    }

                    const customBox = document.getElementById('custom-tax-box');
                    const customIn = document.getElementById('custom-tax-rate-input');
                    if (customIn) customIn.value = data.taxRate;

                    if (!foundMatch) {
                        taxSel.value = 'custom';
                        if (customBox) customBox.style.display = 'block';
                    } else {
                        if (customBox) customBox.style.display = 'none';
                    }

                    calculateCart();
                } else {
                    posAlert("Error: " + data.message, "Update Failed", "danger");
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = 'Save Changes';
                posAlert("Network error updating tax rate settings.", "Connection Error", "danger");
            });
        }

        function onDiscountChange() {
            const discType = document.getElementById('discount-select').value;
            const idBox = document.getElementById('discount-id-box');
            if (discType === 'senior' || discType === 'pwd') {
                idBox.style.display = 'block';
                document.getElementById('discount-id-no').focus();
            } else {
                idBox.style.display = 'none';
            }
            calculateCart();
        }

        function calculateCart() {
            let subtotal = 0;
            cart.forEach(c => {
                subtotal += c.price * c.qty;
            });

            const taxRate = getEffectiveTaxRate();
            const discountType = document.getElementById('discount-select').value;

            let taxAmount = 0;
            let discountAmount = 0;
            let grandTotal = 0;

            if (discountType === 'senior' || discountType === 'pwd') {
                // Philippine statutory law: VAT Exempt (0%) + 20% discount
                taxAmount = 0;
                discountAmount = subtotal * 0.20;
                grandTotal = subtotal - discountAmount;
                document.getElementById('disp-discount-row').style.display = 'flex';
                document.getElementById('disp-tax-row').style.display = 'flex';
                document.getElementById('disp-tax-label').innerHTML = 'VAT (0% Exempt):';
                document.getElementById('disp-tax-amount').textContent = '0.00';
            } else {
                taxAmount = subtotal * (taxRate / 100);
                discountAmount = 0;
                grandTotal = subtotal + taxAmount;
                document.getElementById('disp-discount-row').style.display = 'none';
                document.getElementById('disp-tax-row').style.display = 'flex';
                document.getElementById('disp-tax-label').innerHTML = `VAT / Tax (<span id="disp-tax-rate">${taxRate}</span>%):`;
                document.getElementById('disp-tax-amount').textContent = taxAmount.toFixed(2);
            }

            currentTotal = grandTotal;

            document.getElementById('disp-subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('disp-tax-amount').textContent = taxAmount.toFixed(2);
            document.getElementById('disp-discount-amount').textContent = discountAmount.toFixed(2);
            document.getElementById('disp-grand-total').textContent = grandTotal.toFixed(2);

            document.getElementById('btn-checkout').disabled = (cart.length === 0);
        }

        function renderCart() {
            const wrapper = document.getElementById('cart-items-list');
            if (cart.length === 0) {
                wrapper.innerHTML = `
                    <div class="cart-empty-state">
                        <span style="font-size:2.5rem; margin-bottom:10px;">🛒</span>
                        <p style="font-weight:600;">Cart is empty</p>
                        <small>Scan a barcode or click items to add to sale</small>
                    </div>
                `;
            } else {
                let html = '';
                cart.forEach(item => {
                    const lineSub = item.price * item.qty;
                    html += `
                        <div class="cart-item-row">
                            <div class="cart-item-info">
                                <div class="cart-item-name">${item.itemName}</div>
                                <div class="cart-item-price">₱${item.price.toFixed(2)} each</div>
                            </div>
                            <div class="cart-qty-ctrl">
                                <button class="qty-btn" onclick="changeQty(${item.itemId}, -1)">-</button>
                                <span class="qty-disp">${item.qty}</span>
                                <button class="qty-btn" onclick="changeQty(${item.itemId}, 1)">+</button>
                            </div>
                            <div class="cart-item-sub">₱${lineSub.toFixed(2)}</div>
                            <div class="cart-item-remove" onclick="removeItem(${item.itemId})" title="Remove item">✕</div>
                        </div>
                    `;
                });
                wrapper.innerHTML = html;
            }

            calculateCart();
        }

        // Checkout Modal Functions
        function openCheckoutModal() {
            if (cart.length === 0) return;

            const discountType = document.getElementById('discount-select').value;
            const discountId = document.getElementById('discount-id-no').value.trim();

            if ((discountType === 'senior' || discountType === 'pwd') && !discountId) {
                posAlert(`Please enter the required Senior / PWD ID Number before proceeding.`, "Senior / PWD ID Required", "warning");
                document.getElementById('discount-id-no').focus();
                return;
            }

            document.getElementById('modal-total-due').textContent = currentTotal.toFixed(2);
            const discTag = document.getElementById('modal-discount-tag');
            if (discountType === 'senior' || discountType === 'pwd') {
                discTag.style.display = 'block';
                discTag.textContent = `Applied 20% ${discountType.toUpperCase()} Discount (ID: ${discountId})`;
            } else {
                discTag.style.display = 'none';
            }

            document.getElementById('payment-method-select').value = 'cash';
            onPaymentMethodChange();
            setExactCash();

            document.getElementById('checkout-modal').classList.add('active');
            setTimeout(() => {
                document.getElementById('cash-tendered').focus();
                document.getElementById('cash-tendered').select();
            }, 100);
        }

        function onPaymentMethodChange() {
            const method = document.getElementById('payment-method-select').value;
            const cashFields = document.getElementById('cash-payment-fields');
            const qrFields = document.getElementById('qr-payment-fields');
            const cardFields = document.getElementById('card-payment-fields');

            cashFields.style.display = 'none';
            qrFields.style.display = 'none';
            cardFields.style.display = 'none';

            if (method === 'cash') {
                cashFields.style.display = 'block';
                computeChange();
            } else if (method === 'gcash' || method === 'maya' || method === 'maribank') {
                qrFields.style.display = 'block';
                const header = document.getElementById('qr-brand-header');
                const info = document.getElementById('qr-account-info');
                const frame = document.getElementById('qr-frame-box');

                frame.className = 'qr-frame qr-' + method;

                if (method === 'gcash') {
                    header.innerHTML = '<span style="color:#007dfe;">📱 GCash Instant QR</span>';
                    info.innerHTML = '<strong>Merchant:</strong> Capuzan Kusina<br><strong>No:</strong> 0917-888-GCASH';
                } else if (method === 'maya') {
                    header.innerHTML = '<span style="color:#00d664;">📱 Maya QR Pay</span>';
                    info.innerHTML = '<strong>Merchant:</strong> Capuzan Kusina<br><strong>No:</strong> 0918-999-MAYA';
                } else if (method === 'maribank') {
                    header.innerHTML = '<span style="color:#ff6000;">🏦 MariBank QR</span>';
                    info.innerHTML = '<strong>Merchant:</strong> Capuzan Kusina<br><strong>Account:</strong> 1098-7654-MARI';
                }
                document.getElementById('reference-no').value = '';
                document.getElementById('reference-no').focus();
            } else if (method === 'card') {
                cardFields.style.display = 'block';
                document.getElementById('card-ref-no').value = '';
                document.getElementById('card-ref-no').focus();
            }
        }

        function setExactCash() {
            document.getElementById('cash-tendered').value = currentTotal.toFixed(2);
            computeChange();
        }

        function addCash(amount) {
            const input = document.getElementById('cash-tendered');
            let val = parseFloat(input.value) || 0;
            input.value = (val + amount).toFixed(2);
            computeChange();
        }

        function computeChange() {
            const tendered = parseFloat(document.getElementById('cash-tendered').value) || 0;
            const change = tendered - currentTotal;
            const changeSpan = document.getElementById('cash-change');
            if (change >= 0) {
                changeSpan.textContent = change.toFixed(2);
                changeSpan.style.color = 'var(--success)';
            } else {
                changeSpan.textContent = "0.00 (Short ₱" + Math.abs(change).toFixed(2) + ")";
                changeSpan.style.color = 'var(--danger)';
            }
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Submit Order to backend
        function submitFinalOrder() {
            const method = document.getElementById('payment-method-select').value;
            let amountPaid = 0;
            let referenceNo = '';

            if (method === 'cash') {
                amountPaid = parseFloat(document.getElementById('cash-tendered').value) || 0;
                if (amountPaid < currentTotal) {
                    posAlert(`Cash tendered (₱${amountPaid.toFixed(2)}) is less than total due (₱${currentTotal.toFixed(2)}).`, "Insufficient Payment", "warning");
                    document.getElementById('cash-tendered').focus();
                    return;
                }
            } else if (method === 'gcash' || method === 'maya' || method === 'maribank') {
                referenceNo = document.getElementById('reference-no').value.trim();
                amountPaid = currentTotal;
                if (!referenceNo) {
                    posAlert(`Please enter the e-wallet reference number.`, "Reference Number Required", "warning");
                    document.getElementById('reference-no').focus();
                    return;
                }
            } else if (method === 'card') {
                referenceNo = document.getElementById('card-ref-no').value.trim();
                amountPaid = currentTotal;
                if (!referenceNo) {
                    posAlert(`Please enter the card terminal trace/approval code.`, "Trace Code Required", "warning");
                    document.getElementById('card-ref-no').focus();
                    return;
                }
            }

            const payload = {
                cart: cart,
                paymentMethod: method,
                taxRate: getEffectiveTaxRate(),
                discountType: document.getElementById('discount-select').value,
                discountIdNo: document.getElementById('discount-id-no').value.trim(),
                amountPaid: amountPaid,
                referenceNo: referenceNo
            };

            const confirmBtn = document.getElementById('btn-confirm-order');
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Processing Sale...';

            fetch('process_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm & Complete Sale →';

                if (data.success) {
                    closeModal('checkout-modal');
                    // Display soft-copy receipt UI
                    renderReceipt(data.receipt);
                    posToast("Transaction completed successfully!", "success", 2500);
                    // Update client-side catalog stock counts
                    cart.forEach(c => {
                        const it = catalogItems.find(p => p.item_id == c.itemId);
                        if (it) it.stock -= c.qty;
                    });
                } else {
                    posAlert("Error: " + data.message, "Transaction Failed", "danger");
                }
            })
            .catch(err => {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm & Complete Sale →';
                posAlert("Network / server error processing order. Please check connection.", "Server Error", "danger");
            });
        }

        // Render Thermal Soft-Copy Receipt (Line 13)
        function renderReceipt(receipt) {
            document.getElementById('rcpt-store-name').textContent = receipt.storeName.toUpperCase();
            document.getElementById('rcpt-store-address').textContent = receipt.storeAddress;
            document.getElementById('rcpt-store-contact').textContent = "Tel: " + receipt.storeContact;
            document.getElementById('rcpt-order-id').textContent = receipt.orderId;
            document.getElementById('rcpt-date').textContent = receipt.date;
            document.getElementById('rcpt-cashier').textContent = receipt.cashier;
            document.getElementById('rcpt-footer-msg').textContent = receipt.receiptFooter;

            // Render Itemized Rows
            let itemsHtml = '';
            receipt.items.forEach(it => {
                itemsHtml += `
                    <div class="receipt-row">
                        <span>${it.itemName} x${it.qty}</span>
                        <span>₱${parseFloat(it.subtotal).toFixed(2)}</span>
                    </div>
                `;
            });
            document.getElementById('rcpt-items-list').innerHTML = itemsHtml;

            document.getElementById('rcpt-subtotal').textContent = parseFloat(receipt.subtotal).toFixed(2);
            document.getElementById('rcpt-total').textContent = parseFloat(receipt.grandTotal).toFixed(2);
            document.getElementById('rcpt-payment-method').textContent = receipt.paymentMethod;
            document.getElementById('rcpt-amount-paid').textContent = parseFloat(receipt.amountPaid).toFixed(2);
            document.getElementById('rcpt-change').textContent = parseFloat(receipt.changeAmount).toFixed(2);

            // Tax Line (Always shown)
            const taxRow = document.getElementById('rcpt-tax-row');
            taxRow.style.display = 'flex';
            const taxLabel = document.getElementById('rcpt-tax-label');
            const isExempt = (receipt.discountType && receipt.discountType !== 'none') || parseFloat(receipt.taxRate) === 0;
            const taxAmt = parseFloat(receipt.taxAmount) || 0;

            if (taxAmt > 0) {
                if (taxLabel) taxLabel.innerHTML = `VAT (${receipt.taxRate}%):`;
                document.getElementById('rcpt-tax-amount').textContent = taxAmt.toFixed(2);
            } else if (isExempt) {
                if (taxLabel) taxLabel.innerHTML = `VAT (0% Exempt):`;
                document.getElementById('rcpt-tax-amount').textContent = '0.00';
            } else {
                if (taxLabel) taxLabel.innerHTML = `VAT (0%):`;
                document.getElementById('rcpt-tax-amount').textContent = '0.00';
            }

            // BIR Tax Breakdown (Option 3)
            const vatableSales = receipt.vatableSales !== undefined 
                ? parseFloat(receipt.vatableSales) 
                : (taxAmt > 0 ? parseFloat(receipt.subtotal) : 0);
            const vatExemptSales = receipt.vatExemptSales !== undefined 
                ? parseFloat(receipt.vatExemptSales) 
                : (taxAmt > 0 ? 0 : parseFloat(receipt.subtotal));
            const zeroRatedSales = receipt.zeroRatedSales !== undefined ? parseFloat(receipt.zeroRatedSales) : 0;
            const vatRate = receipt.taxRate || 12;

            if (document.getElementById('rcpt-vatable-sales')) {
                document.getElementById('rcpt-vatable-sales').textContent = vatableSales.toFixed(2);
                document.getElementById('rcpt-vatexempt-sales').textContent = vatExemptSales.toFixed(2);
                document.getElementById('rcpt-zerorated-sales').textContent = zeroRatedSales.toFixed(2);
                document.getElementById('rcpt-vat-breakdown-label').textContent = `VAT Amount (${isExempt && taxAmt === 0 ? '0%' : vatRate + '%'}):`;
                document.getElementById('rcpt-vat-amount').textContent = taxAmt.toFixed(2);
            }

            // Discount
            const discRow = document.getElementById('rcpt-discount-row');
            const idRow = document.getElementById('rcpt-id-row');
            if (receipt.discountAmount > 0) {
                discRow.style.display = 'flex';
                document.getElementById('rcpt-discount-type').textContent = receipt.discountType.toUpperCase();
                document.getElementById('rcpt-discount-amount').textContent = parseFloat(receipt.discountAmount).toFixed(2);
                if (receipt.discountIdNo) {
                    idRow.style.display = 'flex';
                    document.getElementById('rcpt-id-no').textContent = receipt.discountIdNo;
                } else {
                    idRow.style.display = 'none';
                }
            } else {
                discRow.style.display = 'none';
                idRow.style.display = 'none';
            }

            // Reference No
            const refRow = document.getElementById('rcpt-ref-row');
            if (receipt.referenceNo) {
                refRow.style.display = 'flex';
                document.getElementById('rcpt-reference-no').textContent = receipt.referenceNo;
            } else {
                refRow.style.display = 'none';
            }

            document.getElementById('receipt-modal').classList.add('active');
        }

        function printReceipt() {
            window.print();
        }

        function startNewSale() {
            closeModal('receipt-modal');
            cart = [];
            document.getElementById('discount-select').value = 'none';
            document.getElementById('discount-id-no').value = '';
            document.getElementById('discount-id-box').style.display = 'none';
            renderCart();
            // Refocus barcode input for next sale
            const barcodeIn = document.getElementById('barcode-input');
            barcodeIn.focus();
            barcodeIn.select();
        }
    </script>
</body>
</html>
