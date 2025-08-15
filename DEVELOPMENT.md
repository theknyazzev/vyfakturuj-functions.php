# Developer Documentation

This document provides technical details for developers working with the Vyfakturuj.cz WooCommerce integration.

## 🏗️ Architecture Overview

The integration consists of two main classes:

### VyfakturujAPI Class
- **Purpose**: Low-level API communication with Vyfakturuj.cz
- **Responsibilities**: HTTP requests, authentication, response handling
- **Location**: `vyfakturuj-integration.php` lines 15-180

### ToretVyfakturuj Class  
- **Purpose**: WooCommerce integration and business logic
- **Responsibilities**: Order processing, data transformation, WordPress hooks
- **Location**: `vyfakturuj-integration.php` lines 185-850

## 🔌 API Integration Details

### Authentication
```php
// Basic Authentication using login and API hash
$auth_header = 'Basic ' . base64_encode($login . ':' . $apiHash);
```

### Endpoints Used
- `POST /invoice/` - Create new invoice
- `GET /invoice/{id}/` - Retrieve invoice details
- `POST /invoice/{id}/do/send-mail/` - Send invoice via email
- `GET /test/` - API connection test

### Request Structure
```php
$args = [
    'method' => 'POST',
    'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($login . ':' . $apiHash)
    ],
    'body' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'timeout' => 30,
    'sslverify' => true
];
```

## 🎣 WordPress Hooks

### Action Hooks Used
```php
// Order status change trigger
add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 4);

// New order creation
add_action('woocommerce_new_order', array($this, 'on_new_order'), 10, 1);

// Admin interface
add_action('admin_menu', array($this, 'add_admin_menu'));
add_action('add_meta_boxes', array($this, 'add_order_metabox'));

// AJAX handlers
add_action('wp_ajax_create_vyfakturuj_invoice', 'manual_invoice_creation_handler');
add_action('wp_ajax_resend_vyfakturuj_pdf', 'resend_pdf_handler');
```

### Filter Hooks Available
```php
// Customize invoice data before sending
apply_filters('vyfakturuj_invoice_data', $invoice_data, $order);

// Modify customer data
apply_filters('vyfakturuj_customer_data', $customer_data, $order);

// Customize email template
apply_filters('vyfakturuj_email_template', $template, $order, $invoice_id);
```

## 💾 Data Structures

### Invoice Data Format
```php
$invoice_data = [
    'type' => 1,                    // Invoice type (1 = regular)
    'date' => 'Y-m-d',             // Invoice date
    'due_date' => 'Y-m-d',         // Due date (+14 days)
    'vs' => 'order_number',         // Variable symbol
    'calculate_vat' => 1,          // Calculate VAT (1 = yes)
    'round_invoice' => 2,          // Rounding method (2 = mathematical)
    'payment_method' => 1,         // Payment method ID
    'customer' => $customer_data,   // Customer information
    'items' => $items_array,       // Invoice items
    'note' => 'order_details',     // Invoice notes
    'currency' => 'CZK'            // Currency code
];
```

### Customer Data Structure
```php
$customer_data = [
    'name' => 'Customer Name',
    'email' => 'customer@email.com',
    'phone' => '+420123456789',
    'address' => 'Street Address',
    'address2' => 'Additional Address',
    'city' => 'City Name',
    'state' => 'State/Region',
    'zip' => 'Postal Code',
    'country' => 'CZ',
    'company' => 'Company Name'
];
```

### Invoice Item Structure
```php
$item = [
    'text' => 'Product Description',
    'quantity' => 2,
    'unit_price' => 1000.50,       // Price excluding VAT
    'vat_rate' => 21,              // VAT percentage
    'unit' => 'ks'                 // Unit (ks = pieces)
];
```

## 🧮 VAT Calculations

### Price Conversion Logic
```php
// WooCommerce stores prices INCLUDING VAT
$price_with_vat = $item->get_total() + $item->get_total_tax();

// Convert to price EXCLUDING VAT for API
$price_without_vat = $price_with_vat / 1.21;  // 21% VAT rate

// Per unit calculation
$unit_price_without_vat = $price_without_vat / $quantity;
```

### VAT Rate Mapping
- Standard rate: 21% (default for all products)
- Reduced rates: Not currently implemented
- Zero rate: Not currently implemented

## 📊 Database Schema

### WordPress Options
```php
// API configuration
get_option('vyfakturuj_login');      // User login
get_option('vyfakturuj_api_key');    // API key
```

### Order Meta Fields
```php
// Invoice tracking
'_vyfakturuj_invoice_id'       // Vyfakturuj invoice ID
'_vyfakturuj_invoice_number'   // Invoice number
'_vyfakturuj_pdf_sent'         // PDF send timestamp
'_vyfakturuj_pdf_sent_email'   // Recipient email
'_vyfakturuj_pdf_sent_method'  // Send method (api/manual)
```

### Product Meta Fields
```php
// Tire specifications
'tyre_brand'    // Tire brand
'tyre_model'    // Tire model
'width'         // Tire width (mm)
'height'        // Tire height ratio (%)
'diameter'      // Rim diameter (inches)
'season'        // Tire season
'load_index'    // Load index
'speed_index'   // Speed index
```

