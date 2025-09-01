# Point of Sale (POS) System

A comprehensive, web-based Point of Sale system built with PHP, MySQL, and modern web technologies. This system provides complete retail management capabilities with advanced features for inventory management, sales processing, supplier management, and business analytics.

![POS System](https://img.shields.io/badge/PHP-8.0+-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3+-purple)
![License](https://img.shields.io/badge/License-MIT-green)

## 🚀 Features

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

### Sales & Customer Features
- **Customer Database**: Store customer information and purchase history
- **Sales History**: Complete transaction history with detailed records
- **Discount Management**: Flexible discount application
- **Tax Calculation**: Configurable tax rates and calculations
- **Payment Methods**: Multiple payment method support
- **Receipt Generation**: Professional receipt printing

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
- **Morning**: Check low stock alerts and create purchase orders
- **Throughout Day**: Process customer sales and manage inventory
- **Evening**: Review daily sales reports and reconcile cash
- **Weekly**: Analyze performance metrics and adjust inventory levels

### Inventory Management
- Set reorder points for automatic alerts
- Use bulk import for large product catalogs
- Track expiry dates for perishable items
- Generate shelf labels for product organization

## 🔧 API Endpoints

The system includes RESTful API endpoints for integration:

- `GET /api/get_products.php` - Retrieve product catalog
- `GET /api/get_subcategories.php` - Get category subcategories
- `POST /api/search_products.php` - Search products by various criteria

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

### Version 2.0 (Current)
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
- **Loyalty Program**: Customer rewards and points system
- **Multi-language Support**: Internationalization features

### Technical Improvements
- **Microservices Architecture**: Scalable system design
- **API Enhancements**: RESTful API expansion
- **Real-time Updates**: WebSocket integration
- **Cloud Deployment**: AWS/Azure support
- **Performance Optimization**: Caching and optimization

## 📞 Contact

**Project Lead**: Thiarara
**Email**: contact@thiarara.co.ke
**Website**: https://thiarara.co.ke
**GitHub**: https://github.com/Thiararapeter/pointofsale

---

**Made with ❤️ for retail businesses worldwide**
