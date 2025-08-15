<?php
/**
 * Vyfakturuj.cz API Integration for WooCommerce
 * 
 * This file contains the complete integration between WooCommerce and Vyfakturuj.cz API
 * for automatic invoice generation and email sending.
 * 
 * @version 1.0.0
 * @author theknyazzev
 * @license GPL-2.0+
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vyfakturuj.cz API Client Class
 * 
 * Handles all communication with the Vyfakturuj.cz API
 */
class VyfakturujAPI
{
    // HTTP methods
    const HTTP_METHOD_POST = 'POST';
    const HTTP_METHOD_GET = 'GET';
    const HTTP_METHOD_DELETE = 'DELETE';
    const HTTP_METHOD_PUT = 'PUT';

    protected $endpointUrl = 'https://api.vyfakturuj.cz/2.0/';

    protected $login;

    protected $apiHash;

    protected $lastInfo;

    public function __construct($login, $apiHash, $endpointUrl = null)
    {
        $this->login = $login;
        $this->apiHash = $apiHash;
        if ($endpointUrl !== null) {
            $this->setEndpointUrl($endpointUrl);
        }
    }

    public function createInvoice($data)
    {
        return $this->fetchPost('invoice/', $data);
    }

    public function getInvoice($id)
    {
        return $this->fetchGet('invoice/' . $id . '/');
    }

    public function getInvoices($args = [])
    {
        return $this->fetchGet('invoice/?' . http_build_query($args));
    }

    /**
     * Send invoice via email through API (as in the original API)
     */
    public function invoice_sendMail($id, $data)
    {
        return $this->fetchPost('invoice/' . $id . '/do/send-mail/', $data);
    }

    /**
     * Test email sending (returns template without sending)
     */
    public function invoice_sendMail_test($id, $data)
    {
        $data['test'] = true;
        return $this->invoice_sendMail($id, $data);
    }

    public function createContact($data)
    {
        return $this->fetchPost('contact/', $data);
    }

    public function getContact($id)
    {
        return $this->fetchGet('contact/' . $id . '/');
    }

    public function getContacts($args = [])
    {
        return $this->fetchGet('contact/?' . http_build_query($args));
    }

    public function test()
    {
        return $this->fetchGet('test/');
    }

    private function fetchRequest($path, $method, $data = [])
    {
        $url = $this->endpointUrl . $path;
        
        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->apiHash)
            ],
            'timeout' => 30,
            'sslverify' => true
        ];

        if (!empty($data) && in_array($method, [self::HTTP_METHOD_POST, self::HTTP_METHOD_PUT])) {
            $args['body'] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('HTTP Error: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $this->lastInfo = [
            'http_code' => wp_remote_retrieve_response_code($response),
            'dataSend' => $data
        ];

        $result = json_decode($body, true);
        return is_array($result) ? $result : $body;
    }

    /**
     * GET request
     */
    protected function fetchGet($path, $data = null)
    {
        return $this->fetchRequest($path, self::HTTP_METHOD_GET, $data);
    }

    /**
     * POST request
     */
    protected function fetchPost($path, $data = null)
    {
        return $this->fetchRequest($path, self::HTTP_METHOD_POST, $data);
    }

    /**
     * PUT request
     */
    protected function fetchPut($path, $data = null)
    {
        return $this->fetchRequest($path, self::HTTP_METHOD_PUT, $data);
    }

    /**
     * DELETE request
     */
    protected function fetchDelete($path, $data = null)
    {
        return $this->fetchRequest($path, self::HTTP_METHOD_DELETE, $data);
    }

    /**
     * Get information about the last request
     */
    public function getInfo()
    {
        return $this->lastInfo;
    }

    /**
     * Set endpoint URL
     */
    public function setEndpointUrl($endpointUrl)
    {
        if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid endpoint URL');
        }
        $this->endpointUrl = $endpointUrl;
    }
}

/**
 * Main integration class for Vyfakturuj.cz
 */
class ToretVyfakturuj
{
    private $api;
    private $login;
    private $apiKey;

