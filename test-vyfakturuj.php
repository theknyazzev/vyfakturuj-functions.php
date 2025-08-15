<?php
/**
 * Test file for Vyfakturuj.cz API integration
 * 
 * This file can be placed in your WordPress root directory to test the API connection.
 * Access it via: https://yoursite.com/test-vyfakturuj.php
 */

// Include WordPress
require_once('wp-config.php');
require_once('wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied. You must be an administrator to run this test.');
}

// Include the integration file
require_once(get_template_directory() . '/vyfakturuj-integration.php');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Vyfakturuj.cz API Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; background: #f0f8f0; padding: 10px; border: 1px solid green; }
        .error { color: red; background: #fff0f0; padding: 10px; border: 1px solid red; }
        .info { color: blue; background: #f0f0f8; padding: 10px; border: 1px solid blue; }
        pre { background: #f5f5f5; padding: 15px; overflow-x: auto; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>Vyfakturuj.cz API Integration Test</h1>
    
    <?php
    $login = get_option('vyfakturuj_login', '');
    $api_key = get_option('vyfakturuj_api_key', '');
    
    if (empty($login) || empty($api_key)) {
        echo '<div class="error">Error: API credentials not configured. Please go to WooCommerce → Vyfakturuj.cz to set up your login and API key.</div>';
        exit;
    }
    ?>
    
    <div class="info">
        <strong>Configuration:</strong><br>
        Login: <?php echo esc_html($login); ?><br>
        API Key: <?php echo esc_html(substr($api_key, 0, 10) . '...'); ?><br>
    </div>
    
    <div class="test-section">
        <h2>1. API Connection Test</h2>
        <?php
        try {
            $api = new VyfakturujAPI($login, $api_key);
            $test_result = $api->test();
            
            echo '<div class="success">✓ API connection successful!</div>';
            echo '<pre>' . json_encode($test_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
            
        } catch (Exception $e) {
            echo '<div class="error">✗ API connection failed: ' . esc_html($e->getMessage()) . '</div>';
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>2. WooCommerce Integration Test</h2>
        <?php
        if (class_exists('WooCommerce')) {
            echo '<div class="success">✓ WooCommerce is active</div>';
            
            // Check if integration class exists
            if (class_exists('ToretVyfakturuj')) {
                echo '<div class="success">✓ ToretVyfakturuj integration class loaded</div>';
                
                // Test hooks
                $integration = new ToretVyfakturuj();
                echo '<div class="success">✓ Integration hooks initialized</div>';
                
            } else {
                echo '<div class="error">✗ ToretVyfakturuj class not found</div>';
            }
            
        } else {
            echo '<div class="error">✗ WooCommerce is not active</div>';
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>3. Test Order Processing</h2>
        <?php
        if (class_exists('WooCommerce') && class_exists('ToretVyfakturuj')) {
            // Get a recent order for testing
            $orders = wc_get_orders([
                'limit' => 1,
                'status' => ['processing', 'completed'],
                'orderby' => 'date',
                'order' => 'DESC'
            ]);
            
            if (!empty($orders)) {
                $order = $orders[0];
                $order_id = $order->get_id();
                
                echo '<div class="info">Testing with order #' . $order_id . '</div>';
                
                // Check if invoice already exists
                $existing_invoice = get_post_meta($order_id, '_vyfakturuj_invoice_id', true);
                
                if ($existing_invoice) {
                    echo '<div class="success">✓ Invoice already exists for this order (ID: ' . esc_html($existing_invoice) . ')</div>';
                    
                    // Test getting invoice
                    try {
                        $api = new VyfakturujAPI($login, $api_key);
                        $invoice_data = $api->getInvoice($existing_invoice);
                        echo '<div class="success">✓ Successfully retrieved invoice data</div>';
                        echo '<pre>' . json_encode($invoice_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                    } catch (Exception $e) {
                        echo '<div class="error">✗ Failed to retrieve invoice: ' . esc_html($e->getMessage()) . '</div>';
                    }
                    
                } else {
                    echo '<div class="info">No invoice exists for this order yet.</div>';
                    
                    // Test invoice data preparation
                    try {
                        $integration = new ToretVyfakturuj();
                        $reflection = new ReflectionClass($integration);
                        $method = $reflection->getMethod('prepare_invoice_data');
                        $method->setAccessible(true);
                        $invoice_data = $method->invoke($integration, $order);
                        
                        echo '<div class="success">✓ Invoice data preparation successful</div>';
                        echo '<pre>' . json_encode($invoice_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                        
                    } catch (Exception $e) {
                        echo '<div class="error">✗ Invoice data preparation failed: ' . esc_html($e->getMessage()) . '</div>';
                    }
                }
                
            } else {
                echo '<div class="info">No processed orders found for testing.</div>';
            }
        } else {
            echo '<div class="error">Cannot test - WooCommerce or integration not available</div>';
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>4. System Information</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 8px; font-weight: bold;">WordPress Version:</td>
                <td style="padding: 8px;"><?php echo get_bloginfo('version'); ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 8px; font-weight: bold;">WooCommerce Version:</td>
                <td style="padding: 8px;"><?php echo class_exists('WooCommerce') ? WC()->version : 'Not installed'; ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 8px; font-weight: bold;">PHP Version:</td>
                <td style="padding: 8px;"><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 8px; font-weight: bold;">cURL Support:</td>
                <td style="padding: 8px;"><?php echo function_exists('curl_init') ? '✓ Yes' : '✗ No'; ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 8px; font-weight: bold;">SSL Support:</td>
                <td style="padding: 8px;"><?php echo extension_loaded('openssl') ? '✓ Yes' : '✗ No'; ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 8px; font-weight: bold;">WP Remote POST:</td>
                <td style="padding: 8px;"><?php echo function_exists('wp_remote_post') ? '✓ Available' : '✗ Not available'; ?></td>
            </tr>
        </table>
    </div>
    
    <div class="test-section">
        <h2>5. Recent Logs</h2>
        <?php
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            echo '<div class="info">Logging is available. Check WooCommerce → Status → Logs for "vyfakturuj" entries.</div>';
            
            // Test logging
            $logger->info('Test log entry from test script', array('source' => 'vyfakturuj'));
            echo '<div class="success">✓ Test log entry created</div>';
        } else {
            echo '<div class="error">✗ WooCommerce logger not available</div>';
        }
        ?>
    </div>
    
    <p><a href="<?php echo admin_url('admin.php?page=vyfakturuj-settings'); ?>">← Back to Vyfakturuj.cz Settings</a></p>
    
</body>
</html>
