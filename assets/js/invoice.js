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
            $(document).on('click', '.checkoutguard-print-invoice', this.printInvoice.bind(this));
            
            // Single shipping slip print
            $(document).on('click', '.checkoutguard-print-shipping', this.printShippingSlip.bind(this));
            
            // Select all orders
            $('#checkoutguard-select-all-orders').on('change', this.toggleSelectAll.bind(this));
            
            // Bulk invoice
            $('#checkoutguard-bulk-invoice').on('click', this.bulkInvoice.bind(this));
            
            // Bulk shipping slip
            $('#checkoutguard-bulk-shipping').on('click', this.bulkShippingSlip.bind(this));
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
                $(`.checkoutguard-print-invoice[data-order-id="${orderId}"]`) : 
                $(`.checkoutguard-print-shipping[data-order-id="${orderId}"]`);
            
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
                .checkoutguard-invoice-document {
                    max-width: 800px;
                    margin: 0 auto;
                }
                
                .checkoutguard-invoice-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    margin-bottom: 40px;
                    padding-bottom: 20px;
                    border-bottom: 3px solid #4f46e5;
                }
                
                .checkoutguard-invoice-logo img {
                    max-width: 200px;
                    height: auto;
                }
                
                .checkoutguard-invoice-logo h1 {
                    font-size: 28px;
                    color: #4f46e5;
                }
                
                .checkoutguard-invoice-title {
                    text-align: right;
                }
                
                .checkoutguard-invoice-title h2 {
                    font-size: 32px;
                    color: #4f46e5;
                    margin-bottom: 5px;
                }
                
                .checkoutguard-invoice-addresses {
                    display: grid;
                    grid-template-columns: 1fr 1fr 1fr;
                    gap: 30px;
                    margin-bottom: 40px;
                }
                
                .checkoutguard-invoice-addresses h3 {
                    font-size: 14px;
                    text-transform: uppercase;
                    color: #666;
                    margin-bottom: 10px;
                }
                
                .checkoutguard-invoice-addresses p {
                    margin-bottom: 5px;
                    line-height: 1.6;
                }
                
                .checkoutguard-invoice-details table {
                    width: 100%;
                }
                
                .checkoutguard-invoice-details th {
                    text-align: left;
                    padding: 5px 10px 5px 0;
                    font-weight: 600;
                }
                
                .checkoutguard-invoice-details td {
                    padding: 5px 0 5px 10px;
                }
                
                .checkoutguard-invoice-items {
                    margin-bottom: 40px;
                }
                
                .checkoutguard-invoice-items table {
                    width: 100%;
                    border-collapse: collapse;
                }
                
                .checkoutguard-invoice-items thead th {
                    background: #f3f4f6;
                    padding: 12px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                }
                
                .checkoutguard-invoice-items tbody td {
                    padding: 12px;
                    border-bottom: 1px solid #eee;
                }
                
                .checkoutguard-invoice-items tfoot td {
                    padding: 12px;
                    border-top: 2px solid #ddd;
                }
                
                .checkoutguard-invoice-items tfoot tr:last-child td {
                    font-size: 18px;
                    background: #f9fafb;
                    border-top: 3px solid #4f46e5;
                }
                
                .checkoutguard-invoice-footer {
                    text-align: center;
                    margin-top: 60px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    color: #666;
                }
                
                /* Shipping Slip Styles */
                .checkoutguard-shipping-slip-document {
                    max-width: 400px;
                    margin: 0 auto;
                    padding: 15px;
                }
                
                .checkoutguard-shipping-header {
                    text-align: center;
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #4f46e5;
                }
                
                .checkoutguard-shipping-header h2 {
                    font-size: 24px;
                    color: #4f46e5;
                    margin-bottom: 8px;
                }
                
                .checkoutguard-shipping-header p {
                    font-size: 13px;
                    margin: 3px 0;
                }
                
                .checkoutguard-shipping-addresses {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 15px;
                    margin-bottom: 20px;
                }
                
                .checkoutguard-shipping-addresses h3 {
                    font-size: 12px;
                    text-transform: uppercase;
                    color: #666;
                    margin-bottom: 8px;
                }
                
                .checkoutguard-shipping-addresses p {
                    margin-bottom: 3px;
                    line-height: 1.4;
                    font-size: 12px;
                }
                
                .checkoutguard-shipping-items table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 15px;
                    font-size: 11px;
                }
                
                .checkoutguard-shipping-items thead th {
                    background: #f3f4f6;
                    padding: 8px 6px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                    font-size: 11px;
                }
                
                .checkoutguard-shipping-items tbody td {
                    padding: 8px 6px;
                    border-bottom: 1px solid #eee;
                }
                
                .checkoutguard-shipping-total {
                    margin-bottom: 15px;
                    padding: 12px;
                    background: #f9fafb;
                    border: 2px solid #4f46e5;
                    border-radius: 6px;
                }
                
                .checkoutguard-total-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                
                .checkoutguard-total-label {
                    font-size: 14px;
                    font-weight: 600;
                    color: #374151;
                }
                
                .checkoutguard-total-amount {
                    font-size: 18px;
                    font-weight: 700;
                    color: #4f46e5;
                }
                
                .checkoutguard-shipping-notes {
                    margin-bottom: 15px;
                    padding: 10px;
                    background: #fffbeb;
                    border-left: 3px solid #f59e0b;
                    font-size: 11px;
                }
                
                .checkoutguard-shipping-notes h4 {
                    margin-bottom: 6px;
                    font-size: 12px;
                }
                
                .checkoutguard-shipping-signature {
                    margin-top: 20px;
                    font-size: 11px;
                }
                
                .checkoutguard-signature-line {
                    margin: 15px 0;
                    padding-bottom: 2px;
                    border-bottom: 1px solid #333;
                }
                
                /* Branding Footer */
                .checkoutguard-invoice-branding {
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 10px;
                    border-top: 1px solid #e5e7eb;
                    color: #6b7280;
                    font-size: 10px;
                }
                
                .checkoutguard-invoice-branding .checkoutguard-brand-name {
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
                        
                        .checkoutguard-shipping-slip-document {
                            width: 48%;
                            max-width: 48%;
                            margin: 0 1%;
                            padding: 10px;
                            page-break-inside: avoid;
                            box-sizing: border-box;
                            display: inline-block;
                            vertical-align: top;
                        }
                        
                        .checkoutguard-shipping-slip-document:nth-child(2n+1) {
                            page-break-after: avoid;
                        }
                        
                        .checkoutguard-shipping-slip-document:nth-child(2n) {
                            page-break-after: always;
                        }
                        
                        .checkoutguard-shipping-slip-document:nth-child(2n+1):last-child {
                            page-break-after: auto;
                        }
                        
                        .checkoutguard-shipping-header h2 {
                            font-size: 20px;
                        }
                        
                        .checkoutguard-shipping-addresses h3,
                        .checkoutguard-shipping-addresses p,
                        .checkoutguard-shipping-items,
                        .checkoutguard-shipping-notes,
                        .checkoutguard-shipping-signature,
                        .checkoutguard-invoice-branding {
                            font-size: 10px;
                        }
                        
                        .checkoutguard-total-label {
                            font-size: 12px;
                        }
                        
                        .checkoutguard-total-amount {
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
                        
                        .checkoutguard-invoice-document {
                            page-break-inside: avoid;
                        }
                        
                        .checkoutguard-invoice-document ~ .checkoutguard-invoice-document {
                            page-break-before: always;
                        }
                    `}
                    
                    .checkoutguard-invoice-branding {
                        page-break-inside: avoid;
                    }
                }
            `;
        },

        toggleSelectAll: function(e) {
            const isChecked = $(e.currentTarget).is(':checked');
            $('.checkoutguard-order-checkbox').prop('checked', isChecked);
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
            $('.checkoutguard-order-checkbox:checked').each(function() {
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
                            if (index < orderIds.length - 1) {
                                allHtml += '<div style="page-break-after: always;"></div>';
                            }
                        }
                    },
                    complete: () => {
                        completedCount++;
                        printAll();
                    }
                });
            });
        }
    };

    $(document).ready(function() {
        InvoiceManager.init();
    });

})(jQuery);