    public function __construct()
    {
        // Get settings from WordPress options
        $this->login = get_option('vyfakturuj_login', '');
        $this->apiKey = get_option('vyfakturuj_api_key', '');
        
        if (!empty($this->login) && !empty($this->apiKey)) {
            $this->api = new VyfakturujAPI($this->login, $this->apiKey);
        }

        // Initialize WordPress hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        // Hook for order status change
        add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 4);
        
        // Hook for new order creation
        add_action('woocommerce_new_order', array($this, 'on_new_order'), 10, 1);
        
        // Add settings to admin panel
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add metabox to order
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
    }

    /**
     * Order status change handler
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order)
    {
        // Create invoice when order moves to "processing" or "completed" status
        if (in_array($new_status, ['processing', 'completed'])) {
            $this->create_invoice_for_order($order_id);
        }
    }

    /**
     * New order handler
     */
    public function on_new_order($order_id)
    {
        // Log new order creation
        $this->log_message("New order created #$order_id");
    }

    /**
     * Create invoice for order
     */
    public function create_invoice_for_order($order_id)
    {
        if (!$this->api) {
            $this->log_message("API not initialized for order #$order_id");
            return false;
        }

        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception("Order #$order_id not found");
            }

            // Check if invoice already created
            $existing_invoice_id = get_post_meta($order_id, '_vyfakturuj_invoice_id', true);
            if (!empty($existing_invoice_id)) {
                $this->log_message("Invoice already created for order #$order_id (ID: $existing_invoice_id)");
                // If invoice exists, try to send PDF
                $this->send_invoice_pdf_to_customer($order, $existing_invoice_id);
                return $existing_invoice_id;
            }

            // Prepare invoice data
            $this->log_message("Starting data preparation for order #$order_id invoice");
            $invoice_data = $this->prepare_invoice_data($order);
            
            // Log prepared data for debugging
            $this->log_message("Invoice creation data: " . json_encode($invoice_data, JSON_UNESCAPED_UNICODE));
            
            // Create invoice through API
            $this->log_message("Sending invoice creation request to Vyfakturuj.cz");
            $response = $this->api->createInvoice($invoice_data);
            
            // Log API response
            $this->log_message("Vyfakturuj.cz API response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
            
            if (isset($response['id'])) {
                // Save invoice ID to order metadata
                update_post_meta($order_id, '_vyfakturuj_invoice_id', $response['id']);
                update_post_meta($order_id, '_vyfakturuj_invoice_number', $response['number'] ?? '');
                
                $this->log_message("Invoice successfully created for order #$order_id (ID: {$response['id']})");
                
                // Add order note
                $order->add_order_note("Vyfakturuj.cz invoice created. ID: {$response['id']}");
                
                // Send PDF to customer
                $this->send_invoice_pdf_to_customer($order, $response['id']);
                
                return $response['id'];
            } else {
                throw new Exception("Failed to create invoice: " . json_encode($response, JSON_UNESCAPED_UNICODE));
            }

        } catch (Exception $e) {
            $this->log_message("Error creating invoice for order #$order_id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send PDF invoice to customer via email through Vyfakturuj API
     */
    private function send_invoice_pdf_to_customer($order, $invoice_id)
    {
        try {
            $customer_email = $order->get_billing_email();
            if (empty($customer_email)) {
                throw new Exception("Customer email not found in order");
            }

            $this->log_message("Sending PDF invoice #$invoice_id to email $customer_email via Vyfakturuj API");

            // Prepare customer data for email sending
            $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            if (empty($customer_name)) {
                $customer_name = 'Dear Customer';
            }

            $site_name = get_bloginfo('name');
            $order_number = $order->get_order_number();
            
            // Email data for API sending according to Vyfakturuj.cz documentation
            // Start with minimal parameters for testing
            $email_data = [
                'to' => $customer_email,  // Change 'email' to 'to'
                'subject' => "Invoice for order #$order_number - $site_name",
                'message' => "Hello $customer_name!\n\n" .
                           "Thank you for your purchase at $site_name.\n" .
                           "Please find attached the invoice for order #$order_number.\n\n" .
                           "Best regards,\n$site_name Team"
            ];

            // Alternative structure if first doesn't work
            $alternative_email_data = [
                'email' => $customer_email,
                'subject' => "Invoice for order #$order_number - $site_name",
                'message' => "Hello $customer_name!\n\n" .
                           "Thank you for your purchase at $site_name.\n" .
                           "Please find attached the invoice for order #$order_number.\n\n" .
                           "Best regards,\n$site_name Team"
            ];

            // Third option - minimal
            $simple_email_data = [
                'email' => $customer_email
            ];

            // Send email through Vyfakturuj API with retry attempts
            $max_attempts = 3;
            $email_sent = false;
            
            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                try {
                    $this->log_message("Attempt #$attempt to send email for invoice #$invoice_id");
                    
                    // Choose data structure for current attempt
                    if ($attempt == 1) {
                        $current_email_data = $email_data;
                        $data_type = "main (to)";
                    } elseif ($attempt == 2) {
                        $current_email_data = $alternative_email_data;
                        $data_type = "alternative (email)";
                    } else {
                        $current_email_data = $simple_email_data;
                        $data_type = "simple (email only)";
                    }
                    
                    $this->log_message("Using $data_type format: " . json_encode($current_email_data, JSON_UNESCAPED_UNICODE));
                    
                    // Test data structure on first attempt
                    if ($attempt == 1) {
                        try {
                            $this->log_message("Testing data structure before sending...");
                            $test_response = $this->api->invoice_sendMail_test($invoice_id, $current_email_data);
                            $this->log_message("Test passed successfully: " . json_encode($test_response, JSON_UNESCAPED_UNICODE));
                        } catch (Exception $test_e) {
                            $this->log_message("Data structure test failed: " . $test_e->getMessage());
                            // Switch to alternative structure
                            $current_email_data = $alternative_email_data;
                            $data_type = "alternative (email) due to test error";
                            $this->log_message("Switching to alternative structure: " . json_encode($current_email_data, JSON_UNESCAPED_UNICODE));
                        }
                    }
                    
                    // Small pause before sending to ensure invoice is ready
                    if ($attempt > 1) {
                        sleep(3);
                    }
                    
                    $response = $this->api->invoice_sendMail($invoice_id, $current_email_data);
                    
                    $this->log_message("Email sending API response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
                    
                    // Get last request info
                    $api_info = $this->api->getInfo();
                    $this->log_message("HTTP status: " . $api_info['http_code']);
                    
                    // Check sending success
                    if ($api_info['http_code'] == 200 || $api_info['http_code'] == 201) {
                        $email_sent = true;
                        $this->log_message("Email with PDF invoice #$invoice_id successfully sent to $customer_email on attempt #$attempt ($data_type format)");
                        break;
                    } elseif (isset($response['success']) && $response['success'] === true) {
                        $email_sent = true;
                        $this->log_message("Email with PDF invoice #$invoice_id successfully sent to $customer_email on attempt #$attempt");
                        break;
                    } elseif (isset($response['status']) && $response['status'] === 'ok') {
                        $email_sent = true;
                        $this->log_message("Email with PDF invoice #$invoice_id successfully sent to $customer_email on attempt #$attempt");
                        break;
                    } else {
                        $error_msg = "HTTP: {$api_info['http_code']}";
                        if (isset($response['error'])) {
                            $error_msg .= ", Error: " . $response['error'];
                        }
                        if (isset($response['message'])) {
                            $error_msg .= ", Message: " . $response['message'];
                        }
                        $this->log_message("Attempt #$attempt: API returned unsuccessful response ($error_msg)");
                        
                        if ($attempt < $max_attempts) {
                            $this->log_message("Waiting before next attempt...");
                        }
                    }
                } catch (Exception $e) {
                    $this->log_message("Attempt #$attempt email sending failed: " . $e->getMessage());
                    if ($attempt < $max_attempts) {
                        sleep(3);
                    } else {
                        throw $e;
                    }
                }
            }
            
            if ($email_sent) {
                $this->log_message("PDF invoice #$invoice_id successfully sent to $customer_email via Vyfakturuj API");
                $order->add_order_note("PDF invoice sent via Vyfakturuj API to email: $customer_email");
                
                // Save sending information
                update_post_meta($order->get_id(), '_vyfakturuj_pdf_sent', current_time('mysql'));
                update_post_meta($order->get_id(), '_vyfakturuj_pdf_sent_email', $customer_email);
                update_post_meta($order->get_id(), '_vyfakturuj_pdf_sent_method', 'api');
            } else {
                throw new Exception("Failed to send email via API after $max_attempts attempts");
            }

        } catch (Exception $e) {
            $this->log_message("Error sending PDF invoice via API: " . $e->getMessage());
            
            // Add error note
            if (isset($order)) {
                $order->add_order_note("Error sending PDF invoice via API: " . $e->getMessage());
            }
        }
    }

    /**
     * Invoice email template
     */
    private function get_invoice_email_template($order, $customer_name, $invoice_id)
    {
        $order_number = $order->get_order_number();
        $order_total = $order->get_formatted_order_total();
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .order-details { background-color: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
                .highlight { color: #007cba; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Invoice for your order</h2>
                </div>
                
                <p>Hello <strong>$customer_name</strong>!</p>
                
                <p>Thank you for your purchase at <strong>$site_name</strong>.</p>
                
                <p>Please find attached the invoice for your order.</p>
                
                <div class='order-details'>
                    <h3>Order Details:</h3>
                    <ul>
                        <li><strong>Order Number:</strong> <span class='highlight'>#$order_number</span></li>
                        <li><strong>Order Total:</strong> <span class='highlight'>$order_total</span></li>
                        <li><strong>Order Date:</strong> " . $order->get_date_created()->format('d.m.Y H:i') . "</li>
                        <li><strong>Invoice Number:</strong> $invoice_id</li>
                    </ul>
                </div>
                
                <p>The attached invoice includes:</p>
                <ul>
                    <li>Product names and specifications</li>
                    <li>Prices excluding VAT</li>
                    <li>VAT 21% (according to Czech legislation)</li>
                    <li>Total amounts including VAT</li>
                    <li>Product quantities</li>
                    <li>Shipping costs</li>
                </ul>
                
                <p>If you have any questions about your order or invoice, please contact us.</p>
                
                <p>Best regards,<br>
                <strong>$site_name</strong> Team</p>
                
                <div class='footer'>
                    <p>This is an automated message. Please do not reply.</p>
                    <p>Website: <a href='$site_url'>$site_name</a></p>
                </div>
            </div>
        </body>
        </html>";
        
        return $message;
    }

    /**
     * Prepare invoice data from WooCommerce order - TIRES + SHIPPING ONLY
     */
    private function prepare_invoice_data($order)
    {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        
        // VAT in Czech Republic 21% - ALREADY INCLUDED IN PRICE
        $vat_rate = 21;
        
        // Prepare customer data
        $customer_data = [
            'name' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            'email' => $billing['email'] ?? '',
            'phone' => $billing['phone'] ?? '',
            'address' => $billing['address_1'] ?? '',
            'address2' => $billing['address_2'] ?? '',
            'city' => $billing['city'] ?? '',
            'state' => $billing['state'] ?? '',
            'zip' => $billing['postcode'] ?? '',
            'country' => $billing['country'] ?? 'CZ',
            'company' => $billing['company'] ?? ''
        ];

        // If name is empty, use email or "Customer"
        if (empty(trim($customer_data['name']))) {
            $customer_data['name'] = !empty($customer_data['email']) ? $customer_data['email'] : 'Customer #' . $order->get_id();
        }

        // Prepare products - TIRES ONLY (price already includes VAT)
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $quantity = $item->get_quantity();
            
            // Get price including VAT (current price in order)
            $total_with_tax = $item->get_total() + $item->get_total_tax();
            
            // Calculate price excluding VAT (price with VAT / 1.21)
            $total_without_tax = $total_with_tax / 1.21;
            $unit_price_without_tax = $quantity > 0 ? ($total_without_tax / $quantity) : 0;
            
            // Get product name
            $item_name = trim($item->get_name());
            $product_sku = $product ? $product->get_sku() : '';
            
            // Get additional tire information
            $tire_info = $this->get_tire_info($product);
            
            // Form full product description
            $full_description = $item_name;
            if ($product_sku) {
                $full_description .= ' (SKU: ' . $product_sku . ')';
            }
            if (!empty($tire_info)) {
                $full_description .= ' - ' . $tire_info;
            }
            
            // Clean name from dangerous characters
            $full_description = mb_substr($full_description, 0, 500);
            $full_description = str_replace(['/', '\\', '"', "'", "\n", "\r", "\t"], ['_', '_', '', '', ' ', ' ', ' '], $full_description);
            $full_description = trim($full_description);
            
            if (empty($full_description)) {
                $full_description = 'Tire from order #' . $order->get_order_number();
            }
            
            $this->log_message("Product: '$full_description' | Qty: $quantity | Price excl. VAT: " . round($unit_price_without_tax, 2) . " | Total price incl. VAT: " . round($total_with_tax, 2));
            
            $items[] = [
                'text' => $full_description,
                'quantity' => $quantity,
                'unit_price' => round($unit_price_without_tax, 2), // Price excluding VAT
                'vat_rate' => $vat_rate, // 21% VAT
                'unit' => 'ks' // pieces
            ];
        }

        // Add shipping (price also already includes VAT)
        foreach ($order->get_items('shipping') as $shipping_item) {
            $shipping_total_with_tax = $shipping_item->get_total() + $shipping_item->get_total_tax();
            
            if ($shipping_total_with_tax > 0) {
                // Calculate shipping cost excluding VAT
                $shipping_total_without_tax = $shipping_total_with_tax / 1.21;
                $shipping_method_title = $shipping_item->get_name();
                
                $items[] = [
                    'text' => 'Shipping: ' . $shipping_method_title,
                    'quantity' => 1,
                    'unit_price' => round($shipping_total_without_tax, 2),
                    'vat_rate' => $vat_rate, // 21% VAT
                    'unit' => 'ks'
                ];
                
                $this->log_message("Shipping: '$shipping_method_title' | Price excl. VAT: " . round($shipping_total_without_tax, 2) . " | Total price incl. VAT: " . round($shipping_total_with_tax, 2));
            }
        }

        // DO NOT add fees - they should not be in the invoice

        // Extended invoice note
        $note_parts = [];
        $note_parts[] = 'Order from online store #' . $order->get_order_number();
        $note_parts[] = 'Order date: ' . $order->get_date_created()->format('d.m.Y H:i:s');
        $note_parts[] = 'Payment method: ' . $order->get_payment_method_title();
        
        $shipping_methods = $order->get_shipping_methods();
        if (!empty($shipping_methods)) {
            $shipping_names = [];
            foreach ($shipping_methods as $shipping) {
                $shipping_names[] = $shipping->get_name();
            }
            $note_parts[] = 'Shipping: ' . implode(', ', $shipping_names);
        }
        
        if ($order->get_customer_note()) {
            $note_parts[] = 'Note: ' . $order->get_customer_note();
        }
        
        $note_parts[] = 'NOTE: Prices already include VAT 21%';

        // Form invoice data
        $invoice_data = [
            'type' => 1, // Regular invoice
            'date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime('+14 days')),
            'vs' => $order->get_order_number(), // Variable symbol
            'calculate_vat' => 1, // Calculate VAT
            'round_invoice' => 2, // Mathematical rounding
            'payment_method' => $this->get_payment_method($order->get_payment_method()),
            'customer' => $customer_data,
            'items' => $items,
            'note' => implode("\n", $note_parts),
            'currency' => $order->get_currency()
        ];

        $this->log_message("Invoice created: tires + shipping, prices already include VAT 21%, items: " . count($items));

        return $invoice_data;
    }

    /**
     * Get tire information from product
     */
    private function get_tire_info($product)
    {
        if (!$product) {
            return '';
        }

        $tire_info = [];
        
        // Brand
        $brand = get_post_meta($product->get_id(), 'tyre_brand', true);
        if ($brand) $tire_info[] = $brand;
        
        // Model
        $model = get_post_meta($product->get_id(), 'tyre_model', true);
        if ($model) $tire_info[] = $model;
        
        // Size
        $width = get_post_meta($product->get_id(), 'width', true);
        $height = get_post_meta($product->get_id(), 'height', true);
        $diameter = get_post_meta($product->get_id(), 'diameter', true);
        
        if ($width && $height && $diameter) {
            $tire_info[] = $width . '/' . $height . ' R' . $diameter;
        }
        
        // Season
        $season = get_post_meta($product->get_id(), 'season', true);
        if ($season) $tire_info[] = $season;
        
        // Load and speed index
        $load_index = get_post_meta($product->get_id(), 'load_index', true);
        $speed_index = get_post_meta($product->get_id(), 'speed_index', true);
        
        if ($load_index && $speed_index) {
            $tire_info[] = $load_index . $speed_index;
        }
        
        return implode(' ', $tire_info);
    }

    /**
     * Get payment method for Vyfakturuj.cz
     */
    private function get_payment_method($wc_payment_method)
    {
        $known_methods = [
            'bacs' => 1,       // Bank transfer
            'cheque' => 1,     // Check
            'cod' => 4,        // Cash on delivery
            'paypal' => 128,   // PayPal
            'stripe' => 8,     // Online card
            'stripe_cc' => 8,
            'woocommerce_payments' => 8,
        ];

        if (isset($known_methods[$wc_payment_method])) {
            return $known_methods[$wc_payment_method];
        }

        // Auto-detection by name
        $method_lower = strtolower($wc_payment_method);
        
        if (preg_match('/\b(cod|cash.*delivery|dobir)/i', $method_lower)) {
            return 4; // Cash on delivery
        }
        
        if (preg_match('/\b(paypal|pp_)/i', $method_lower)) {
            return 128; // PayPal
        }
        
        if (preg_match('/\b(card|credit|debit|visa|master|stripe)/i', $method_lower)) {
            return 8; // Online card
        }
        
        return 1; // Default bank transfer
    }

    /**
     * Log messages
     */
    private function log_message($message)
    {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'vyfakturuj'));
        } else {
            error_log("Vyfakturuj: $message");
        }
    }

    /**
     * Add menu to admin panel
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Vyfakturuj.cz Settings',
            'Vyfakturuj.cz',
            'manage_options',
            'vyfakturuj-settings',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin settings page
     */
    public function admin_page()
    {
        if (isset($_POST['submit'])) {
            update_option('vyfakturuj_login', sanitize_text_field($_POST['vyfakturuj_login']));
            update_option('vyfakturuj_api_key', sanitize_text_field($_POST['vyfakturuj_api_key']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }

        $login = get_option('vyfakturuj_login', '');
        $api_key = get_option('vyfakturuj_api_key', '0qGr3JavojBZWQX9v9WFz0N95jxNdKlgOkD1Rn9p');
        ?>
        <div class="wrap">
            <h1>Vyfakturuj.cz Settings</h1>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Login</th>
                        <td><input type="text" name="vyfakturuj_login" value="<?php echo esc_attr($login); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td><input type="text" name="vyfakturuj_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Information</h2>
            <p>After invoice creation, PDF is automatically sent to the customer's email specified during checkout.</p>
            <p>The invoice includes:</p>
            <ul>
                <li>Tire names with full specifications</li>
                <li>Prices excluding VAT</li>
                <li>VAT 21% (according to Czech legislation)</li>
                <li>Total prices including VAT</li>
                <li>Product quantities</li>
                <li>Shipping costs</li>
            </ul>
            
            <h2>Connection Test</h2>
            <p><a href="<?php echo site_url('/test-vyfakturuj.php'); ?>" class="button" target="_blank">Run Test</a></p>
        </div>
        <?php
    }

    /**
     * Add metabox to order
     */
    public function add_order_metabox()
    {
        $screen = 'shop_order';
        if (function_exists('wc_get_page_screen_id')) {
            $screen = wc_get_page_screen_id('shop-order');
        }
            
        add_meta_box(
            'vyfakturuj_order_info',
            'Vyfakturuj.cz Information',
            array($this, 'order_metabox_content'),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Order metabox content
     */
    public function order_metabox_content($post)
    {
        $invoice_id = get_post_meta($post->ID, '_vyfakturuj_invoice_id', true);
        $invoice_number = get_post_meta($post->ID, '_vyfakturuj_invoice_number', true);
        $pdf_sent = get_post_meta($post->ID, '_vyfakturuj_pdf_sent', true);
        $pdf_sent_email = get_post_meta($post->ID, '_vyfakturuj_pdf_sent_email', true);

        if ($invoice_id) {
            echo '<p><strong>Invoice ID:</strong> ' . esc_html($invoice_id) . '</p>';
            if ($invoice_number) {
                echo '<p><strong>Invoice Number:</strong> ' . esc_html($invoice_number) . '</p>';
            }
            if ($pdf_sent) {
                echo '<p><strong>PDF Sent:</strong> ' . date('d.m.Y H:i', strtotime($pdf_sent)) . '</p>';
                if ($pdf_sent_email) {
                    echo '<p><strong>Recipient Email:</strong> ' . esc_html($pdf_sent_email) . '</p>';
                }
            }
            echo '<button type="button" class="button" onclick="resendInvoicePdf(' . $post->ID . ')">Resend PDF</button>';
        } else {
            echo '<p>Invoice not yet created</p>';
            echo '<button type="button" class="button" onclick="createInvoiceManually(' . $post->ID . ')">Create Invoice Manually</button>';
        }
        ?>
        <script>
        function createInvoiceManually(orderId) {
            if (confirm('Create invoice for this order?')) {
                window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=create_vyfakturuj_invoice&order_id=' + orderId;
            }
        }
        
        function resendInvoicePdf(orderId) {
            if (confirm('Resend PDF invoice?')) {
                window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=resend_vyfakturuj_pdf&order_id=' + orderId;
            }
        }
        </script>
        <?php
    }
}

// Initialize the class only if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('woocommerce_init', function() {
        new ToretVyfakturuj();
    });
} else {
    add_action('admin_notices', function() {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p><strong>Vyfakturuj.cz Integration:</strong> WooCommerce must be activated for this integration to work</p></div>';
        }
    });
}

