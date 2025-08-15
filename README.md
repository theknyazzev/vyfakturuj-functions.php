# Vyfakturuj.cz WooCommerce Integration

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![WordPress Compatible](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce Compatible](https://img.shields.io/badge/WooCommerce-3.0%2B-purple.svg)](https://woocommerce.com/)

A robust WordPress integration that automatically creates and sends invoices through the Vyfakturuj.cz API when WooCommerce orders are processed.

## ✨ Features

- **Automatic Invoice Generation**: Creates invoices in Vyfakturuj.cz when orders reach "processing" or "completed" status
- **Email PDF Delivery**: Automatically sends PDF invoices to customers via email through Vyfakturuj.cz API
- **VAT Compliance**: Handles Czech VAT (21%) calculations according to local legislation
- **Tire Specifications**: Includes detailed tire information (brand, model, size, season, load/speed index)
- **Multiple Payment Methods**: Supports various payment methods with proper mapping
- **Admin Interface**: Easy configuration through WordPress admin panel
- **Order Management**: Metabox in order edit screen with invoice status and manual controls
- **Comprehensive Logging**: Detailed logging for debugging and monitoring
- **Error Handling**: Robust error handling with retry mechanisms

## 🚀 Quick Start

### Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Valid Vyfakturuj.cz account and API credentials

### Installation

1. **Add to your theme's functions.php**:
   ```php
   // Include the Vyfakturuj integration
   require_once get_template_directory() . '/vyfakturuj-integration.php';
   ```

2. **Configure API credentials**:
   - Go to WooCommerce → Vyfakturuj.cz in your WordPress admin
   - Enter your Vyfakturuj.cz login and API key
   - Save settings

3. **Test the integration**:
   - Create a test order
   - Change status to "Processing"
   - Check WooCommerce logs for integration activity

## ⚙️ Configuration

### Admin Settings

Navigate to **WooCommerce → Vyfakturuj.cz** to configure:

- **Login**: Your Vyfakturuj.cz account login
- **API Key**: Your Vyfakturuj.cz API key

### Automatic Triggers

Invoices are automatically created when orders change to:
- `processing` status
- `completed` status

### Manual Controls

From the order edit screen, you can:
- Manually create invoices
- Resend PDF invoices
- View invoice status and information

## 📋 Invoice Details

### What's Included

- **Product Information**: Complete tire specifications (brand, model, size, season, indices)
- **Pricing**: Prices excluding VAT with 21% VAT calculated separately
- **Customer Data**: Full billing information
- **Order Details**: Order number, date, payment method, shipping method
- **Shipping Costs**: Included with proper VAT calculation

### VAT Handling

- Prices in WooCommerce are assumed to **include** 21% VAT
- API receives prices **excluding** VAT (calculated by dividing by 1.21)
- Invoice shows both excluding and including VAT amounts
- Complies with Czech legislation requirements

## 🔧 Technical Details

### API Integration

- Uses Vyfakturuj.cz API v2.0
- Implements proper authentication with Basic Auth
- Includes retry mechanisms for reliable delivery
- Handles multiple response formats

### Error Handling

- Comprehensive logging through WooCommerce logger
- Graceful fallbacks for API failures
- Order notes for tracking invoice status
- Multiple email sending attempts with different data formats

### Data Mapping

#### Payment Methods
- Bank transfer: `bacs`, `cheque` → Type 1
- Cash on delivery: `cod` → Type 4  
- Online card: `stripe`, `woocommerce_payments` → Type 8
- PayPal: `paypal` → Type 128

#### Product Information
Custom meta fields supported:
- `tyre_brand`: Tire brand
- `tyre_model`: Tire model  
- `width`, `height`, `diameter`: Tire dimensions
- `season`: Tire season
- `load_index`, `speed_index`: Performance indices

## 📖 Usage Examples

### Basic Order Processing
```php
// When order status changes to "processing"
$order = wc_get_order($order_id);
// Invoice is automatically created and PDF sent to customer
```

### Manual Invoice Creation
```php
// From admin panel or programmatically
$integration = new ToretVyfakturuj();
$invoice_id = $integration->create_invoice_for_order($order_id);
```

### Check Invoice Status
```php
$invoice_id = get_post_meta($order_id, '_vyfakturuj_invoice_id', true);
$pdf_sent = get_post_meta($order_id, '_vyfakturuj_pdf_sent', true);
```

## 🐛 Troubleshooting

### Common Issues

1. **API Connection Errors**
   - Verify login and API key
   - Check server firewall settings
   - Review WooCommerce logs

2. **Email Delivery Issues**
   - Check customer email validity
   - Review Vyfakturuj.cz email settings
   - Monitor retry attempts in logs

3. **VAT Calculation Problems**
   - Ensure prices include VAT in WooCommerce
   - Verify 21% VAT rate setting
   - Check Czech locale settings

### Debug Mode

Enable WooCommerce logging to see detailed integration activity:
```php
// Check logs at WooCommerce → Status → Logs
// Look for 'vyfakturuj' source entries
```

## 📄 License

This project is licensed under the GPL v2 License - see the [LICENSE](LICENSE) file for details.

## 🤝 Contributing

Contributions are welcome! Please read our [Developer Documentation](DEVELOPMENT.md) for details on our code of conduct and the process for submitting pull requests.

## 📞 Support

- Check the [Developer Documentation](DEVELOPMENT.md) for technical details
- Review WooCommerce logs for error messages
- Verify Vyfakturuj.cz API status and credentials

## 🔄 Changelog

### Version 1.0.0
- Initial release
- Automatic invoice generation
- PDF email delivery
- Admin interface
- Comprehensive logging
- Error handling and retry mechanisms

---

**Note**: This integration is specifically designed for tire/wheel e-commerce stores using WooCommerce in the Czech Republic with Vyfakturuj.cz invoicing service.
