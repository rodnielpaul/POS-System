<?php
session_start();
require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/logger.php";
header("Content-Type: application/json");

$allowedRoles = ["admin", "manager", "cashier"];
if (!isset($_SESSION["role"]) || !in_array($_SESSION["role"], $allowedRoles, true)) {
    echo json_encode(["success" => false, "message" => "Not authorized."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$cart = $data["cart"] ?? [];
$paymentMethod = $data["paymentMethod"] ?? "cash";
$taxRate = isset($data["taxRate"]) ? (float)$data["taxRate"] : 12.00;
$discountType = $data["discountType"] ?? "none";
$discountIdNo = trim($data["discountIdNo"] ?? "");
$amountPaid = isset($data["amountPaid"]) ? (float)$data["amountPaid"] : 0.00;
$referenceNo = trim($data["referenceNo"] ?? "");

if (empty($cart)) {
    echo json_encode(["success" => false, "message" => "Cart is empty."]);
    exit;
}

// Validation for Senior / PWD ID
if (in_array($discountType, ["senior", "pwd"], true) && empty($discountIdNo)) {
    echo json_encode(["success" => false, "message" => "Senior / PWD ID number is required for discount records."]);
    exit;
}

$userId = (int)$_SESSION["user_id"];
$cashierName = $_SESSION["full_name"] ?? $_SESSION["username"];

// Begin Transaction
$conn->begin_transaction();

try {
    // 1. Validate stock and compute item totals
    $subtotal = 0;
    $processedItems = [];

    // Lock and check items
    $checkStmt = $conn->prepare("SELECT item_id, item_name, price, stock, is_available FROM menu_items WHERE item_id = ? FOR UPDATE");

    foreach ($cart as $cItem) {
        $itemId = (int)$cItem["itemId"];
        $qty = (int)$cItem["qty"];

        if ($qty <= 0) continue;

        $checkStmt->bind_param("i", $itemId);
        $checkStmt->execute();
        $res = $checkStmt->get_result();
        $dbItem = $res->fetch_assoc();

        if (!$dbItem) {
            throw new Exception("Product ID #$itemId not found.");
        }
        if ($dbItem["is_available"] != 1) {
            throw new Exception("Product '{$dbItem['item_name']}' is currently unavailable.");
        }
        if ($dbItem["stock"] < $qty) {
            throw new Exception("Insufficient stock for '{$dbItem['item_name']}'. Only {$dbItem['stock']} left.");
        }

        $itemPrice = (float)$dbItem["price"];
        $lineSubtotal = $itemPrice * $qty;
        $subtotal += $lineSubtotal;

        $processedItems[] = [
            "itemId" => $itemId,
            "itemName" => $dbItem["item_name"],
            "price" => $itemPrice,
            "qty" => $qty,
            "subtotal" => $lineSubtotal
        ];
    }

    if (empty($processedItems)) {
        throw new Exception("No valid items in cart.");
    }

    // 2. Compute Tax and Discount
    $discountAmount = 0.00;
    $taxAmount = 0.00;

    if (in_array($discountType, ["senior", "pwd"], true)) {
        // Philippine Statutory Rule: VAT Exempt + 20% discount
        $taxAmount = 0.00;
        $discountAmount = round($subtotal * 0.20, 2);
        $grandTotal = round($subtotal - $discountAmount, 2);
    } else {
        // Standard Tax Calculation based on selected tax rate
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $grandTotal = round($subtotal + $taxAmount, 2);
    }

    // Validate cash payment
    $changeAmount = 0.00;
    if ($paymentMethod === "cash") {
        if ($amountPaid < $grandTotal) {
            throw new Exception("Amount paid (₱" . number_format($amountPaid, 2) . ") is less than the total due (₱" . number_format($grandTotal, 2) . ").");
        }
        $changeAmount = round($amountPaid - $grandTotal, 2);
    } else {
        $amountPaid = $grandTotal;
        $changeAmount = 0.00;
    }

    // 3. Insert into `orders`
    $orderStmt = $conn->prepare("
        INSERT INTO orders (user_id, subtotal, total_amount, tax_rate, tax_amount, discount_type, discount_id_no, discount_amount, payment_method, amount_paid, change_amount, reference_no, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
    ");
    $orderStmt->bind_param("iddddssdsdds", 
        $userId, $subtotal, $grandTotal, $taxRate, $taxAmount, $discountType, 
        $discountIdNo, $discountAmount, $paymentMethod, $amountPaid, $changeAmount, $referenceNo
    );
    $orderStmt->execute();
    $orderId = $conn->insert_id;

    // 4. Insert into `order_items` & deduct inventory stock
    $orderItemStmt = $conn->prepare("INSERT INTO order_items (order_id, item_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
    $stockDeductStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE item_id = ?");
    $invLogStmt = $conn->prepare("INSERT INTO inventory_logs (item_id, action_type, quantity_change, reference_no, notes, user_id) VALUES (?, 'sale', ?, ?, 'POS Order Sale', ?)");

    foreach ($processedItems as $item) {
        // Order item record
        $orderItemStmt->bind_param("iiidd", $orderId, $item["itemId"], $item["qty"], $item["price"], $item["subtotal"]);
        $orderItemStmt->execute();

        // Stock deduction
        $stockDeductStmt->bind_param("ii", $item["qty"], $item["itemId"]);
        $stockDeductStmt->execute();

        // Inventory log (negative change for sale)
        $negQty = -$item["qty"];
        $refStr = "ORDER #" . $orderId;
        $invLogStmt->bind_param("iisi", $item["itemId"], $negQty, $refStr, $userId);
        $invLogStmt->execute();
    }

    // 5. System activity log
    log_activity($conn, $userId, "SALE_COMPLETED", "Completed Order #$orderId (Total: ₱$grandTotal, Method: $paymentMethod)");

    $conn->commit();

    // Fetch store settings for receipt
    $storeSettings = [
        "store_name" => "Capuzan Kusina",
        "store_address" => "123 Main Street, Metro Manila",
        "store_contact" => "+63 912 345 6789",
        "receipt_footer" => "Thank you for dining with us! Please come again."
    ];
    $settRes = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($settRes) {
        while ($s = $settRes->fetch_assoc()) {
            $storeSettings[$s["setting_key"]] = $s["setting_value"];
        }
    }

    // Return receipt payload
    echo json_encode([
        "success" => true,
        "receipt" => [
            "orderId" => $orderId,
            "date" => date("Y-m-d H:i:s"),
            "cashier" => $cashierName,
            "items" => $processedItems,
            "subtotal" => $subtotal,
            "taxRate" => $taxRate,
            "taxAmount" => $taxAmount,
            "discountType" => $discountType,
            "discountIdNo" => $discountIdNo,
            "discountAmount" => $discountAmount,
            "grandTotal" => $grandTotal,
            "paymentMethod" => strtoupper($paymentMethod),
            "referenceNo" => $referenceNo,
            "amountPaid" => $amountPaid,
            "changeAmount" => $changeAmount,
            "storeName" => $storeSettings["store_name"],
            "storeAddress" => $storeSettings["store_address"],
            "storeContact" => $storeSettings["store_contact"],
            "receiptFooter" => $storeSettings["receipt_footer"]
        ]
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