## 🔄 Workflow Diagrams

### Invoice Creation Flow
```
Order Status Change
        ↓
Check if "processing" or "completed"
        ↓
Verify API credentials
        ↓
Check if invoice already exists
        ↓
Prepare invoice data
        ↓
Send to Vyfakturuj API
        ↓
Save invoice ID to order meta
        ↓
Send PDF to customer
        ↓
Log completion
```

### Email Sending Flow
```
Get customer email
        ↓
Prepare email data (3 formats)
        ↓
Attempt 1: Test structure → Send
        ↓
If failed: Attempt 2 with alt structure
        ↓
If failed: Attempt 3 with minimal data
        ↓
Update order meta with results
        ↓
Log completion/failure
```

## 🧪 Testing

### Unit Test Structure
```php
class VyfakturujIntegrationTest extends WP_UnitTestCase {
    
    public function setUp() {
        parent::setUp();
        // Activate WooCommerce
        // Create test products and orders
    }
    
    public function test_invoice_creation() {
        // Test invoice generation
    }
    
    public function test_vat_calculation() {
        // Test VAT price conversion
    }
    
    public function test_email_sending() {
        // Test PDF email delivery
    }
}
```

### Manual Testing Checklist
- [ ] API connection test
- [ ] Invoice creation for new order
- [ ] PDF email delivery
- [ ] VAT calculation accuracy
- [ ] Error handling scenarios
- [ ] Admin interface functionality

## 🐛 Debugging

### Enable Debug Logging
```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Integration logs appear in WooCommerce logs
// Navigate to: WooCommerce → Status → Logs
// Filter by source: 'vyfakturuj'
```

### Common Debug Points
```php
// Check API response
$this->log_message("API Response: " . json_encode($response));

// Verify order data
$this->log_message("Order ID: $order_id, Status: $new_status");

// Monitor email sending
$this->log_message("Email attempt #$attempt to $email");
```

### Error Scenarios to Test
1. Invalid API credentials
2. Network connectivity issues
3. Malformed order data
4. Missing customer email
5. API rate limiting
6. Invalid VAT calculations

## 🔧 Customization Examples

### Custom Invoice Data
```php
add_filter('vyfakturuj_invoice_data', function($data, $order) {
    // Add custom invoice notes
    $data['note'] .= "\nCustom business logic note";
    
    // Modify due date
    $data['due_date'] = date('Y-m-d', strtotime('+30 days'));
    
    return $data;
}, 10, 2);
```

### Custom Email Template
```php
add_filter('vyfakturuj_email_template', function($template, $order, $invoice_id) {
    // Customize email subject and body
    $template['subject'] = "Custom Subject: Invoice #$invoice_id";
    $template['message'] = "Custom email body content";
    
    return $template;
}, 10, 3);
```

### Custom Payment Method Mapping
```php
// Modify the get_payment_method() function
private function get_payment_method($wc_payment_method) {
    $custom_methods = [
        'custom_gateway' => 16,  // Custom payment method
        'crypto_payment' => 32,  // Cryptocurrency
    ];
    
    if (isset($custom_methods[$wc_payment_method])) {
        return $custom_methods[$wc_payment_method];
    }
    
    // Fall back to original logic
    return parent::get_payment_method($wc_payment_method);
}
```

## 📈 Performance Considerations

### Optimization Tips
1. **Caching**: Cache API responses for repeated requests
2. **Async Processing**: Consider background job processing for large orders
3. **Rate Limiting**: Implement rate limiting for API calls
4. **Database Queries**: Optimize order meta queries

### Monitoring
```php
// Add performance logging
$start_time = microtime(true);
// ... API operation ...
$execution_time = microtime(true) - $start_time;
$this->log_message("API call took: {$execution_time}s");
```

## 🚀 Deployment

### Production Checklist
- [ ] API credentials configured
- [ ] SSL certificates valid
- [ ] Error logging enabled
- [ ] Performance monitoring setup
- [ ] Backup procedures in place
- [ ] Test invoice creation workflow

### Environment Variables
```php
// Recommended environment-specific configuration
define('VYFAKTURUJ_API_ENDPOINT', 'https://api.vyfakturuj.cz/2.0/');
define('VYFAKTURUJ_TIMEOUT', 30);
define('VYFAKTURUJ_DEBUG', false);
```

## 📋 Code Standards

### Coding Style
- Follow WordPress Coding Standards
- Use PSR-4 autoloading where applicable
- Maintain backward compatibility with PHP 7.4+
- Include comprehensive docblocks

### Security Best Practices
- Sanitize all input data
- Validate API responses
- Use WordPress nonces for AJAX requests
- Escape output data appropriately

### Error Handling
```php
try {
    // API operation
} catch (Exception $e) {
    // Log error
    $this->log_message("Error: " . $e->getMessage());
    
    // Add order note
    $order->add_order_note("Invoice creation failed: " . $e->getMessage());
    
    // Return graceful failure
    return false;
}
```

---

For additional support or questions, please review the main [README.md](README.md) or check the integration logs for specific error messages.
