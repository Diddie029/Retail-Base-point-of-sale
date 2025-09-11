# Split Payment with Loyalty Points Integration

## Overview

The POS system now supports split payments with full loyalty points integration. Customers can pay for a single transaction using multiple payment methods, including loyalty points redemption, with automatic receipt printing and comprehensive transaction tracking.

## Features

### ✅ **Split Payment Core Features**
- **Multiple Payment Methods**: Support for cash, credit card, mobile money, bank transfer, check, and loyalty points
- **Real-time Balance Tracking**: Dynamic calculation of remaining balance as payments are added
- **Payment Validation**: Comprehensive validation for each payment method
- **Modern UI**: Bootstrap 5-based interface with intuitive controls

### ✅ **Loyalty Points Integration**
- **Customer Selection**: Choose customer for loyalty points redemption
- **Points Balance Validation**: Real-time checking of available loyalty points
- **Points-to-Currency Conversion**: Configurable conversion rate (default: 100 points = 1 currency unit)
- **Minimum Redemption**: Configurable minimum points required for redemption
- **Transaction Tracking**: Complete audit trail of points earned and redeemed

### ✅ **Receipt & Printing**
- **Automatic Receipt Generation**: Immediate receipt creation after payment completion
- **Split Payment Details**: Detailed breakdown of all payment methods used
- **Loyalty Points Information**: Shows points redeemed, earned, and current balance
- **Auto-Print Functionality**: Automatic redirection to print receipt page
- **Professional Receipt Layout**: Clean, organized receipt format

## Technical Implementation

### Backend Components

#### 1. **Enhanced process_payment.php**
```php
// Split payment processing with loyalty points support
if ($isSplitPayment) {
    foreach ($paymentData['split_payments'] as $splitPayment) {
        if ($splitPayment['method'] === 'loyalty_points') {
            // Validate and redeem loyalty points
            $currentBalance = getCustomerLoyaltyBalance($conn, $splitCustomerId);
            redeemLoyaltyPoints($conn, $splitCustomerId, $splitPointsToUse, 
                "Redeemed in split payment for purchase #$sale_id", $transaction_id);
        }
    }
}
```

#### 2. **Loyalty Validation Endpoint (include/get_customer_loyalty.php)**
- Customer loyalty balance checking
- Points validation and conversion
- Minimum redemption enforcement
- Real-time balance updates

#### 3. **Customer Management (include/get_customers.php)**
- Active customer listing
- Loyalty points display
- Customer search and selection

### Frontend Components

#### 1. **Enhanced Split Payment Manager (assets/js/split-payment.js)**
```javascript
class SplitPaymentManager {
    async addLoyaltyPointsPayment(customerId, pointsToUse) {
        const loyaltyData = await this.validateLoyaltyPoints(customerId, pointsToUse);
        return this.addPayment('loyalty_points', loyaltyData.pointsValue, {
            customer_id: customerId,
            points_to_use: pointsToUse,
            points_value: loyaltyData.pointsValue
        });
    }
}
```

#### 2. **Enhanced Receipt Display (pos/print_receipt.php)**
- Split payment method breakdown
- Loyalty points transaction details
- Professional receipt formatting
- Auto-print functionality

## Usage Guide

### For Cashiers

#### 1. **Starting a Split Payment**
1. Add items to cart as normal
2. Click "Process Payment"
3. Select "Split Payment" option
4. Choose payment methods and amounts

#### 2. **Adding Loyalty Points Payment**
1. Select "Loyalty Points" from payment methods dropdown
2. Choose customer from the customer list
3. Enter points to use (system shows available balance)
4. Click "Add Loyalty Payment"
5. System automatically calculates points value

#### 3. **Completing the Transaction**
1. Add remaining payment methods (cash, card, etc.)
2. Ensure total payments equal transaction amount
3. Click "Complete Split Payment"
4. System automatically redirects to receipt printing

### For Customers

#### **Benefits**
- **Flexible Payment Options**: Pay using multiple methods in one transaction
- **Loyalty Points Redemption**: Use accumulated points as partial payment
- **Real-time Balance**: See remaining balance and points value instantly
- **Complete Receipt**: Detailed receipt showing all payment methods used

## Configuration

### Loyalty Program Settings

#### **Points Conversion Rate**
```php
// Default: 100 points = 1 currency unit
$pointsToCurrencyRate = $loyaltySettings['points_to_currency_rate'] ?? 100;
```

#### **Minimum Redemption**
```php
// Default: 100 points minimum
$minRedemption = $loyaltySettings['minimum_redemption_points'] ?? 100;
```

### Payment Methods Configuration

The system automatically includes loyalty points as a payment method:
```php
function getPaymentMethods($conn) {
    // Adds loyalty_points if not already present
    $payment_methods[] = [
        'name' => 'loyalty_points',
        'display_name' => 'Loyalty Points',
        'category' => 'other',
        'icon' => 'bi-gift'
    ];
}
```

## Database Schema

### **Enhanced sale_payments Table**
```sql
-- Supports multiple payment records per sale
INSERT INTO sale_payments (
    sale_id, payment_method, amount, reference, received_at
) VALUES (?, ?, ?, ?, NOW())
```

### **Loyalty Points Tracking**
```sql
-- Points redemption record
INSERT INTO loyalty_points (
    customer_id, points_redeemed, transaction_type, 
    transaction_reference, description
) VALUES (?, ?, 'redeemed', ?, ?)
```

## Testing

### **Manual Testing**
1. Navigate to `pos/test_split_payment_loyalty.html`
2. Initialize split payment with test amount
3. Run automated test scenarios
4. Verify loyalty points integration

### **Test Scenarios**
- **Scenario 1**: Partial loyalty points + cash
- **Scenario 2**: Multiple payment methods including loyalty
- **Scenario 3**: Insufficient loyalty points handling
- **Scenario 4**: Receipt generation and printing

## Security & Validation

### **Input Validation**
- Customer ID validation
- Points balance verification
- Payment amount validation
- Transaction integrity checks

### **Error Handling**
- Insufficient points graceful handling
- Network error recovery
- Transaction rollback on failure
- User-friendly error messages

## Performance Considerations

### **Optimizations**
- Real-time balance caching
- Efficient customer lookup
- Minimal database queries
- Fast UI updates

### **Scalability**
- Supports unlimited payment methods
- Handles large customer bases
- Efficient loyalty points calculation
- Optimized receipt generation

## Future Enhancements

### **Planned Features**
- **Payment Method Limits**: Set maximum amounts per method
- **Loyalty Tiers**: Different conversion rates by membership level
- **Payment Scheduling**: Partial payments over time
- **Advanced Reporting**: Split payment analytics

### **Integration Opportunities**
- **Mobile App**: Split payment support in mobile POS
- **Online Store**: E-commerce split payment integration
- **Third-party APIs**: External loyalty program integration
- **Advanced Analytics**: Payment method preference analysis

## Support & Troubleshooting

### **Common Issues**
1. **Loyalty points not showing**: Check customer selection and loyalty program status
2. **Receipt not printing**: Verify auto-print settings and browser permissions
3. **Payment validation errors**: Ensure all amounts are valid and total matches
4. **Customer not found**: Verify customer is active and has loyalty account

### **Debug Mode**
Enable detailed logging by setting:
```javascript
window.debugSplitPayment = true;
```

This comprehensive split payment with loyalty points integration provides a modern, flexible, and user-friendly payment processing experience for both cashiers and customers.
