<?php
// This file is included by quotation.php when action=edit
// All session and database checks are already done in quotation.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Quotation <?php echo htmlspecialchars($quotation['quotation_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
        }

        .quotation-form-container {
            max-width: 1200px;
            margin: 2rem auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Multi-step form styles */
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }

        .step {
            display: flex;
            align-items: center;
            margin: 0 1rem;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 0.5rem;
            transition: all 0.3s ease;
        }

        .step.active .step-number {
            background: var(--primary-color);
            color: white;
        }

        .step.completed .step-number {
            background: #10b981;
            color: white;
        }

        .step-label {
            font-weight: 500;
            color: #6b7280;
            transition: all 0.3s ease;
        }

        .step.active .step-label {
            color: var(--primary-color);
        }

        .step.completed .step-label {
            color: #10b981;
        }

        .step-connector {
            width: 60px;
            height: 2px;
            background: #e5e7eb;
            margin: 0 1rem;
            transition: all 0.3s ease;
        }

        .step.completed + .step .step-connector {
            background: #10b981;
        }

        .form-step {
            display: none;
            padding: 2rem;
            min-height: 500px;
        }

        .form-step.active {
            display: block;
        }

        .step-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 2rem;
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
        }

        .btn-step {
            padding: 0.75rem 2rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .btn-step:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .form-body {
            padding: 2rem;
        }

        .customer-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .items-section {
            margin-bottom: 2rem;
        }

         .item-row {
             display: grid;
             grid-template-columns: 2fr 1fr 1fr 1fr 1fr 40px;
             gap: 1rem;
             margin-bottom: 1rem;
             padding: 1rem;
             background: #f8fafc;
             border-radius: 8px;
         }

        .item-input {
            width: 100%;
        }

        .btn-add-item {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: white;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-add-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .btn-remove-item {
            background: #dc3545;
            border: none;
            border-radius: 6px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }

        .totals-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

         .totals-grid {
             display: grid;
             grid-template-columns: repeat(3, 1fr);
             gap: 1rem;
         }

         .tax-details {
             background: #f8fafc;
             border-radius: 8px;
             padding: 1rem;
         }

         .tax-item {
             display: flex;
             justify-content: space-between;
             padding: 0.5rem 0;
             border-bottom: 1px solid #e2e8f0;
         }

         .tax-item:last-child {
             border-bottom: none;
         }

         .tax-item .tax-name {
             font-weight: 500;
             color: #374151;
         }

         .tax-item .tax-amount {
             font-weight: 600;
             color: var(--primary-color);
         }

        .total-input {
            text-align: right;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .btn-save {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
        }

        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .product-search-results {
            position: absolute;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: 100%;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .product-search-item {
            padding: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.2s;
        }

        .product-search-item:hover {
            background: #f8fafc;
        }

        .product-search-item:last-child {
            border-bottom: none;
        }

        /* Modern Customer Search Styles */
        .customer-search-container {
            position: relative;
        }

        .customer-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-top: none;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            backdrop-filter: blur(8px);
        }

        .customer-search-item {
            padding: 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .customer-search-item:hover,
        .customer-search-item.selected {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transform: translateX(2px);
            border-left: 3px solid var(--primary-color);
        }

        .customer-search-item.selected {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }

        .customer-search-item:last-child {
            border-bottom: none;
        }

        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color) 0%, #8b5cf6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .customer-info {
            flex: 1;
            min-width: 0;
        }

        .customer-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .customer-details {
            font-size: 0.8rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .customer-detail-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .customer-detail-item i {
            font-size: 0.75rem;
            color: #9ca3af;
        }

        .customer-loyalty {
            background: #fef3c7;
            color: #92400e;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .no-customers {
            padding: 2rem;
            text-align: center;
            color: #6b7280;
        }

        .no-customers i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #d1d5db;
        }

        .search-loading {
            padding: 1rem;
            text-align: center;
            color: #6b7280;
        }

        .search-loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

         .search-results-container {
             max-height: 400px;
             overflow-y: auto;
             border: 1px solid #e2e8f0;
             border-radius: 8px;
             background: white;
         }

         .search-result-item {
             padding: 1rem;
             border-bottom: 1px solid #f1f5f9;
             cursor: pointer;
             transition: background-color 0.2s;
         }

         .search-result-item:hover {
             background-color: #f8fafc;
         }

         .search-result-item:last-child {
             border-bottom: none;
         }

         .search-result-item .product-name {
             font-weight: 600;
             color: #374151;
             margin-bottom: 0.25rem;
         }

         .search-result-item .product-details {
             font-size: 0.875rem;
             color: #6b7280;
             margin-bottom: 0.5rem;
         }

         .search-result-item .product-price {
             font-weight: 600;
             color: var(--primary-color);
         }

         .search-result-item .stock-status {
             display: inline-block;
             padding: 0.25rem 0.5rem;
             border-radius: 4px;
             font-size: 0.75rem;
             font-weight: 500;
         }

         .stock-status.in_stock {
             background-color: #dcfce7;
             color: #166534;
         }

         .stock-status.low_stock {
             background-color: #fef3c7;
             color: #92400e;
         }

         .stock-status.out_of_stock {
             background-color: #fee2e2;
             color: #991b1b;
         }

         .modal-lg {
             max-width: 900px;
         }

         .item-row.highlight-duplicate {
             border: 2px solid #ff6b6b;
             background-color: #fff5f5;
             animation: pulse 0.5s ease-in-out;
         }

         @keyframes pulse {
             0% { transform: scale(1); }
             50% { transform: scale(1.02); }
             100% { transform: scale(1); }
         }

         .product-add-section {
             background-color: #f8f9fa;
             border: 2px dashed #dee2e6;
             border-radius: 8px;
             padding: 20px;
             margin-bottom: 20px;
             text-align: center;
         }

         .product-add-section h5 {
             color: #6c757d;
             margin-bottom: 15px;
         }

         .product-add-buttons {
             display: flex;
             gap: 10px;
             justify-content: center;
             flex-wrap: wrap;
         }

         .product-add-buttons .btn {
             min-width: 120px;
         }

         .quantity.is-invalid {
             border-color: #dc3545;
             box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
         }

         .quantity.is-invalid:focus {
             border-color: #dc3545;
             box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
         }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    <div class="main-content" style="padding-bottom: 4rem;">
        <div class="container-fluid" style="margin-bottom: 3rem;">
            <div class="quotation-form-container">
                <div class="form-header">
                    <h2><i class="bi bi-pencil-square"></i> Edit Quotation</h2>
                    <p class="mb-0">Quotation #<?php echo htmlspecialchars($quotation['quotation_number']); ?> - <?php echo htmlspecialchars($quotation['customer_name'] ?: 'Walk-in Customer'); ?></p>
                </div>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label">Customer Info</div>
                    </div>
                    <div class="step-connector"></div>
                    <div class="step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label">Add Products</div>
                    </div>
                    <div class="step-connector"></div>
                    <div class="step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label">Review & Save</div>
                    </div>
                </div>

                <form id="quotationForm">
                    <!-- Step 1: Customer Information -->
                    <div class="form-step active" id="step1">
                        <div class="customer-section">
                        <h5 class="mb-3"><i class="bi bi-person"></i> Customer Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_name" class="form-label">Customer Name *</label>
                                    <input type="text" class="form-control" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($quotation['customer_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="customer_email" name="customer_email" value="<?php echo htmlspecialchars($quotation['customer_email'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control" id="customer_phone" name="customer_phone" value="<?php echo htmlspecialchars($quotation['customer_phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_search" class="form-label">
                                        <i class="bi bi-search me-1"></i> Search Customer
                                    </label>
                                    <div class="customer-search-container">
                                        <input type="text" class="form-control" id="customer_search" placeholder="Type customer name, email, or phone...">
                                        <div id="customer_search_results" class="customer-search-results" style="display: none;"></div>
                                    </div>
                                    <div class="form-text">Search by name, email, or phone number</div>
                                </div>
                                <div class="mb-3">
                                    <label for="customer_id" class="form-label">
                                        <i class="bi bi-person-check me-1"></i> Quick Select
                                    </label>
                                    <select class="form-control" id="customer_id" name="customer_id">
                                        <option value="">Or select from recent customers...</option>
                                        <?php
                                        $customers = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, email, phone FROM customers ORDER BY first_name, last_name LIMIT 10");
                                        while ($customer = $customers->fetch(PDO::FETCH_ASSOC)) {
                                            $selected = ($customer['id'] == $quotation['customer_id']) ? 'selected' : '';
                                            echo "<option value=\"{$customer['id']}\" data-email=\"{$customer['email']}\" data-phone=\"{$customer['phone']}\" {$selected}>{$customer['name']}</option>";
                                        }
                                        ?>
                                    </select>
                                    <div class="form-text">Quick access to frequently used customers</div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="customer_address" class="form-label">Address</label>
                            <textarea class="form-control" id="customer_address" name="customer_address" rows="2"><?php echo htmlspecialchars($quotation['customer_address'] ?? ''); ?></textarea>
                        </div>
                        </div>
                    </div>

                    <!-- Step 2: Product Selection -->
                    <div class="form-step" id="step2">
                        <div class="items-section">
                            <h5 class="mb-3"><i class="bi bi-box"></i> Edit Products</h5>

                            <!-- Product Add Section -->
                            <div class="product-add-section">
                                <div class="product-add-buttons">
                                    <button type="button" class="btn btn-primary" onclick="showProductSearch()">
                                        <i class="fas fa-search"></i> Search Products
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="showBarcodeScanner()">
                                        <i class="fas fa-barcode"></i> Scan Barcode
                                    </button>
                                </div>
                            </div>

                            <!-- Quotation Items -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5><i class="bi bi-list-ul"></i> Quotation Items</h5>
                            </div>

                         <!-- Product Search Modal -->
                         <div class="modal fade" id="productSearchModal" tabindex="-1">
                             <div class="modal-dialog modal-lg">
                                 <div class="modal-content">
                                     <div class="modal-header">
                                         <h5 class="modal-title">Search Products</h5>
                                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                     </div>
                                     <div class="modal-body">
                                         <div class="row mb-3">
                                             <div class="col-md-6">
                                                 <label class="form-label">Search Type</label>
                                                 <select class="form-control" id="searchType">
                                                     <option value="all">All Fields</option>
                                                     <option value="name">Product Name</option>
                                                     <option value="sku">SKU</option>
                                                     <option value="barcode">Barcode</option>
                                                     <option value="category">Category</option>
                                                 </select>
                                             </div>
                                             <div class="col-md-6">
                                                 <label class="form-label">Category</label>
                                                 <select class="form-control" id="searchCategory">
                                                     <option value="">All Categories</option>
                                                 </select>
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-md-6">
                                                 <label class="form-label">Stock Status</label>
                                                 <select class="form-control" id="searchStockStatus">
                                                     <option value="all">All Products</option>
                                                     <option value="in_stock">In Stock</option>
                                                     <option value="low_stock">Low Stock</option>
                                                     <option value="out_of_stock">Out of Stock</option>
                                                 </select>
                                             </div>
                                             <div class="col-md-6">
                                                 <label class="form-label">Search Term</label>
                                                 <input type="text" class="form-control" id="searchTerm" placeholder="Enter search term...">
                                             </div>
                                         </div>
                                         <div id="searchResults" class="search-results-container">
                                             <!-- Search results will be loaded here -->
                                         </div>
                                     </div>
                                 </div>
                             </div>
                         </div>

                         <!-- Barcode Scanner Modal -->
                         <div class="modal fade" id="barcodeScannerModal" tabindex="-1">
                             <div class="modal-dialog">
                                 <div class="modal-content">
                                     <div class="modal-header">
                                         <h5 class="modal-title">Scan Barcode</h5>
                                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                     </div>
                                     <div class="modal-body">
                                         <div class="mb-3">
                                             <label class="form-label">Barcode</label>
                                             <input type="text" class="form-control" id="barcodeInput" placeholder="Enter or scan barcode..." autofocus>
                                         </div>
                                         <div id="barcodeResults" class="search-results-container">
                                             <!-- Barcode scan results will be loaded here -->
                                         </div>
                                     </div>
                                 </div>
                             </div>
                         </div>

                            <div id="itemsContainer">
                                <!-- Existing items will be loaded here -->
                                <div class="text-center py-4">
                                    <p class="text-muted">Loading existing quotation items...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Review & Save -->
                    <div class="form-step" id="step3">
                        <div class="totals-section">
                            <h5 class="mb-3"><i class="bi bi-calculator"></i> Quotation Summary</h5>
                            <div class="totals-grid">
                                <div>
                                    <label class="form-label">Subtotal</label>
                                    <input type="number" class="form-control total-input" id="subtotal" value="<?php echo number_format($quotation['subtotal'], 2, '.', ''); ?>" readonly>
                                </div>
                                <div>
                                    <label class="form-label">Tax Amount</label>
                                    <input type="number" class="form-control total-input" id="tax_amount" value="<?php echo number_format($quotation['tax_amount'], 2, '.', ''); ?>" readonly>
                                </div>
                                <div>
                                    <label class="form-label">Final Total</label>
                                    <input type="number" class="form-control total-input fw-bold" id="final_amount" value="<?php echo number_format($quotation['final_amount'], 2, '.', ''); ?>" readonly>
                                </div>
                            </div>
                            <div id="taxBreakdown" class="mt-3" style="display: none;">
                                <h6>Tax Breakdown</h6>
                                <div id="taxDetails" class="tax-details"></div>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes for the quotation"><?php echo htmlspecialchars($quotation['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="terms" class="form-label">Terms & Conditions</label>
                                    <textarea class="form-control" id="terms" name="terms" rows="3" placeholder="Terms and conditions for this quotation"><?php echo htmlspecialchars($quotation['terms'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="valid_until" class="form-label">Valid Until</label>
                                    <input type="date" class="form-control" id="valid_until" name="valid_until"
                                           value="<?php echo date('Y-m-d', strtotime($quotation['valid_until'])); ?>">
                                    <div class="form-text">Date until which this quotation is valid</div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="text-center mt-4">
                            <button type="button" class="btn btn-save me-2" onclick="updateQuotation('<?php echo $quotation['quotation_status']; ?>')">
                                <i class="bi bi-save"></i> Save Changes
                            </button>
                            <a href="quotation.php?quotation_id=<?php echo $quotation_id; ?>" class="btn btn-secondary ms-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </div>
                </form>

                <!-- Step Navigation -->
                <div class="step-navigation">
                    <button type="button" class="btn btn-outline-secondary btn-step" id="prevBtn" onclick="changeStep(-1)" disabled>
                        <i class="bi bi-arrow-left"></i> Previous
                    </button>
                    <div class="step-info">
                        <span id="stepInfo">Step 1 of 3</span>
                    </div>
                    <button type="button" class="btn btn-primary btn-step" id="nextBtn" onclick="changeStep(1)">
                        Next <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let itemCounter = 1;
        let existingItems = <?php echo json_encode($quotation['items'] ?? []); ?>;

        // Product search functionality with improved error handling
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('product-search')) {
                const searchTerm = e.target.value;
                const resultsContainer = e.target.nextElementSibling.nextElementSibling;

                if (searchTerm.length < 2) {
                    resultsContainer.style.display = 'none';
                    return;
                }

                // Clear previous timeout
                if (window.productSearchTimeout) {
                    clearTimeout(window.productSearchTimeout);
                }

                // Debounce search requests to avoid overwhelming the server
                window.productSearchTimeout = setTimeout(() => {
                    performProductSearch(searchTerm, resultsContainer);
                }, 500);
            }
        });

        // Enhanced product search with timeout and error handling
        function performProductSearch(searchTerm, resultsContainer) {
            // Create AbortController for timeout handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout

            resultsContainer.innerHTML = '<div class="text-center text-muted"><small>Searching...</small></div>';
            resultsContainer.style.display = 'block';

            fetch(`../api/search_products.php?q=${encodeURIComponent(searchTerm)}`, {
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                resultsContainer.innerHTML = '';

                if (data.success && data.products && data.products.length > 0) {
                    data.products.forEach(product => {
                        const item = document.createElement('div');
                        item.className = 'product-search-item';
                        item.innerHTML = `
                            <div class="fw-bold">${product.name}</div>
                            <small class="text-muted">SKU: ${product.sku || 'N/A'} | Stock: ${product.stock_quantity || 0} | Price: KES ${product.selling_price || 0}</small>
                        `;
                        item.onclick = function() {
                            selectProduct(e.target.closest('.item-row').querySelector('.product-search'), product);
                        };
                        resultsContainer.appendChild(item);
                    });
                    resultsContainer.style.display = 'block';
                } else {
                    resultsContainer.innerHTML = '<div class="text-center text-muted"><small>No products found</small></div>';
                    resultsContainer.style.display = 'block';
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                // Silent error handling - no console logging

                // Show user-friendly error message
                resultsContainer.innerHTML = '<div class="text-center text-warning"><small>Search temporarily unavailable</small></div>';
                resultsContainer.style.display = 'block';

                // Hide error after 3 seconds
                setTimeout(() => {
                    if (resultsContainer.innerHTML.includes('temporarily unavailable')) {
                        resultsContainer.style.display = 'none';
                    }
                }, 3000);
            });
        }

         function selectProduct(input, product) {
             const itemRow = input.closest('.item-row');

             // Check for duplicate products
             if (isProductAlreadyAdded(product.id)) {
                 const existingRow = findExistingProductRow(product.id);
                 const currentQuantity = existingRow ? parseFloat(existingRow.querySelector('.quantity').value) || 0 : 0;

                 // Highlight the existing product row
                 if (existingRow) {
                     existingRow.classList.add('highlight-duplicate');
                     setTimeout(() => {
                         existingRow.classList.remove('highlight-duplicate');
                     }, 2000);
                 }

                 if (confirm(`This product has already been added to the quotation (Current quantity: ${currentQuantity}). Would you like to increase the quantity by 1 instead?`)) {
                     // Increase quantity of existing product
                     const quantityInput = existingRow.querySelector('.quantity');
                     quantityInput.value = currentQuantity + 1;
                     calculateItemTotal(existingRow);
                     calculateTotals();
                 }

                 input.value = '';
                 input.nextElementSibling.nextElementSibling.style.display = 'none';
                 return;
             }

             input.value = product.name;
             itemRow.querySelector('.product-id').value = product.id;

             // Add product-selected class for grid layout
             itemRow.classList.add('product-selected');

             // Show quantity and other fields after product selection
             itemRow.querySelector('.quantity-section').style.display = 'block';
             itemRow.querySelector('.unit-price-section').style.display = 'block';
             itemRow.querySelector('.total-section').style.display = 'block';
             itemRow.querySelector('.description-section').style.display = 'block';
             itemRow.querySelector('.remove-section').style.display = 'block';

             // Set unit price
             const unitPriceInput = itemRow.querySelector('.unit-price');
             unitPriceInput.value = product.selling_price || product.price || 0;

             // Set quantity to 1 if empty
             const quantityInput = itemRow.querySelector('.quantity');
             if (!quantityInput.value) {
                 quantityInput.value = 1;
             }

             // Calculate total
             calculateItemTotal(itemRow);

             // Hide search results
             input.nextElementSibling.nextElementSibling.style.display = 'none';
         }

        // Check if product is already added to quotation
        function isProductAlreadyAdded(productId) {
            const existingProductIds = [];
            document.querySelectorAll('.item-row').forEach(row => {
                const productIdInput = row.querySelector('.product-id');
                if (productIdInput && productIdInput.value) {
                    existingProductIds.push(productIdInput.value);
                }
            });
            return existingProductIds.includes(productId.toString());
        }

        // Find existing product row and suggest quantity increase
        function findExistingProductRow(productId) {
            let existingRow = null;
            document.querySelectorAll('.item-row').forEach(row => {
                const productIdInput = row.querySelector('.product-id');
                if (productIdInput && productIdInput.value === productId.toString()) {
                    existingRow = row;
                }
            });
            return existingRow;
        }

        function removeItem(button) {
            const itemRow = button.closest('.item-row');
            if (document.querySelectorAll('.item-row').length > 1) {
                itemRow.remove();
                calculateTotals();
            }
        }

         // Calculate item total
         document.addEventListener('input', function(e) {
             if (e.target.classList.contains('quantity') || e.target.classList.contains('unit-price')) {
                 calculateItemTotal(e.target.closest('.item-row'));
             }
         });

        function calculateItemTotal(itemRow) {
            const quantity = parseFloat(itemRow.querySelector('.quantity').value) || 0;
            const unitPrice = parseFloat(itemRow.querySelector('.unit-price').value) || 0;
            const total = quantity * unitPrice;

            itemRow.querySelector('.total-price').value = total.toFixed(2);

            // Validate quantity
            if (quantity <= 0) {
                itemRow.querySelector('.quantity').classList.add('is-invalid');
                itemRow.querySelector('.quantity').setCustomValidity('Quantity must be greater than 0');
            } else {
                itemRow.querySelector('.quantity').classList.remove('is-invalid');
                itemRow.querySelector('.quantity').setCustomValidity('');
            }

            calculateTotals();
        }

         function calculateTotals() {
             let subtotal = 0;
             document.querySelectorAll('.total-price').forEach(input => {
                 subtotal += parseFloat(input.value) || 0;
             });

             document.getElementById('subtotal').value = subtotal.toFixed(2);

             // Calculate taxes using TaxManager
             calculateTaxes(subtotal);
         }

        // Calculate taxes using TaxManager with improved error handling
        function calculateTaxes(subtotal) {
            const items = getQuotationItems();
            const customerId = document.getElementById('customer_id').value || null;

            if (items.length === 0) {
                document.getElementById('tax_amount').value = '0.00';
                document.getElementById('final_amount').value = subtotal.toFixed(2);
                document.getElementById('taxBreakdown').style.display = 'none';
                return;
            }

            // Prepare items for tax calculation
            const taxItems = items.map(item => ({
                product_id: item.product_id,
                quantity: item.quantity,
                unit_price: item.unit_price
            }));

            // Create AbortController for timeout handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 15000); // 15 second timeout for tax calculation

            // Call tax calculation API with better error handling
            fetch('../api/calculate_quotation_taxes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                },
                body: JSON.stringify({
                    items: taxItems,
                    customer_id: customerId,
                    subtotal: subtotal
                }),
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const taxAmount = parseFloat(data.total_tax_amount || 0);
                    const finalTotal = subtotal + taxAmount;

                    document.getElementById('tax_amount').value = taxAmount.toFixed(2);
                    document.getElementById('final_amount').value = finalTotal.toFixed(2);

                    // Display tax breakdown
                    displayTaxBreakdown(data.taxes);
                } else {
                    // Fallback to zero tax if calculation fails
                    document.getElementById('tax_amount').value = '0.00';
                    document.getElementById('final_amount').value = subtotal.toFixed(2);
                    document.getElementById('taxBreakdown').style.display = 'none';
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                // Show temporary user notification
                showTaxCalculationNotification('Tax calculation temporarily unavailable - using 0% tax rate');

                // Fallback to zero tax
                document.getElementById('tax_amount').value = '0.00';
                document.getElementById('final_amount').value = subtotal.toFixed(2);
                document.getElementById('taxBreakdown').style.display = 'none';
            });
        }

        // Helper function to show tax calculation notifications
        function showTaxCalculationNotification(message) {
            // Remove any existing notification
            const existingNotification = document.querySelector('.tax-notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            // Create new notification
            const notification = document.createElement('div');
            notification.className = 'alert alert-warning alert-dismissible fade show tax-notification';
            notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 400px;';
            notification.innerHTML = `
                <small><i class="bi bi-exclamation-triangle me-1"></i>${message}</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;

            document.body.appendChild(notification);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }

        // Display tax breakdown
        function displayTaxBreakdown(taxes) {
            const container = document.getElementById('taxDetails');
            const breakdown = document.getElementById('taxBreakdown');

            if (!taxes || taxes.length === 0) {
                breakdown.style.display = 'none';
                return;
            }

            container.innerHTML = taxes.map(tax => `
                <div class="tax-item">
                    <span class="tax-name">${tax.tax_name} (${(tax.tax_rate * 100).toFixed(2)}%)</span>
                    <span class="tax-amount">KES ${parseFloat(tax.tax_amount).toFixed(2)}</span>
                </div>
            `).join('');

            breakdown.style.display = 'block';
        }

        // Get quotation items for tax calculation
        function getQuotationItems() {
            const items = [];
            document.querySelectorAll('.item-row').forEach(row => {
                const productId = row.querySelector('.product-id').value;
                const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
                const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;

                // Only include items with valid product ID, quantity, and unit price
                if (productId && quantity > 0 && unitPrice > 0) {
                    items.push({
                        product_id: parseInt(productId),
                        quantity: quantity,
                        unit_price: unitPrice
                    });
                }
            });
            return items;
        }

        // Handle existing customer selection
        document.getElementById('customer_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                document.getElementById('customer_name').value = selectedOption.text;
                document.getElementById('customer_email').value = selectedOption.getAttribute('data-email') || '';
                document.getElementById('customer_phone').value = selectedOption.getAttribute('data-phone') || '';
            }
        });

        // Enhanced Customer search functionality with debouncing
        let searchTimeout;
        let isSearching = false;

        document.getElementById('customer_search').addEventListener('input', function() {
            const query = this.value.trim();
            const resultsContainer = document.getElementById('customer_search_results');

            // Clear previous timeout
            clearTimeout(searchTimeout);

            if (query.length < 2) {
                resultsContainer.style.display = 'none';
                return;
            }

            // Show loading state
            showSearchLoading();

            // Debounce search to avoid too many API calls
            searchTimeout = setTimeout(() => {
                if (!isSearching) {
                    searchCustomers(query);
                }
            }, 500);
        });

        function showSearchLoading() {
            const resultsContainer = document.getElementById('customer_search_results');
            resultsContainer.innerHTML = `
                <div class="search-loading">
                    <i class="bi bi-arrow-clockwise"></i>
                    <div>Searching customers...</div>
                </div>
            `;
            resultsContainer.style.display = 'block';
        }

        function searchCustomers(query) {
            isSearching = true;

            // Create AbortController for timeout handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout

            fetch('../api/search_customers_loyalty.php?search=' + encodeURIComponent(query), {
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.customers && data.customers.length > 0) {
                        displayCustomerResults(data.customers);
                    } else {
                        showNoCustomersFound();
                    }
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    showSearchError();
                })
                .finally(() => {
                    isSearching = false;
                });
        }

        function showNoCustomersFound() {
            const resultsContainer = document.getElementById('customer_search_results');
            resultsContainer.innerHTML = `
                <div class="no-customers">
                    <i class="bi bi-person-x"></i>
                    <div>No customers found</div>
                    <small>Try a different search term</small>
                </div>
            `;
            resultsContainer.style.display = 'block';
        }

        function showSearchError() {
            const resultsContainer = document.getElementById('customer_search_results');
            resultsContainer.innerHTML = `
                <div class="no-customers">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>Search failed</div>
                    <small>Please try again</small>
                </div>
            `;
            resultsContainer.style.display = 'block';
        }

        function displayCustomerResults(customers) {
            const resultsContainer = document.getElementById('customer_search_results');
            resultsContainer.innerHTML = '';

            customers.forEach(customer => {
                const item = document.createElement('div');
                item.className = 'customer-search-item';

                // Generate customer avatar initials
                const initials = getCustomerInitials(customer.name);

                // Format customer details
                const email = customer.email || '';
                const phone = customer.phone || '';
                const loyaltyPoints = customer.loyalty_points || 0;

                item.innerHTML = `
                    <div class="customer-avatar">${initials}</div>
                    <div class="customer-info">
                        <div class="customer-name">${customer.name}</div>
                        <div class="customer-details">
                            ${email ? `<div class="customer-detail-item"><i class="bi bi-envelope"></i> ${email}</div>` : ''}
                            ${phone ? `<div class="customer-detail-item"><i class="bi bi-telephone"></i> ${phone}</div>` : ''}
                            ${loyaltyPoints > 0 ? `<div class="customer-loyalty"><i class="bi bi-star-fill"></i> ${loyaltyPoints} pts</div>` : ''}
                        </div>
                    </div>
                `;

                item.addEventListener('click', function() {
                    selectCustomer(customer);
                });

                resultsContainer.appendChild(item);
            });

            resultsContainer.style.display = 'block';
        }

        function getCustomerInitials(name) {
            return name
                .split(' ')
                .map(word => word.charAt(0))
                .join('')
                .toUpperCase()
                .substring(0, 2);
        }

        function selectCustomer(customer) {
            document.getElementById('customer_name').value = customer.name;
            document.getElementById('customer_email').value = customer.email || '';
            document.getElementById('customer_phone').value = customer.phone || '';
            document.getElementById('customer_id').value = customer.id;
            document.getElementById('customer_search').value = '';
            document.getElementById('customer_search_results').style.display = 'none';
        }

        // Enhanced keyboard navigation and click outside handling
        let selectedCustomerIndex = -1;

        document.getElementById('customer_search').addEventListener('keydown', function(e) {
            const resultsContainer = document.getElementById('customer_search_results');
            const customerItems = resultsContainer.querySelectorAll('.customer-search-item');

            if (resultsContainer.style.display === 'none' || customerItems.length === 0) return;

            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    selectedCustomerIndex = Math.min(selectedCustomerIndex + 1, customerItems.length - 1);
                    updateSelectedCustomer(customerItems);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    selectedCustomerIndex = Math.max(selectedCustomerIndex - 1, -1);
                    updateSelectedCustomer(customerItems);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (selectedCustomerIndex >= 0 && customerItems[selectedCustomerIndex]) {
                        customerItems[selectedCustomerIndex].click();
                    }
                    break;
                case 'Escape':
                    resultsContainer.style.display = 'none';
                    selectedCustomerIndex = -1;
                    break;
            }
        });

        function updateSelectedCustomer(customerItems) {
            customerItems.forEach((item, index) => {
                item.classList.toggle('selected', index === selectedCustomerIndex);
            });
        }

        // Hide search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#customer_search') && !e.target.closest('#customer_search_results')) {
                document.getElementById('customer_search_results').style.display = 'none';
                selectedCustomerIndex = -1;
            }
        });

        function updateQuotation(status) {
            // Custom validation instead of form.checkValidity() to avoid hidden input issues
            const customerName = document.getElementById('customer_name').value.trim();
            if (!customerName) {
                alert('Please enter customer name');
                document.getElementById('customer_name').focus();
                return;
            }

            // Check for invalid quantities
            let hasInvalidQuantity = false;
            document.querySelectorAll('.quantity').forEach(quantityInput => {
                const quantity = parseFloat(quantityInput.value);
                if (quantity <= 0) {
                    quantityInput.classList.add('is-invalid');
                    hasInvalidQuantity = true;
                } else {
                    quantityInput.classList.remove('is-invalid');
                }
            });

            if (hasInvalidQuantity) {
                alert('Please ensure all items have a quantity greater than 0');
                return;
            }

             const items = [];
             document.querySelectorAll('.item-row').forEach(row => {
                 const productName = row.querySelector('input[readonly]').value; // Get product name from readonly input
                 const productId = row.querySelector('.product-id').value;
                 const quantity = row.querySelector('.quantity').value;
                 const unitPrice = row.querySelector('.unit-price').value;
                 const totalPrice = row.querySelector('.total-price').value;
                 const description = row.querySelector('.description').value;

                 // Only include items that have a valid product ID (from inventory)
                 if (quantity && unitPrice && productId) {
                     items.push({
                         product_id: parseInt(productId),
                         product_name: productName,
                         product_sku: '',
                         quantity: parseFloat(quantity),
                         unit_price: parseFloat(unitPrice),
                         total_price: parseFloat(totalPrice),
                         description: description
                     });
                 }
             });

             // Validate that no items have 0 quantity
             const invalidItems = items.filter(item => parseFloat(item.quantity) <= 0);
             if (invalidItems.length > 0) {
                 alert('Please ensure all items have a quantity greater than 0. Remove items with 0 quantity or enter a valid quantity.');
                 return;
             }

             // Validate that all items have valid product IDs from inventory
             if (items.length === 0) {
                 alert('Please add at least one product from your inventory to the quotation.');
                 return;
             }

             // Check that all items have valid product IDs
             const itemsWithoutProductId = items.filter(item => !item.product_id);
             if (itemsWithoutProductId.length > 0) {
                 alert('All quotation items must be selected from your inventory. Please remove any custom items and select products from your inventory.');
                 return;
             }

             const quotationData = {
                 customer_id: document.getElementById('customer_id').value || null,
                 customer_name: document.getElementById('customer_name').value,
                 customer_email: document.getElementById('customer_email').value,
                 customer_phone: document.getElementById('customer_phone').value,
                 customer_address: document.getElementById('customer_address').value,
                 subtotal: parseFloat(document.getElementById('subtotal').value),
                 tax_amount: parseFloat(document.getElementById('tax_amount').value),
                 final_amount: parseFloat(document.getElementById('final_amount').value),
                 quotation_status: status,
                 notes: document.getElementById('notes').value,
                 terms: document.getElementById('terms').value,
                 valid_until: document.getElementById('valid_until').value,
                 items: items
             };

            // Update quotation with improved error handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout for update operation

            fetch('quotation_update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                },
                body: JSON.stringify({
                    quotation_id: <?php echo $quotation_id; ?>,
                    quotationData: quotationData
                }),
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert(`Quotation updated successfully!`);
                    window.location.href = `quotation.php?quotation_id=<?php echo $quotation_id; ?>`;
                } else {
                    alert('Error: ' + (data.error || 'Failed to update quotation'));
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                let errorMessage = 'Network error. Please try again.';
                if (error.name === 'AbortError') {
                    errorMessage = 'Request timed out. Please check your connection and try again.';
                } else if (error.message.includes('Failed to fetch')) {
                    errorMessage = 'Unable to connect to server. Please check your internet connection.';
                }

                alert(errorMessage);
            });
        }

         // Multi-step form management
         let currentStep = 1;
         const totalSteps = 3;

         function changeStep(direction) {
             const newStep = currentStep + direction;

             if (newStep < 1 || newStep > totalSteps) return;

             // Validate current step before proceeding
             if (direction > 0 && !validateCurrentStep()) {
                 return;
             }

             // Hide current step
             document.getElementById(`step${currentStep}`).classList.remove('active');
             document.querySelector(`[data-step="${currentStep}"]`).classList.remove('active');

             // Show new step
             currentStep = newStep;
             document.getElementById(`step${currentStep}`).classList.add('active');
             document.querySelector(`[data-step="${currentStep}"]`).classList.add('active');

             // Update step indicators
             updateStepIndicators();
             updateNavigationButtons();
             updateStepInfo();
         }

         function validateCurrentStep() {
             switch (currentStep) {
                 case 1:
                     // Validate customer information
                     const customerName = document.getElementById('customer_name').value.trim();
                     if (!customerName) {
                         alert('Please enter a customer name.');
                         document.getElementById('customer_name').focus();
                         return false;
                     }
                     return true;

                 case 2:
                     // Validate products
                     const items = document.querySelectorAll('.item-row');
                     if (items.length === 0) {
                         alert('Please add at least one product to the quotation.');
                         return false;
                     }
                     return true;

                 case 3:
                     // Final step - no validation needed
                     return true;

                 default:
                     return true;
             }
         }

         function updateStepIndicators() {
             document.querySelectorAll('.step').forEach((step, index) => {
                 const stepNumber = index + 1;
                 step.classList.remove('active', 'completed');

                 if (stepNumber < currentStep) {
                     step.classList.add('completed');
                 } else if (stepNumber === currentStep) {
                     step.classList.add('active');
                 }
             });
         }

         function updateNavigationButtons() {
             const prevBtn = document.getElementById('prevBtn');
             const nextBtn = document.getElementById('nextBtn');

             // Update Previous button
             prevBtn.disabled = currentStep === 1;

             // Update Next button
             if (currentStep === totalSteps) {
                 nextBtn.style.display = 'none';
             } else {
                 nextBtn.style.display = 'inline-block';
             }
         }

         function updateStepInfo() {
             document.getElementById('stepInfo').textContent = `Step ${currentStep} of ${totalSteps}`;
         }

        // Global error handler for unhandled promise rejections
        window.addEventListener('unhandledrejection', function(event) {
            // Prevent the default browser behavior (logging to console)
            // but don't show alerts to avoid annoying users with technical details
            event.preventDefault();
        });

        // Global error handler for JavaScript errors
        window.addEventListener('error', function(event) {
            // Handle extension-related errors gracefully
            if (event.message && event.message.includes('Extension context invalidated')) {
                event.preventDefault();
                return false;
            }

            if (event.message && event.message.includes('message channel closed')) {
                event.preventDefault();
                return false;
            }
        });

        // Load existing items on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadExistingItems();
            calculateTotals();
            updateStepIndicators();
            updateNavigationButtons();
            updateStepInfo();
        });

        // Load existing quotation items
        function loadExistingItems() {
            const container = document.getElementById('itemsContainer');

            if (existingItems.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-4">
                        <p class="text-muted">No items in this quotation. Add products using the buttons above.</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = '';

            existingItems.forEach(item => {
                const newItem = document.createElement('div');
                newItem.className = 'item-row product-selected';
                newItem.innerHTML = `
                    <div>
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control item-input" value="${item.product_name}" readonly>
                        <input type="hidden" class="product-id" name="product_id[]" value="${item.product_id}">
                    </div>
                    <div>
                        <label class="form-label">Quantity *</label>
                        <input type="number" class="form-control item-input quantity" name="quantity[]" min="0.01" step="0.01" value="${item.quantity}" required>
                        <div class="invalid-feedback">Quantity must be greater than 0</div>
                    </div>
                    <div>
                        <label class="form-label">Unit Price</label>
                        <input type="number" class="form-control item-input unit-price" name="unit_price[]" min="0" step="0.01" value="${item.unit_price}" readonly>
                    </div>
                    <div>
                        <label class="form-label">Total</label>
                        <input type="number" class="form-control item-input total-price" value="${item.total_price}" readonly>
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control item-input description" name="description[]" value="${item.description || ''}" placeholder="Optional description">
                    </div>
                    <div>
                        <button type="button" class="btn btn-remove-item" onclick="removeItem(this)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
                container.appendChild(newItem);
            });
        }

        // Load search filters (categories, etc.) - optimized for performance
        function loadSearchFilters() {
            // Only load filters when the product search modal is opened
            // This prevents unnecessary API calls on page load
            if (document.getElementById('searchCategory').children.length > 1) {
                return; // Already loaded
            }

            // Create AbortController for timeout handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 8000); // 8 second timeout for filters

            fetch('../api/get_search_filters.php', {
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.categories) {
                        // Populate category dropdown efficiently
                        const searchCategory = document.getElementById('searchCategory');
                        const fragment = document.createDocumentFragment();

                        data.categories.forEach(category => {
                            const option = document.createElement('option');
                            option.value = category.id;
                            option.textContent = category.name;
                            fragment.appendChild(option);
                        });

                        searchCategory.appendChild(fragment);
                    }
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    // Silent error handling - no console logging
                    // Don't show error to user as this is not critical
                });
        }

         // Show product search modal
         function showProductSearch() {
             const modal = new bootstrap.Modal(document.getElementById('productSearchModal'));
             modal.show();

             // Load filters when modal is opened (lazy loading)
             loadSearchFilters();

             // Clear previous results
             document.getElementById('searchResults').innerHTML = '';

             // Add event listeners
             document.getElementById('searchTerm').addEventListener('input', performProductSearch);
             document.getElementById('searchType').addEventListener('change', performProductSearch);
             document.getElementById('searchCategory').addEventListener('change', performProductSearch);
             document.getElementById('searchStockStatus').addEventListener('change', performProductSearch);
         }

         // Show barcode scanner modal
         function showBarcodeScanner() {
             const modal = new bootstrap.Modal(document.getElementById('barcodeScannerModal'));
             modal.show();

             // Clear previous results
             document.getElementById('barcodeResults').innerHTML = '';

             // Focus on barcode input
             setTimeout(() => {
                 document.getElementById('barcodeInput').focus();
             }, 500);

             // Add event listener for barcode input
             document.getElementById('barcodeInput').addEventListener('input', function() {
                 const barcode = this.value.trim();
                 if (barcode.length >= 3) {
                     scanBarcode(barcode);
                 }
             });
         }

         // Perform product search
         function performProductSearch() {
             const searchTerm = document.getElementById('searchTerm').value.trim();
             const searchType = document.getElementById('searchType').value;
             const categoryId = document.getElementById('searchCategory').value;
             const stockStatus = document.getElementById('searchStockStatus').value;

             if (searchTerm.length < 1 && searchType !== 'all') {
                 document.getElementById('searchResults').innerHTML = '';
                 return;
             }

             const params = new URLSearchParams({
                 q: searchTerm,
                 type: searchType,
                 category_id: categoryId,
                 stock_status: stockStatus,
                 limit: 20
             });

            // Create AbortController for timeout handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 12000); // 12 second timeout for enhanced search

            fetch('../api/enhanced_product_search.php?' + params, {
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        displaySearchResults(data.products);
                    } else {
                        document.getElementById('searchResults').innerHTML =
                            '<div class="p-3 text-center text-muted">Error: ' + (data.error || 'Search failed') + '</div>';
                    }
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    // Silent error handling - no console logging
                    let errorMessage = 'Search failed. Please try again.';
                    if (error.name === 'AbortError') {
                        errorMessage = 'Search timed out. Please try again.';
                    }
                    document.getElementById('searchResults').innerHTML =
                        '<div class="p-3 text-center text-muted">' + errorMessage + '</div>';
                });
         }

         // Display search results
         function displaySearchResults(products) {
             const container = document.getElementById('searchResults');

             if (products.length === 0) {
                 container.innerHTML = '<div class="p-3 text-center text-muted">No products found</div>';
                 return;
             }

             container.innerHTML = products.map(product => `
                 <div class="search-result-item" onclick="selectProductFromSearch(${JSON.stringify(product).replace(/"/g, '&quot;')})">
                     <div class="product-name">${product.name}</div>
                     <div class="product-details">
                         ${[product.sku ? 'SKU: ' + product.sku : '',
                            product.barcode ? 'Barcode: ' + product.barcode : '',
                            product.category_name ? 'Category: ' + product.category_name : '',
                            'Stock: ' + product.quantity].filter(Boolean).join(' • ')}
                     </div>
                     <div class="d-flex justify-content-between align-items-center">
                         <span class="product-price">KES ${product.price.toFixed(2)}</span>
                         <span class="stock-status ${product.stock_status}">${product.stock_status.replace('_', ' ').toUpperCase()}</span>
                     </div>
                 </div>
             `).join('');
         }

         // Select product from search results
         function selectProductFromSearch(product) {
             addProductToQuotation(product);
             bootstrap.Modal.getInstance(document.getElementById('productSearchModal')).hide();
         }

         // Scan barcode
         function scanBarcode(barcode) {
            // Create AbortController for timeout handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 8000); // 8 second timeout for barcode scan

            fetch('../api/scan_barcode.php?barcode=' + encodeURIComponent(barcode), {
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.exact_match) {
                            // Single product found, add it directly
                            addProductToQuotation(data.product);
                            bootstrap.Modal.getInstance(document.getElementById('barcodeScannerModal')).hide();
                        } else {
                            // Multiple products found, show selection
                            displayBarcodeResults(data.products);
                        }
                    } else {
                        document.getElementById('barcodeResults').innerHTML =
                            '<div class="p-3 text-center text-muted">' + (data.error || 'Scan failed') + '</div>';
                    }
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    // Silent error handling - no console logging
                    let errorMessage = 'Scan failed. Please try again.';
                    if (error.name === 'AbortError') {
                        errorMessage = 'Scan timed out. Please try again.';
                    }
                    document.getElementById('barcodeResults').innerHTML =
                        '<div class="p-3 text-center text-muted">' + errorMessage + '</div>';
                });
         }

         // Display barcode scan results
         function displayBarcodeResults(products) {
             const container = document.getElementById('barcodeResults');

             container.innerHTML = products.map(product => `
                 <div class="search-result-item" onclick="selectProductFromBarcode(${JSON.stringify(product).replace(/"/g, '&quot;')})">
                     <div class="product-name">${product.name}</div>
                     <div class="product-details">
                         ${[product.sku ? 'SKU: ' + product.sku : '',
                            'Barcode: ' + product.barcode,
                            'Stock: ' + product.quantity].filter(Boolean).join(' • ')}
                     </div>
                     <div class="d-flex justify-content-between align-items-center">
                         <span class="product-price">KES ${product.price.toFixed(2)}</span>
                         <span class="stock-status ${product.stock_status || 'in_stock'}">${(product.stock_status || 'in_stock').replace('_', ' ').toUpperCase()}</span>
                     </div>
                 </div>
             `).join('');
         }

         // Select product from barcode results
         function selectProductFromBarcode(product) {
             addProductToQuotation(product);
             bootstrap.Modal.getInstance(document.getElementById('barcodeScannerModal')).hide();
         }

         // Add product to quotation
         function addProductToQuotation(product) {
             // Check for duplicate products
             if (isProductAlreadyAdded(product.id)) {
                 const existingRow = findExistingProductRow(product.id);
                 const currentQuantity = existingRow ? parseFloat(existingRow.querySelector('.quantity').value) || 0 : 0;

                 // Highlight the existing product row
                 if (existingRow) {
                     existingRow.classList.add('highlight-duplicate');
                     setTimeout(() => {
                         existingRow.classList.remove('highlight-duplicate');
                     }, 2000);
                 }

                 if (confirm(`This product has already been added to the quotation (Current quantity: ${currentQuantity}). Would you like to increase the quantity by 1 instead?`)) {
                     // Increase quantity of existing product
                     const quantityInput = existingRow.querySelector('.quantity');
                     quantityInput.value = currentQuantity + 1;
                     calculateItemTotal(existingRow);
                     calculateTotals();
                 }
                 return;
             }

             const container = document.getElementById('itemsContainer');

             // Clear the placeholder message if it exists
             const placeholderMessage = container.querySelector('.text-center');
             if (placeholderMessage) {
                 placeholderMessage.remove();
             }

             const newItem = document.createElement('div');
             newItem.className = 'item-row';
             newItem.innerHTML = `
                 <div>
                     <label class="form-label">Product</label>
                     <input type="text" class="form-control item-input" value="${product.name}" readonly>
                     <input type="hidden" class="product-id" name="product_id[]" value="${product.id}">
                 </div>
                 <div>
                     <label class="form-label">Quantity *</label>
                     <input type="number" class="form-control item-input quantity" name="quantity[]" min="0.01" step="0.01" value="1" required>
                     <div class="invalid-feedback">Quantity must be greater than 0</div>
                 </div>
                 <div>
                     <label class="form-label">Unit Price</label>
                     <input type="number" class="form-control item-input unit-price" name="unit_price[]" min="0" step="0.01" value="${product.price}" readonly>
                 </div>
                 <div>
                     <label class="form-label">Total</label>
                     <input type="number" class="form-control item-input total-price" value="${product.price}" readonly>
                 </div>
                 <div>
                     <label class="form-label">Description</label>
                     <input type="text" class="form-control item-input description" name="description[]" placeholder="Optional description">
                 </div>
                 <div>
                     <button type="button" class="btn btn-remove-item" onclick="removeItem(this)">
                         <i class="bi bi-trash"></i>
                     </button>
                 </div>
             `;
             container.appendChild(newItem);

             // Calculate totals
             calculateTotals();
         }
    </script>
</body>
</html>
