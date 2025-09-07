<?php
header('Content-Type: application/json');
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            handleForecastRequest();
            break;
        case 'GET':
            handleGetRequest();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleForecastRequest() {
    global $conn, $user_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    $budget_id = $input['budget_id'] ?? null;
    $forecast_period = $input['forecast_period'] ?? 12;
    $forecast_method = $input['forecast_method'] ?? 'linear';
    
    // Generate forecast
    $forecast_data = generateBudgetForecast($conn, $budget_id, $forecast_period, $forecast_method);
    
    // Calculate additional metrics
    $total_predicted = array_sum(array_column($forecast_data, 'predicted_expenses'));
    $avg_confidence = array_sum(array_column($forecast_data, 'confidence')) / count($forecast_data);
    
    // Get historical data for comparison
    $historical_data = getHistoricalData($conn, $budget_id);
    
    echo json_encode([
        'success' => true,
        'forecast_data' => $forecast_data,
        'summary' => [
            'total_predicted' => $total_predicted,
            'average_confidence' => $avg_confidence,
            'forecast_period' => $forecast_period,
            'method' => $forecast_method
        ],
        'historical_data' => $historical_data
    ]);
}

function handleGetRequest() {
    global $conn, $user_id;
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'budgets':
            getAvailableBudgets();
            break;
        case 'historical':
            getHistoricalData();
            break;
        case 'performance':
            getBudgetPerformance();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function getAvailableBudgets() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT id, name, budget_type, start_date, end_date, total_budget_amount, total_actual_amount
            FROM budgets 
            WHERE status = 'active' 
            ORDER BY created_at DESC
        ");
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'budgets' => $budgets
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getHistoricalData() {
    global $conn;
    
    $budget_id = $_GET['budget_id'] ?? null;
    
    try {
        if ($budget_id) {
            $stmt = $conn->prepare("
                SELECT 
                    DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                    SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                    SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue,
                    COUNT(*) as transaction_count
                FROM budget_transactions bt
                WHERE bt.budget_id = ? 
                AND bt.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
                GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
                ORDER BY month
            ");
            $stmt->execute([$budget_id]);
        } else {
            $stmt = $conn->query("
                SELECT 
                    DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                    SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                    SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue,
                    COUNT(*) as transaction_count
                FROM budget_transactions bt
                WHERE bt.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
                GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
                ORDER BY month
            ");
        }
        
        $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'historical_data' => $historical
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getBudgetPerformance() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT 
                b.id,
                b.name,
                b.total_budget_amount,
                b.total_actual_amount,
                (b.total_actual_amount / b.total_budget_amount * 100) as utilization_percentage,
                (b.total_budget_amount - b.total_actual_amount) as remaining_amount,
                b.start_date,
                b.end_date,
                DATEDIFF(b.end_date, CURDATE()) as days_remaining
            FROM budgets b
            WHERE b.status = 'active'
            ORDER BY utilization_percentage DESC
        ");
        $performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'performance' => $performance
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function generateBudgetForecast($conn, $budget_id, $periods, $method) {
    $forecast = [];
    
    if (!$budget_id) {
        // Generate overall forecast
        $stmt = $conn->query("
            SELECT 
                DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue
            FROM budget_transactions bt
            WHERE bt.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
            ORDER BY month
        ");
        $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Generate forecast for specific budget
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue
            FROM budget_transactions bt
            WHERE bt.budget_id = ? 
            AND bt.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$budget_id]);
        $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (empty($historical)) {
        return $forecast;
    }
    
    // Calculate forecast based on method
    switch ($method) {
        case 'linear':
            $forecast = calculateLinearForecast($historical, $periods);
            break;
        case 'moving_average':
            $forecast = calculateMovingAverageForecast($historical, $periods);
            break;
        case 'exponential':
            $forecast = calculateExponentialForecast($historical, $periods);
            break;
        case 'seasonal':
            $forecast = calculateSeasonalForecast($historical, $periods);
            break;
    }
    
    return $forecast;
}

// Linear regression forecast
function calculateLinearForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 2) return [];
    
    $x_sum = 0;
    $y_sum = 0;
    $xy_sum = 0;
    $x2_sum = 0;
    
    foreach ($historical as $i => $data) {
        $x = $i + 1;
        $y = $data['expenses'];
        $x_sum += $x;
        $y_sum += $y;
        $xy_sum += $x * $y;
        $x2_sum += $x * $x;
    }
    
    $slope = ($n * $xy_sum - $x_sum * $y_sum) / ($n * $x2_sum - $x_sum * $x_sum);
    $intercept = ($y_sum - $slope * $x_sum) / $n;
    
    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $x = $n + $i;
        $predicted = $slope * $x + $intercept;
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => max(0, $predicted),
            'confidence' => max(0, min(100, 100 - ($i * 5))) // Decreasing confidence
        ];
    }
    
    return $forecast;
}

// Moving average forecast
function calculateMovingAverageForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 3) return [];
    
    $window = min(3, $n);
    $recent_data = array_slice($historical, -$window);
    $avg_expenses = array_sum(array_column($recent_data, 'expenses')) / $window;
    
    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => $avg_expenses,
            'confidence' => max(0, min(100, 100 - ($i * 3)))
        ];
    }
    
    return $forecast;
}

// Exponential smoothing forecast
function calculateExponentialForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 2) return [];
    
    $alpha = 0.3; // Smoothing factor
    $forecast = [];
    
    // Initialize with first value
    $last_forecast = $historical[0]['expenses'];
    
    for ($i = 1; $i <= $periods; $i++) {
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => $last_forecast,
            'confidence' => max(0, min(100, 100 - ($i * 4)))
        ];
    }
    
    return $forecast;
}

// Seasonal forecast
function calculateSeasonalForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 12) return calculateLinearForecast($historical, $periods);
    
    // Calculate seasonal indices
    $monthly_totals = [];
    $monthly_counts = [];
    
    foreach ($historical as $data) {
        $month = date('n', strtotime($data['month'] . '-01'));
        if (!isset($monthly_totals[$month])) {
            $monthly_totals[$month] = 0;
            $monthly_counts[$month] = 0;
        }
        $monthly_totals[$month] += $data['expenses'];
        $monthly_counts[$month]++;
    }
    
    $monthly_averages = [];
    $overall_avg = array_sum($monthly_totals) / array_sum($monthly_counts);
    
    for ($i = 1; $i <= 12; $i++) {
        $monthly_averages[$i] = isset($monthly_totals[$i]) ? 
            $monthly_totals[$i] / $monthly_counts[$i] : $overall_avg;
    }
    
    $seasonal_indices = [];
    for ($i = 1; $i <= 12; $i++) {
        $seasonal_indices[$i] = $monthly_averages[$i] / $overall_avg;
    }
    
    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $month = date('n', strtotime("+$i months"));
        $predicted = $overall_avg * $seasonal_indices[$month];
        
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => max(0, $predicted),
            'confidence' => max(0, min(100, 100 - ($i * 2)))
        ];
    }
    
    return $forecast;
}
?>
