# Point of Sale (POS) System

A comprehensive, web-based Point of Sale system built with PHP, MySQL, and modern web technologies. This system provides complete retail management capabilities with advanced features for inventory management, sales processing, supplier management, and business analytics.

![POS System](https://img.shields.io/badge/PHP-8.0+-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3+-purple)
![License](https://img.shields.io/badge/License-MIT-green)

## 🚀 Features

### 🆕 Latest Features (Version 2.5)
- **🔧 Till Reconciliation System**: Complete cash management with opening/closing procedures, variance tracking, and detailed reconciliation reports
- **📦 Bill of Materials (BOM)**: Multi-level product assembly with automatic cost calculation and pricing analytics
- **🎁 Loyalty Program**: Customer points system with rewards management and tiered benefits
- **💰 Financial Management**: Advanced budgeting, cash flow tracking, and comprehensive financial reporting
- **📊 Day Not Closed Tracking**: Automated monitoring and management of missed till closings with bulk operations
- **🔒 Enhanced Security**: Comprehensive security logging, monitoring, and audit trails
- **📈 Advanced Reporting**: Till reconciliation reports, BOM cost analysis, and real-time financial dashboards
- **🎨 Improved UI/UX**: Modern responsive design with sticky headers, pagination, and enhanced user experience
- **⚡ Performance Optimizations**: Improved database queries, caching, and overall system performance

### Core Functionality
- **Sales Management**: Complete sales processing with customer management
- **Inventory Management**: Real-time inventory tracking with low stock alerts
- **Product Management**: Comprehensive product catalog with variants and attributes
- **Supplier Management**: Vendor management with performance tracking
- **User Management**: Role-based access control with granular permissions
- **Reporting & Analytics**: Detailed business insights and financial reports

### Advanced Features
- **Multi-user Support**: Admin and Cashier roles with different permissions
- **Purchase Orders**: Complete procurement workflow from order to receipt
- **Return Management**: Handle product returns with approval workflows
- **Expiry Tracking**: Monitor product expiry dates and manage expired items
- **Bulk Operations**: Import/export products, bulk pricing updates
- **Barcode Support**: Generate and scan product barcodes
- **PDF Generation**: Automated invoice and receipt generation
- **Backup & Restore**: Automated database backups with scheduling
- **Email Integration**: Email notifications and testing capabilities
- **Till Reconciliation**: Advanced cash management with closing reconciliation
- **Bill of Materials (BOM)**: Multi-level product assembly and costing
- **Auto BOM Pricing**: Automatic cost calculation for assembled products
- **Loyalty Program**: Customer points and rewards management
- **Financial Management**: Comprehensive budgeting and cash flow tracking

### Product Features
- **Product Variants**: Size, color, and custom attribute management
- **Multiple Images**: Support for multiple product images
- **Product Status**: Active, inactive, discontinued, and blocked product states
- **Serial Number Tracking**: Support for serialized products
- **Warranty Management**: Track product warranties and support periods
- **Bulk Pricing**: Dynamic pricing with sale price management

### Inventory Features
- **Stock Management**: Real-time stock levels with reorder points
- **Low Stock Alerts**: Automatic notifications for low inventory
- **Inventory Transfers**: Move stock between locations
- **Stock Adjustments**: Manual stock corrections with audit trail
- **Batch Tracking**: Track inventory by batch/lot numbers
- **Shelf Labels**: Generate, print, and export professional shelf labels with barcodes

### Sales & Customer Features
- **Customer Database**: Store customer information and purchase history
- **Sales History**: Complete transaction history with detailed records
- **Discount Management**: Flexible discount application
- **Tax Calculation**: Configurable tax rates and calculations
- **Payment Methods**: Multiple payment method support
- **Receipt Generation**: Professional receipt printing
- **Till Management**: Multi-till support with opening/closing procedures
- **Cash Drop Tracking**: Monitor cash removals during shifts
- **Till Reconciliation**: Daily cash reconciliation with variance tracking
- **Customer Loyalty**: Points earning and redemption system
- **Walk-in Customer Support**: Automatic walk-in customer creation

### Supplier & Procurement
- **Supplier Performance**: Track supplier reliability and performance metrics
- **Purchase Orders**: Create and manage purchase orders
- **Supplier Returns**: Handle returns to suppliers with approval workflow
- **Payment Terms**: Configurable payment terms and conditions
- **Supplier Contracts**: Manage supplier agreements and terms

### Administrative Features
- **User Roles & Permissions**: Granular access control system
- **System Settings**: Configurable system parameters
- **Activity Logging**: Comprehensive audit trail
- **Security Features**: Login attempt monitoring and rate limiting
- **Backup Management**: Automated and manual backup capabilities
- **System Maintenance**: Database cleanup and optimization tools
- **Till Reconciliation Reports**: Comprehensive cash management reporting
- **Day Not Closed Tracking**: Monitor and manage missed till closings
- **Financial Dashboard**: Real-time financial metrics and analytics
- **Security Logs**: Detailed security event monitoring

## 🛠️ Technology Stack

### Backend
- **PHP 8.0+**: Server-side scripting
- **MySQL 5.7+**: Database management
- **PDO**: Database abstraction layer

### Frontend
- **HTML5**: Semantic markup
- **CSS3**: Custom styling with responsive design
- **JavaScript**: Dynamic user interactions
- **Bootstrap 5.3+**: UI framework
- **Bootstrap Icons**: Icon library

### Libraries & Dependencies
- **DomPDF**: PDF generation for invoices and receipts
- **Composer**: PHP dependency management
- **jQuery**: JavaScript library for enhanced interactions

## 📋 Requirements

### Server Requirements
- **PHP Version**: 8.0 or higher
- **MySQL**: 5.7 or higher
- **Web Server**: Apache/Nginx with mod_rewrite
- **Memory**: Minimum 128MB (256MB recommended)
- **Disk Space**: 100MB for installation + storage for data

### Browser Compatibility
- **Chrome**: 90+
- **Firefox**: 88+
- **Safari**: 14+
- **Edge**: 90+

## 🚀 Installation

### Quick Start
1. **Download & Extract**
   ```bash
   git clone https://github.com/your-repo/pos-system.git
   cd pos-system
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Database Setup**
   - Create MySQL database: `pos_system`
   - Import the database schema (automatically created on first run)

4. **Web Server Configuration**
   - Point document root to the project directory
   - Ensure `storage/` directory is writable
   - Configure URL rewriting if using Apache

5. **Initial Setup**
   - Navigate to `starter.php` in your browser
   - Follow the installation wizard
   - Create your admin account
   - Configure company settings

6. **Access System**
   - Login at: `http://your-domain/auth/login.php`
   - Dashboard: `http://your-domain/dashboard/dashboard.php`

### Manual Installation
1. Upload all files to your web server
2. Create MySQL database
3. Update database credentials in `include/db.php`
4. Run the installation script
5. Configure permissions for `storage/` directory

## ⚙️ Configuration

### Database Configuration
Edit `include/db.php`:
```php
$host = 'localhost';
$dbname = 'pos_system';
$username = 'your_db_user';
$password = 'your_db_password';
```

### Company Settings
Configure via Admin Panel:
- Company name and contact information
- Currency and tax settings
- Receipt templates and branding
- Email settings for notifications

### User Roles & Permissions
- **Admin**: Full system access
- **Cashier**: Sales processing and basic inventory
- **Manager**: Inventory and supplier management
- Custom roles with specific permissions

## 📖 Usage Guide

### Getting Started
1. **Login** with your administrator credentials
2. **Setup Products** - Add your product catalog
3. **Configure Categories** - Organize products by category
4. **Add Suppliers** - Set up your vendor database
5. **Process Sales** - Start selling with the POS interface

### Daily Operations
- **Morning**: Check low stock alerts, create purchase orders, and open tills
- **Throughout Day**: Process customer sales, manage inventory, and track cash drops
- **Evening**: Close tills, reconcile cash, and review daily sales reports
- **Weekly**: Analyze performance metrics, adjust inventory levels, and review financial reports

### Till Management Workflow
- **Till Opening**: Set opening amounts and register till access
- **During Shift**: Process sales, record cash drops, and monitor till balance
- **Till Closing**: Count cash, reconcile differences, and generate closing reports
- **Reconciliation**: Review variance reports and investigate discrepancies

### Inventory Management
- Set reorder points for automatic alerts
- Use bulk import for large product catalogs
- Track expiry dates for perishable items
- Generate shelf labels for product organization

## 🔧 API Endpoints

The system includes comprehensive RESTful API endpoints for integration:

### Product & Inventory APIs
- `GET /api/get_products.php` - Retrieve product catalog
- `GET /api/get_subcategories.php` - Get category subcategories
- `POST /api/search_products.php` - Search products by various criteria
- `GET /api/get_categories_and_families.php` - Get product categories and families
- `POST /api/scan_barcode.php` - Scan and retrieve product by barcode

### Till & Reconciliation APIs
- `GET /api/till_reconciliation.php` - Till reconciliation data and reports
- `POST /api/till_reconciliation.php` - Close missed days and manage till operations
- `GET /api/get_tills.php` - Retrieve active till information

### Customer & Loyalty APIs
- `GET /api/get_customers.php` - Retrieve customer database
- `GET /api/get_customer_loyalty.php` - Get customer loyalty information
- `POST /api/add_loyalty_points.php` - Add loyalty points to customers
- `POST /api/search_customers_loyalty.php` - Search customers with loyalty data

### BOM & Pricing APIs
- `GET /api/get_auto_bom_products.php` - Get BOM products for assembly
- `GET /api/get_auto_bom_units.php` - Get BOM unit information
- `POST /api/auto_bom_price_calculation.php` - Calculate BOM pricing
- `POST /api/recalculate_unit_price.php` - Recalculate product unit prices

### Financial APIs
- `GET /api/budget/` - Budget management endpoints
- `POST /api/calculate_quotation_taxes.php` - Calculate quotation taxes

## 🔒 Security Features

- **Password Security**: Strong password requirements and hashing
- **Session Management**: Secure session handling with timeouts
- **Rate Limiting**: Protection against brute force attacks
- **Input Validation**: Comprehensive data sanitization
- **SQL Injection Protection**: Prepared statements throughout
- **XSS Protection**: Output escaping and validation
- **CSRF Protection**: Token-based request validation

## 📊 Reporting & Analytics

### Built-in Reports
- **Sales Reports**: Daily, weekly, monthly sales analysis
- **Inventory Reports**: Stock levels, movement, and turnover
- **Supplier Reports**: Performance metrics and order history
- **Financial Reports**: Revenue, profit, and expense analysis
- **Customer Reports**: Purchase history and customer insights
- **Till Reconciliation Reports**: Cash variance and closing analysis
- **Till Short/Excess Reports**: Detailed cash discrepancy tracking
- **Day Not Closed Reports**: Missed till closing management
- **BOM Cost Analysis**: Bill of materials pricing reports
- **Loyalty Program Reports**: Customer points and rewards tracking
- **Budget vs Actual Reports**: Financial performance analysis
- **Cash Flow Reports**: Real-time cash flow monitoring

### Export Options
- PDF reports for professional presentation
- Excel/CSV exports for data analysis
- Custom date ranges and filtering
- Scheduled report generation

## 🔄 Backup & Recovery

### Automated Backups
- Daily database backups
- Configurable backup schedules
- Cloud storage integration options
- Backup verification and testing

### Manual Backup
- On-demand backup creation
- Selective data backup
- Backup compression and encryption
- Easy restore procedures

## 🐛 Troubleshooting

### Common Issues
- **Database Connection**: Check credentials in `include/db.php`
- **File Permissions**: Ensure `storage/` is writable
- **Memory Issues**: Increase PHP memory limit
- **Session Problems**: Clear browser cache and cookies

### Debug Mode
Enable debug logging in `include/functions.php` for detailed error information.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

### Documentation
- Complete user manual available in `/docs/`
- API documentation in `/docs/api/`
- Video tutorials and walkthroughs

### Community Support
- GitHub Issues for bug reports
- Discussion forum for questions
- Email support for enterprise customers

### Professional Services
- Custom development and integration
- Training and implementation services
- Ongoing support and maintenance

## 🔄 Version History

### Version 2.5 (Current)
- **Till Reconciliation System**: Complete cash management with opening/closing procedures
- **Bill of Materials (BOM)**: Multi-level product assembly and automatic costing
- **Loyalty Program**: Customer points system with rewards management
- **Financial Management**: Advanced budgeting, cash flow tracking, and reconciliation
- **Day Not Closed Tracking**: Automated monitoring and management of missed till closings
- **Enhanced Security**: Comprehensive security logging and monitoring
- **Advanced Reporting**: Till reconciliation, BOM cost analysis, and financial reports
- **Improved UI/UX**: Modern responsive design with sticky headers and pagination
- **API Enhancements**: RESTful APIs for till management and reconciliation
- **Performance Optimizations**: Improved database queries and caching

### Version 2.0
- Complete rewrite with modern PHP architecture
- Enhanced security features
- Improved user interface
- Advanced reporting capabilities
- Mobile-responsive design

### Version 1.5
- Added supplier performance tracking
- Enhanced inventory management
- Bulk operations support
- PDF generation improvements

### Version 1.0
- Initial release
- Basic POS functionality
- User management system
- Inventory tracking

## 🚀 Future Roadmap

### Planned Features
- **Mobile App**: Native iOS and Android applications
- **Multi-store Support**: Chain management capabilities
- **E-commerce Integration**: Online store connectivity
- **Advanced Analytics**: AI-powered business insights
- **Multi-language Support**: Internationalization features
- **Advanced BOM Features**: More complex assembly workflows
- **Enhanced Loyalty Program**: Tiered rewards and advanced customer segmentation
- **Real-time Notifications**: Push notifications for till alerts and low stock
- **Advanced Till Features**: Multi-currency support and advanced reconciliation

### Technical Improvements
- **Microservices Architecture**: Scalable system design
- **API Enhancements**: RESTful API expansion
- **Real-time Updates**: WebSocket integration
- **Cloud Deployment**: AWS/Azure support
- **Performance Optimization**: Caching and optimization
- **Database Optimization**: Improved queries and indexing
- **Frontend Enhancements**: Modern JavaScript with pagination and sticky headers
- **Security Hardening**: Enhanced authentication and authorization
- **Code Quality**: Improved error handling and debugging capabilities

## 📞 Contact

**Project Lead**: JEE
**Email**: kiprotichsawe99@gmail.com
**Website**: diddie029.github.io/Personal-Portfolio/
**GitHub**: https://github.com/Diddie029/Retail-Base-point-of-sale

