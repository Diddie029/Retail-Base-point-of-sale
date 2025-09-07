<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

// Get user permissions
$permissions = [];
if (isset($_SESSION['permissions'])) {
    $permissions = $_SESSION['permissions'];
}

// Get user info
$username = $_SESSION['username'] ?? 'User';
$role_name = $_SESSION['role_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconciliation Documentation - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --sidebar-color: #1e293b;
        }
        
        .doc-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .doc-section {
            margin-bottom: 3rem;
        }
        
        .doc-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        
        .doc-card .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid var(--primary-color);
            font-weight: 600;
        }
        
        .step-number {
            background: var(--primary-color);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .feature-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .code-block {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            margin: 1rem 0;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .info-box {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .success-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .toc {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .toc ul {
            margin-bottom: 0;
        }
        
        .toc a {
            text-decoration: none;
            color: var(--primary-color);
        }
        
        .toc a:hover {
            text-decoration: underline;
        }
        
        /* Override main-content styles for standalone page */
        .main-content {
            margin-left: 0 !important;
            padding-left: 0 !important;
            width: 100% !important;
        }
        
        /* Ensure full width content */
        .container, .container-fluid {
            max-width: 100% !important;
            padding-left: 15px;
            padding-right: 15px;
        }
    </style>
</head>
<body>
    <!-- Simple Navigation Header -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container-fluid">
            <a class="navbar-brand" href="../../dashboard/dashboard.php">
                <i class="bi bi-shop me-2"></i>POS System
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../reconciliation.php">
                    <i class="bi bi-arrow-left me-1"></i>Back to Reconciliation
                </a>
                <a class="nav-link" href="../../dashboard/dashboard.php">
                    <i class="bi bi-house me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="main-content" style="margin-left: 0; padding-left: 0;">
        <div class="doc-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1><i class="bi bi-book"></i> Account Reconciliation Documentation</h1>
                        <p class="lead mb-0">Complete guide to using the reconciliation feature in your POS system</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="../reconciliation.php" class="btn btn-light btn-lg">
                            <i class="bi bi-arrow-left"></i> Back to Reconciliation
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Table of Contents -->
            <div class="toc">
                <h4><i class="bi bi-list"></i> Table of Contents</h4>
                <ul>
                    <li><a href="#overview">1. Overview</a></li>
                    <li><a href="#getting-started">2. Getting Started</a></li>
                    <li><a href="#bank-accounts">3. Bank Account Management</a></li>
                    <li><a href="#importing-statements">4. Importing Bank Statements</a></li>
                    <li><a href="#creating-reconciliations">5. Creating Reconciliations</a></li>
                    <li><a href="#matching-transactions">6. Matching Transactions</a></li>
                    <li><a href="#resolving-discrepancies">7. Resolving Discrepancies</a></li>
                    <li><a href="#reports">8. Reports and History</a></li>
                    <li><a href="#troubleshooting">9. Troubleshooting</a></li>
                    <li><a href="#best-practices">10. Best Practices</a></li>
                </ul>
            </div>

            <!-- Overview Section -->
            <div class="doc-section" id="overview">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-info-circle"></i> 1. Overview</h3>
                    </div>
                    <div class="card-body">
                        <p>The Account Reconciliation feature allows you to match your POS system transactions with your bank statements to ensure accuracy and identify discrepancies. This process helps maintain financial integrity and provides a clear audit trail.</p>
                        
                        <h5>Key Features:</h5>
                        <ul>
                            <li><strong>Multi-Account Support:</strong> Manage multiple bank accounts and cash drawers</li>
                            <li><strong>Statement Import:</strong> Upload CSV/Excel bank statements</li>
                            <li><strong>Smart Matching:</strong> Automatic and manual transaction matching</li>
                            <li><strong>Discrepancy Tracking:</strong> Identify and resolve differences</li>
                            <li><strong>Audit Trail:</strong> Complete history of all reconciliation activities</li>
                            <li><strong>Role-Based Access:</strong> Secure permissions for different user levels</li>
                        </ul>

                        <div class="info-box">
                            <h6><i class="bi bi-lightbulb"></i> Why Reconciliation Matters</h6>
                            <p>Regular reconciliation helps you:</p>
                            <ul class="mb-0">
                                <li>Detect errors and fraud early</li>
                                <li>Ensure accurate financial reporting</li>
                                <li>Maintain compliance with accounting standards</li>
                                <li>Identify cash flow issues</li>
                                <li>Build confidence in your financial data</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Getting Started Section -->
            <div class="doc-section" id="getting-started">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-play-circle"></i> 2. Getting Started</h3>
                    </div>
                    <div class="card-body">
                        <h5>Prerequisites</h5>
                        <p>Before using the reconciliation feature, ensure you have:</p>
                        <ul>
                            <li>Admin or Finance Manager role permissions</li>
                            <li>Access to your bank statements (CSV/Excel format)</li>
                            <li>Basic understanding of your POS transaction flow</li>
                        </ul>

                        <h5>Initial Setup</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="text-center">
                                    <i class="bi bi-bank feature-icon"></i>
                                    <h6>Step 1: Add Bank Accounts</h6>
                                    <p>Create bank account records for each account you want to reconcile</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center">
                                    <i class="bi bi-upload feature-icon"></i>
                                    <h6>Step 2: Import Statements</h6>
                                    <p>Upload your bank statements in CSV or Excel format</p>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="text-center">
                                    <i class="bi bi-plus-circle feature-icon"></i>
                                    <h6>Step 3: Create Reconciliation</h6>
                                    <p>Start a new reconciliation session for a specific date range</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center">
                                    <i class="bi bi-arrow-left-right feature-icon"></i>
                                    <h6>Step 4: Match Transactions</h6>
                                    <p>Match bank transactions with POS transactions</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank Account Management Section -->
            <div class="doc-section" id="bank-accounts">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-bank"></i> 3. Bank Account Management</h3>
                    </div>
                    <div class="card-body">
                        <p>Bank accounts are the foundation of the reconciliation process. You can manage multiple accounts including checking accounts, savings accounts, cash drawers, and credit cards.</p>

                        <h5>Adding a New Bank Account</h5>
                        <ol>
                            <li>Click <strong>"Add Account"</strong> on the reconciliation dashboard</li>
                            <li>Fill in the account details:
                                <ul>
                                    <li><strong>Account Name:</strong> A descriptive name (e.g., "Main Business Checking")</li>
                                    <li><strong>Bank Name:</strong> The name of your bank</li>
                                    <li><strong>Account Number:</strong> Your account number (optional)</li>
                                    <li><strong>Account Type:</strong> Choose from Checking, Savings, Cash Drawer, or Credit Card</li>
                                    <li><strong>Opening Balance:</strong> The current balance of the account</li>
                                </ul>
                            </li>
                            <li>Click <strong>"Add Account"</strong> to save</li>
                        </ol>

                        <h5>Account Types Explained</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-credit-card text-primary"></i> Checking Account</h6>
                                        <p>Primary business checking account for daily operations</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-piggy-bank text-success"></i> Savings Account</h6>
                                        <p>Secondary account for savings or reserve funds</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-cash text-warning"></i> Cash Drawer</h6>
                                        <p>Physical cash register drawer for cash transactions</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-credit-card-2-front text-info"></i> Credit Card</h6>
                                        <p>Credit card account for tracking credit transactions</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="warning-box">
                            <h6><i class="bi bi-exclamation-triangle"></i> Important Notes</h6>
                            <ul class="mb-0">
                                <li>Account names should be descriptive and unique</li>
                                <li>Opening balance should reflect the current account balance</li>
                                <li>You can edit account details later if needed</li>
                                <li>Deleting an account will remove all associated data</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Importing Statements Section -->
            <div class="doc-section" id="importing-statements">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-upload"></i> 4. Importing Bank Statements</h3>
                    </div>
                    <div class="card-body">
                        <p>Import your bank statements to start the reconciliation process. The system supports CSV and Excel formats.</p>

                        <h5>Supported File Formats</h5>
                        <ul>
                            <li><strong>CSV Files:</strong> Comma-separated values (.csv)</li>
                            <li><strong>Excel Files:</strong> Microsoft Excel (.xlsx, .xls)</li>
                        </ul>

                        <h5>Required File Format</h5>
                        <p>Your bank statement file must have the following columns (in any order):</p>
                        <div class="code-block">
Date,Description,Amount,Balance
2024-01-15,Payment from Customer ABC,1500.00,5000.00
2024-01-16,Office Supplies Purchase,-250.00,4750.00
2024-01-17,Deposit from Sales,3200.00,7950.00
                        </div>

                        <h5>Column Requirements</h5>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Column</th>
                                    <th>Description</th>
                                    <th>Required</th>
                                    <th>Format</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Date</strong></td>
                                    <td>Transaction date</td>
                                    <td>Yes</td>
                                    <td>YYYY-MM-DD or MM/DD/YYYY</td>
                                </tr>
                                <tr>
                                    <td><strong>Description</strong></td>
                                    <td>Transaction description</td>
                                    <td>Yes</td>
                                    <td>Text</td>
                                </tr>
                                <tr>
                                    <td><strong>Amount</strong></td>
                                    <td>Transaction amount</td>
                                    <td>Yes</td>
                                    <td>Positive for credits, negative for debits</td>
                                </tr>
                                <tr>
                                    <td><strong>Balance</strong></td>
                                    <td>Account balance after transaction</td>
                                    <td>No</td>
                                    <td>Decimal number</td>
                                </tr>
                            </tbody>
                        </table>

                        <h5>Import Process</h5>
                        <ol>
                            <li>Click <strong>"Import Bank Statement"</strong> on the dashboard</li>
                            <li>Select the bank account for this statement</li>
                            <li>Choose your statement file using drag-and-drop or file browser</li>
                            <li>Review the file format requirements</li>
                            <li>Click <strong>"Import Statement"</strong></li>
                            <li>Review the import results and any errors</li>
                        </ol>

                        <div class="success-box">
                            <h6><i class="bi bi-check-circle"></i> Import Tips</h6>
                            <ul class="mb-0">
                                <li>Ensure your file has headers in the first row</li>
                                <li>Remove any empty rows before importing</li>
                                <li>Check that dates are in the correct format</li>
                                <li>Verify amounts don't have currency symbols</li>
                                <li>Save your original files for backup</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Creating Reconciliations Section -->
            <div class="doc-section" id="creating-reconciliations">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-plus-circle"></i> 5. Creating Reconciliations</h3>
                    </div>
                    <div class="card-body">
                        <p>A reconciliation session represents a specific period where you match bank transactions with POS transactions. You can create multiple reconciliations for different time periods.</p>

                        <h5>Creating a New Reconciliation</h5>
                        <ol>
                            <li>Click <strong>"Start New Reconciliation"</strong> on the dashboard</li>
                            <li>Select the bank account to reconcile</li>
                            <li>Choose the reconciliation date (usually month-end or period-end)</li>
                            <li>Enter the opening balance (current account balance)</li>
                            <li>Enter the closing balance (from bank statement)</li>
                            <li>Add optional notes about this reconciliation</li>
                            <li>Click <strong>"Create Reconciliation"</strong></li>
                        </ol>

                        <h5>Reconciliation Statuses</h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <span class="badge bg-secondary">Draft</span>
                                        <p class="mt-2">Newly created, not yet started</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <span class="badge bg-warning">In Progress</span>
                                        <p class="mt-2">Currently being reconciled</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <span class="badge bg-success">Completed</span>
                                        <p class="mt-2">Successfully reconciled</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <span class="badge bg-danger">Cancelled</span>
                                        <p class="mt-2">Cancelled or abandoned</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Balance Information</h5>
                        <ul>
                            <li><strong>Opening Balance:</strong> Account balance at the start of the period</li>
                            <li><strong>Closing Balance:</strong> Account balance at the end of the period (from bank statement)</li>
                            <li><strong>Expected Balance:</strong> Calculated balance based on POS transactions</li>
                            <li><strong>Difference:</strong> Variance between closing and expected balance</li>
                        </ul>

                        <div class="info-box">
                            <h6><i class="bi bi-lightbulb"></i> Best Practices</h6>
                            <ul class="mb-0">
                                <li>Reconcile monthly or weekly for better accuracy</li>
                                <li>Use consistent date ranges (e.g., first to last day of month)</li>
                                <li>Keep detailed notes for complex reconciliations</li>
                                <li>Complete reconciliations promptly to avoid backlogs</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Matching Transactions Section -->
            <div class="doc-section" id="matching-transactions">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-arrow-left-right"></i> 6. Matching Transactions</h3>
                    </div>
                    <div class="card-body">
                        <p>Transaction matching is the core of the reconciliation process. You'll match bank transactions with corresponding POS transactions to ensure accuracy.</p>

                        <h5>Types of Matches</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-robot feature-icon"></i>
                                        <h6>Automatic</h6>
                                        <p>System automatically matches transactions based on amount and date proximity</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-person feature-icon"></i>
                                        <h6>Manual</h6>
                                        <p>You manually select and match transactions</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-scissors feature-icon"></i>
                                        <h6>Partial</h6>
                                        <p>Match part of a transaction amount</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Manual Matching Process</h5>
                        <ol>
                            <li>Click <strong>"Match Transactions"</strong> from the reconciliation view</li>
                            <li>Review unmatched bank transactions on the left</li>
                            <li>Review unmatched POS transactions on the right</li>
                            <li>Click on a bank transaction to select it</li>
                            <li>Click on a corresponding POS transaction</li>
                            <li>Enter the match amount (usually the full amount)</li>
                            <li>Add optional notes about the match</li>
                            <li>Click <strong>"Match"</strong> to confirm</li>
                        </ol>

                        <h5>Matching Criteria</h5>
                        <p>The system considers several factors when suggesting matches:</p>
                        <ul>
                            <li><strong>Amount:</strong> Exact or close amount matches</li>
                            <li><strong>Date:</strong> Transactions within a reasonable date range</li>
                            <li><strong>Description:</strong> Similar transaction descriptions</li>
                            <li><strong>Reference Numbers:</strong> Matching reference or check numbers</li>
                        </ul>

                        <h5>Confidence Scoring</h5>
                        <p>Automatic matches receive a confidence score (0-100%) based on:</p>
                        <div class="row">
                            <div class="col-md-6">
                                <ul>
                                    <li>Amount match accuracy</li>
                                    <li>Date proximity</li>
                                    <li>Description similarity</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul>
                                    <li>Reference number match</li>
                                    <li>Transaction type consistency</li>
                                    <li>Historical patterns</li>
                                </ul>
                            </div>
                        </div>

                        <div class="warning-box">
                            <h6><i class="bi bi-exclamation-triangle"></i> Matching Guidelines</h6>
                            <ul class="mb-0">
                                <li>Always verify automatic matches before accepting</li>
                                <li>Look for exact amount matches first</li>
                                <li>Check dates are within reasonable range</li>
                                <li>Be cautious with high-value transactions</li>
                                <li>Document any unusual matches with notes</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resolving Discrepancies Section -->
            <div class="doc-section" id="resolving-discrepancies">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-exclamation-triangle"></i> 7. Resolving Discrepancies</h3>
                    </div>
                    <div class="card-body">
                        <p>Discrepancies occur when there are differences between your bank records and POS transactions. The system helps you identify and resolve these issues.</p>

                        <h5>Types of Discrepancies</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-currency-dollar text-danger"></i> Amount Mismatch</h6>
                                        <p>Bank and POS amounts don't match exactly</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-calendar-x text-warning"></i> Date Mismatch</h6>
                                        <p>Transactions have different dates</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-bank text-info"></i> Missing Bank Transaction</h6>
                                        <p>POS transaction has no corresponding bank entry</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-receipt text-success"></i> Missing POS Transaction</h6>
                                        <p>Bank transaction has no corresponding POS entry</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Discrepancy Resolution Process</h5>
                        <ol>
                            <li>Review the discrepancy details</li>
                            <li>Investigate the cause:
                                <ul>
                                    <li>Check for data entry errors</li>
                                    <li>Verify transaction dates</li>
                                    <li>Look for missing transactions</li>
                                    <li>Check for timing differences</li>
                                </ul>
                            </li>
                            <li>Take corrective action:
                                <ul>
                                    <li>Correct data entry errors</li>
                                    <li>Add missing transactions</li>
                                    <li>Adjust transaction dates</li>
                                    <li>Document the resolution</li>
                                </ul>
                            </li>
                            <li>Update the discrepancy status</li>
                        </ol>

                        <h5>Common Discrepancy Causes</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Data Entry Errors</h6>
                                <ul>
                                    <li>Wrong amount entered</li>
                                    <li>Incorrect date recorded</li>
                                    <li>Missing decimal points</li>
                                    <li>Transposed numbers</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Timing Issues</h6>
                                <ul>
                                    <li>Weekend transactions</li>
                                    <li>Holiday processing delays</li>
                                    <li>End-of-month cutoffs</li>
                                    <li>Bank processing times</li>
                                </ul>
                            </div>
                        </div>

                        <div class="success-box">
                            <h6><i class="bi bi-check-circle"></i> Resolution Tips</h6>
                            <ul class="mb-0">
                                <li>Always investigate before making changes</li>
                                <li>Document your findings and actions</li>
                                <li>Get approval for significant adjustments</li>
                                <li>Follow up to ensure corrections are processed</li>
                                <li>Use notes to explain complex resolutions</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports Section -->
            <div class="doc-section" id="reports">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-graph-up"></i> 8. Reports and History</h3>
                    </div>
                    <div class="card-body">
                        <p>The reconciliation system provides comprehensive reporting and history tracking to help you monitor your reconciliation activities and maintain audit trails.</p>

                        <h5>Available Reports</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-clock-history"></i> Reconciliation History</h6>
                                        <p>Complete history of all reconciliation activities with filtering options</p>
                                        <ul>
                                            <li>Filter by date range</li>
                                            <li>Filter by account</li>
                                            <li>Filter by status</li>
                                            <li>Export to CSV/Excel</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="bi bi-exclamation-triangle"></i> Unmatched Transactions</h6>
                                        <p>View all transactions that haven't been matched yet</p>
                                        <ul>
                                            <li>Bank transactions</li>
                                            <li>POS transactions</li>
                                            <li>Filter by date range</li>
                                            <li>Quick action buttons</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Dashboard Statistics</h5>
                        <p>The main dashboard provides key metrics:</p>
                        <ul>
                            <li><strong>Total Reconciliations:</strong> Number of reconciliation sessions created</li>
                            <li><strong>Completed:</strong> Successfully completed reconciliations</li>
                            <li><strong>In Progress:</strong> Currently active reconciliations</li>
                            <li><strong>Unmatched:</strong> Transactions requiring attention</li>
                        </ul>

                        <h5>Audit Trail</h5>
                        <p>Every action in the reconciliation system is tracked:</p>
                        <ul>
                            <li>Who performed each action</li>
                            <li>When the action was taken</li>
                            <li>What changes were made</li>
                            <li>Notes and comments added</li>
                        </ul>

                        <h5>Export Options</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <i class="bi bi-file-earmark-spreadsheet feature-icon"></i>
                                    <h6>Excel Export</h6>
                                    <p>Export data to Excel for further analysis</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <i class="bi bi-file-earmark-text feature-icon"></i>
                                    <h6>CSV Export</h6>
                                    <p>Export data to CSV for import into other systems</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <i class="bi bi-printer feature-icon"></i>
                                    <h6>Print Reports</h6>
                                    <p>Print reconciliation reports for physical records</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Troubleshooting Section -->
            <div class="doc-section" id="troubleshooting">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-tools"></i> 9. Troubleshooting</h3>
                    </div>
                    <div class="card-body">
                        <h5>Common Issues and Solutions</h5>

                        <div class="accordion" id="troubleshootingAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#issue1">
                                        Import Errors
                                    </button>
                                </h2>
                                <div id="issue1" class="accordion-collapse collapse show" data-bs-parent="#troubleshootingAccordion">
                                    <div class="accordion-body">
                                        <h6>Problem:</h6>
                                        <p>Bank statement import fails or shows errors</p>
                                        <h6>Solutions:</h6>
                                        <ul>
                                            <li>Check file format (CSV or Excel only)</li>
                                            <li>Ensure required columns are present</li>
                                            <li>Remove empty rows from the file</li>
                                            <li>Check date format (YYYY-MM-DD or MM/DD/YYYY)</li>
                                            <li>Verify amounts don't contain currency symbols</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#issue2">
                                        Permission Denied
                                    </button>
                                </h2>
                                <div id="issue2" class="accordion-collapse collapse" data-bs-parent="#troubleshootingAccordion">
                                    <div class="accordion-body">
                                        <h6>Problem:</h6>
                                        <p>Getting "access_denied" error when trying to access reconciliation</p>
                                        <h6>Solutions:</h6>
                                        <ul>
                                            <li>Ensure you have "view_finance" permission</li>
                                            <li>Check your user role assignments</li>
                                            <li>Contact your system administrator</li>
                                            <li>Log out and log back in</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#issue3">
                                        Missing Transactions
                                    </button>
                                </h2>
                                <div id="issue3" class="accordion-collapse collapse" data-bs-parent="#troubleshootingAccordion">
                                    <div class="accordion-body">
                                        <h6>Problem:</h6>
                                        <p>Expected POS transactions don't appear in reconciliation</p>
                                        <h6>Solutions:</h6>
                                        <ul>
                                            <li>Check the date range of your reconciliation</li>
                                            <li>Verify POS transactions are properly recorded</li>
                                            <li>Check if transactions are already matched</li>
                                            <li>Look for data entry errors in POS system</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#issue4">
                                        Balance Discrepancies
                                    </button>
                                </h2>
                                <div id="issue4" class="accordion-collapse collapse" data-bs-parent="#troubleshootingAccordion">
                                    <div class="accordion-body">
                                        <h6>Problem:</h6>
                                        <p>Reconciliation shows unexpected balance differences</p>
                                        <h6>Solutions:</h6>
                                        <ul>
                                            <li>Double-check opening and closing balances</li>
                                            <li>Look for unmatched transactions</li>
                                            <li>Check for duplicate entries</li>
                                            <li>Verify all transactions are included</li>
                                            <li>Review bank statement for errors</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Getting Help</h5>
                        <div class="info-box">
                            <h6><i class="bi bi-question-circle"></i> Support Resources</h6>
                            <ul class="mb-0">
                                <li>Check this documentation first</li>
                                <li>Review system logs for error details</li>
                                <li>Contact your system administrator</li>
                                <li>Check the POS system documentation</li>
                                <li>Verify database connectivity</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Best Practices Section -->
            <div class="doc-section" id="best-practices">
                <div class="card doc-card">
                    <div class="card-header">
                        <h3><i class="bi bi-star"></i> 10. Best Practices</h3>
                    </div>
                    <div class="card-body">
                        <h5>Reconciliation Workflow</h5>
                        <ol>
                            <li><strong>Regular Schedule:</strong> Reconcile monthly or weekly</li>
                            <li><strong>Consistent Process:</strong> Follow the same steps each time</li>
                            <li><strong>Timely Completion:</strong> Don't let reconciliations pile up</li>
                            <li><strong>Documentation:</strong> Keep detailed notes of issues</li>
                            <li><strong>Review:</strong> Have someone else review completed reconciliations</li>
                        </ol>

                        <h5>Data Quality</h5>
                        <ul>
                            <li>Ensure accurate data entry in POS system</li>
                            <li>Verify bank statement accuracy</li>
                            <li>Keep transaction descriptions clear and consistent</li>
                            <li>Use reference numbers when available</li>
                            <li>Regularly backup reconciliation data</li>
                        </ul>

                        <h5>Security and Access</h5>
                        <ul>
                            <li>Limit reconciliation access to authorized personnel</li>
                            <li>Use strong passwords and two-factor authentication</li>
                            <li>Regularly review user permissions</li>
                            <li>Log out when finished</li>
                            <li>Keep reconciliation data secure</li>
                        </ul>

                        <h5>Performance Tips</h5>
                        <ul>
                            <li>Close completed reconciliations promptly</li>
                            <li>Archive old reconciliation data</li>
                            <li>Use filters to focus on specific periods</li>
                            <li>Batch similar transactions together</li>
                            <li>Regularly clean up test data</li>
                        </ul>

                        <div class="success-box">
                            <h6><i class="bi bi-trophy"></i> Success Metrics</h6>
                            <p>Track these metrics to measure reconciliation success:</p>
                            <ul class="mb-0">
                                <li>Time to complete reconciliations</li>
                                <li>Number of discrepancies found</li>
                                <li>Accuracy of automatic matches</li>
                                <li>User satisfaction with the process</li>
                                <li>Reduction in manual errors</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center py-4">
                <p class="text-muted">
                    <i class="bi bi-info-circle"></i>
                    This documentation is part of your POS System. For additional support, contact your system administrator.
                </p>
                <a href="../reconciliation.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Back to Reconciliation
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