/**
 * AJAX handler for manual invoice creation
 */
add_action('wp_ajax_create_vyfakturuj_invoice', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
    
    $order_id = intval($_GET['order_id']);
    $integration = new ToretVyfakturuj();
    $result = $integration->create_invoice_for_order($order_id);
    
    if ($result) {
        wp_redirect(admin_url("post.php?post=$order_id&action=edit&message=1"));
    } else {
        wp_redirect(admin_url("post.php?post=$order_id&action=edit&message=0"));
    }
    exit;
});

/**
 * AJAX handler for PDF resending
 */
add_action('wp_ajax_resend_vyfakturuj_pdf', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
    
    $order_id = intval($_GET['order_id']);
    $order = wc_get_order($order_id);
    $invoice_id = get_post_meta($order_id, '_vyfakturuj_invoice_id', true);
    
    if ($order && $invoice_id) {
        $integration = new ToretVyfakturuj();
        $reflection = new ReflectionClass($integration);
        $method = $reflection->getMethod('send_invoice_pdf_to_customer');
        $method->setAccessible(true);
        $method->invoke($integration, $order, $invoice_id);
        
        wp_redirect(admin_url("post.php?post=$order_id&action=edit&message=1"));
    } else {
        wp_redirect(admin_url("post.php?post=$order_id&action=edit&message=0"));
    }
    exit;
});
