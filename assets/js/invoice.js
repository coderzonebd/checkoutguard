/**
 * CheckoutGuard - Invoice & Shipping Slip JavaScript
 * 
 * Handles printing and generating invoices and shipping slips
 * 
 * @package CheckoutGuard
 */

(function($) {
    'use strict';

    const InvoiceManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Single invoice print
            $(document).on('click', '.cg-print-invoice', this.printInvoice.bind(this));
            
            // Single shipping slip print
            $(document).on('click', '.cg-print-shipping', this.printShippingSlip.bind(this));
            
            // Select all orders
            $('#cg-select-all-orders').on('change', this.toggleSelectAll.bind(this));
            
            // Bulk invoice
            $('#cg-bulk-invoice').on('click', this.bulkInvoice.bind(this));
            
            // Bulk shipping slip
            $('#cg-bulk-shipping').on('click', this.bulkShippingSlip.bind(this));
        },

        printInvoice: function(e) {
            e.preventDefault();
            const orderId = $(e.currentTarget).data('order-id');
            this.generateAndPrint(orderId, 'invoice');
        },

        printShippingSlip: function(e) {
            e.preventDefault();
            const orderId = $(e.currentTarget).data('order-id');
            this.generateAndPrint(orderId, 'shipping');
        },

        generateAndPrint: function(orderId, type) {
            const $button = type === 'invoice' ? 
                $(`.cg-print-invoice[data-order-id="${orderId}"]`) : 
                $(`.cg-print-shipping[data-order-id="${orderId}"]`);
            
            $button.prop('disabled', true).css('opacity', '0.6');

            $.ajax({
                url: checkoutguard_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'checkoutguard_get_invoice',
                    nonce: checkoutguard_invoice_params.nonce,
                    order_id: orderId,
                    type: type
                },
                success: (response) => {
                    if (response.success) {
                        this.printDocument(response.data.html, type);
                    } else {
                        alert(response.data.message || 'Failed to generate document');
                    }
                },
                error: () => {
                    alert('An error occurred. Please try again.');
                },
                complete: () => {
                    $button.prop('disabled', false).css('opacity', '1');
                }
            });
        },

        printDocument: function(html, type) {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Print Document</title>
                    <style>
                        ${this.getPrintStyles(type)}
                    </style>
                </head>
                <body>
                    ${html}
                    <script>
                        window.onload = function() {
                            window.print();
                        };
                    </script>
                </body>
                </html>
            `);
            printWindow.document.close();
        },

        getPrintStyles: function(type) {
            const isShipping = type === 'shipping';
            
            return `
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: Arial, sans-serif;
                    padding: ${isShipping ? '0' : '40px'};
                    color: #333;
                }
                
                /* Invoice Styles */
                .cg-invoice-document {
                    max-width: 800px;
                    margin: 0 auto;
                }
                
                .cg-invoice-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    margin-bottom: 40px;
                    padding-bottom: 20px;
                    border-bottom: 3px solid #4f46e5;
                }
                
                .cg-invoice-logo img {
                    max-width: 200px;
                    height: auto;
                }
                
                .cg-invoice-logo h1 {
                    font-size: 28px;
                    color: #4f46e5;
                }
                
                .cg-invoice-title {
                    text-align: right;
                }
                
                .cg-invoice-title h2 {
                    font-size: 32px;
                    color: #4f46e5;
                    margin-bottom: 5px;
                }
                
                .cg-invoice-addresses {
                    display: grid;
                    grid-template-columns: 1fr 1fr 1fr;
                    gap: 30px;
                    margin-bottom: 40px;
                }
                
                .cg-invoice-addresses h3 {
                    font-size: 14px;
                    text-transform: uppercase;
                    color: #666;
                    margin-bottom: 10px;
                }
                
                .cg-invoice-addresses p {
                    margin-bottom: 5px;
                    line-height: 1.6;
                }
                
                .cg-invoice-details table {
                    width: 100%;
                }
                
                .cg-invoice-details th {
                    text-align: left;
                    padding: 5px 10px 5px 0;
                    font-weight: 600;
                }
                
                .cg-invoice-details td {
                    padding: 5px 0 5px 10px;
                }
                
                .cg-invoice-items {
                    margin-bottom: 40px;
                }
                
                .cg-invoice-items table {
                    width: 100%;
                    border-collapse: collapse;
                }
                
                .cg-invoice-items thead th {
                    background: #f3f4f6;
                    padding: 12px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                }
                
                .cg-invoice-items tbody td {
                    padding: 12px;
                    border-bottom: 1px solid #eee;
                }
                
                .cg-invoice-items tfoot td {
                    padding: 12px;
                    border-top: 2px solid #ddd;
                }
                
                .cg-invoice-items tfoot tr:last-child td {
                    font-size: 18px;
                    background: #f9fafb;
                    border-top: 3px solid #4f46e5;
                }
                
                .cg-invoice-footer {
                    text-align: center;
                    margin-top: 60px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    color: #666;
                }
                
                /* Shipping Slip Styles */
                .cg-shipping-slip-document {
                    max-width: 400px;
                    margin: 0 auto;
                    padding: 15px;
                }
                
                .cg-shipping-header {
                    text-align: center;
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #4f46e5;
                }
                
                .cg-shipping-header h2 {
                    font-size: 24px;
                    color: #4f46e5;
                    margin-bottom: 8px;
                }
                
                .cg-shipping-header p {
                    font-size: 13px;
                    margin: 3px 0;
                }
                
                .cg-shipping-addresses {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 15px;
                    margin-bottom: 20px;
                }
                
                .cg-shipping-addresses h3 {
                    font-size: 12px;
                    text-transform: uppercase;
                    color: #666;
                    margin-bottom: 8px;
                }
                
                .cg-shipping-addresses p {
                    margin-bottom: 3px;
                    line-height: 1.4;
                    font-size: 12px;
                }
                
                .cg-shipping-items table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 15px;
                    font-size: 11px;
                }
                
                .cg-shipping-items thead th {
                    background: #f3f4f6;
                    padding: 8px 6px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                    font-size: 11px;
                }
                
                .cg-shipping-items tbody td {
                    padding: 8px 6px;
                    border-bottom: 1px solid #eee;
                }
                
                .cg-shipping-total {
                    margin-bottom: 15px;
                    padding: 12px;
                    background: #f9fafb;
                    border: 2px solid #4f46e5;
                    border-radius: 6px;
                }
                
                .cg-total-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                
                .cg-total-label {
                    font-size: 14px;
                    font-weight: 600;
                    color: #374151;
                }
                
                .cg-total-amount {
                    font-size: 18px;
                    font-weight: 700;
                    color: #4f46e5;
                }
                
                .cg-shipping-notes {
                    margin-bottom: 15px;
                    padding: 10px;
                    background: #fffbeb;
                    border-left: 3px solid #f59e0b;
                    font-size: 11px;
                }
                
                .cg-shipping-notes h4 {
                    margin-bottom: 6px;
                    font-size: 12px;
                }
                
                .cg-shipping-signature {
                    margin-top: 20px;
                    font-size: 11px;
                }
                
                .cg-signature-line {
                    margin: 15px 0;
                    padding-bottom: 2px;
                    border-bottom: 1px solid #333;
                }
                
                /* Branding Footer */
                .cg-invoice-branding {
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 10px;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280;
                    font-size: 10px;
                }
                
                .cg-invoice-branding .cg-brand-name {
                    color: #7c3aed;
                    font-weight: 600;
                }
                
                @media print {
                    ${isShipping ? `
                        /* Shipping Slip - Landscape A4 (2-up) */
                        @page {
                            size: A4 landscape;
                            margin: 10mm;
                        }
                        
                        body {
                            padding: 0;
                            margin: 0;
                            display: flex;
                            flex-wrap: wrap;
                            align-content: flex-start;
                        }
                        
                        .cg-shipping-slip-document {
                            width: 48%;
                            max-width: 48%;
                            margin: 0 1%;
                            padding: 10px;
                            page-break-inside: avoid;
                            box-sizing: border-box;
                            display: inline-block;
                            vertical-align: top;
                        }
                        
                        .cg-shipping-slip-document:nth-child(2n+1) {
                            page-break-after: avoid;
                        }
                        
                        .cg-shipping-slip-document:nth-child(2n) {
                            page-break-after: always;
                        }
                        
                        .cg-shipping-slip-document:nth-child(2n+1):last-child {
                            page-break-after: auto;
                        }
                        
                        .cg-shipping-header h2 {
                            font-size: 20px;
                        }
                        
                        .cg-shipping-addresses h3,
                        .cg-shipping-addresses p,
                        .cg-shipping-items,
                        .cg-shipping-notes,
                        .cg-shipping-signature,
                        .cg-invoice-branding {
                            font-size: 10px;
                        }
                        
                        .cg-total-label {
                            font-size: 12px;
                        }
                        
                        .cg-total-amount {
                            font-size: 16px;
                        }
                    ` : `
                        /* Invoice - Portrait A4 */
                        @page {
                            size: A4 portrait;
                            margin: 15mm;
                        }
                        
                        body {
                            padding: 20px;
                        }
                        
                        .cg-invoice-document {
                            page-break-inside: avoid;
                        }
                        
                        .cg-invoice-document ~ .cg-invoice-document {
                            page-break-before: always;
                        }
                    `}
                    
                    .cg-invoice-branding {
                        page-break-inside: avoid;
                    }
                }
            `;
        },

        toggleSelectAll: function(e) {
            const isChecked = $(e.currentTarget).is(':checked');
            $('.cg-order-checkbox').prop('checked', isChecked);
        },

        bulkInvoice: function(e) {
            e.preventDefault();
            const selectedOrders = this.getSelectedOrders();
            
            if (selectedOrders.length === 0) {
                alert('Please select at least one order');
                return;
            }
            
            this.bulkPrint(selectedOrders, 'invoice');
        },

        bulkShippingSlip: function(e) {
            e.preventDefault();
            const selectedOrders = this.getSelectedOrders();
            
            if (selectedOrders.length === 0) {
                alert('Please select at least one order');
                return;
            }
            
            this.bulkPrint(selectedOrders, 'shipping');
        },

        getSelectedOrders: function() {
            const orders = [];
            $('.cg-order-checkbox:checked').each(function() {
                orders.push($(this).val());
            });
            return orders;
        },

        bulkPrint: function(orderIds, type) {
            let completedCount = 0;
            let allHtml = '';

            const printAll = () => {
                if (completedCount === orderIds.length) {
                    this.printDocument(allHtml, type);
                }
            };

            orderIds.forEach((orderId, index) => {
                $.ajax({
                    url: checkoutguard_admin_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'checkoutguard_get_invoice',
                        nonce: checkoutguard_invoice_params.nonce,
                        order_id: orderId,
                        type: type
                    },
                    success: (response) => {
                        if (response.success) {
                            allHtml += response.data.html;
                            // Only add page breaks for invoices (not shipping slips)
                            if (type === 'invoice' && index < orderIds.length - 1) {
                                allHtml += '<div style="page-break-after: always;"></div>';
                            }
                        }
                        completedCount++;
                        printAll();
                    },
                    error: () => {
                        completedCount++;
                        printAll();
                    }
                });
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('.cg-invoice-table').length) {
            InvoiceManager.init();
        }
    });

})(jQuery);
