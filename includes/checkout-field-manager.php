<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CheckoutGuard_Field_Manager {

    private $settings;
    private $default_fields;

    public function __construct() {
        $this->init_default_fields();
        $this->load_settings();
        
        // Hook into WooCommerce checkout fields with MAXIMUM priority to override ALL theme customizations
        // Using PHP_INT_MAX to ensure we're absolutely last
        add_filter( 'woocommerce_checkout_fields', [ $this, 'customize_checkout_fields' ], PHP_INT_MAX );
        add_filter( 'woocommerce_default_address_fields', [ $this, 'customize_address_fields' ], PHP_INT_MAX );
        
        // Additional hooks for themes that modify fields differently
        add_filter( 'woocommerce_billing_fields', [ $this, 'customize_billing_fields' ], PHP_INT_MAX );
        add_filter( 'woocommerce_shipping_fields', [ $this, 'customize_shipping_fields' ], PHP_INT_MAX );
        
        // Individual field filters - some themes use these
        add_filter( 'woocommerce_form_field_args', [ $this, 'customize_individual_field' ], PHP_INT_MAX, 3 );

        // Block Checkout (Store API) compatibility
        add_filter( 'woocommerce_store_api_schema', [ $this, 'customize_store_api_schema' ], PHP_INT_MAX );
        
        // Save settings action
        add_action( 'admin_init', [ $this, 'maybe_save_settings' ] );
    }

    /**
     * Initialize default checkout fields
     */
    private function init_default_fields() {
        $this->default_fields = [
            'billing' => [
                'billing_first_name' => [
                    'label' => __( 'First name', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'billing_last_name' => [
                    'label' => __( 'Last name', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'billing_company' => [
                    'label' => __( 'Company name', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => false,
                ],
                'billing_address_1' => [
                    'label' => __( 'Street address', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'billing_address_2' => [
                    'label' => __( 'Apartment, suite, unit, etc.', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => false,
                ],
                'billing_city' => [
                    'label' => __( 'Town / City', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'billing_state' => [
                    'label' => __( 'State / County', 'checkoutguard' ),
                    'type' => 'select',
                    'default_required' => false,
                ],
                'billing_postcode' => [
                    'label' => __( 'Postcode / ZIP', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'billing_country' => [
                    'label' => __( 'Country / Region', 'checkoutguard' ),
                    'type' => 'select',
                    'default_required' => true,
                ],
                'billing_phone' => [
                    'label' => __( 'Phone', 'checkoutguard' ),
                    'type' => 'tel',
                    'default_required' => true,
                ],
                'billing_email' => [
                    'label' => __( 'Email address', 'checkoutguard' ),
                    'type' => 'email',
                    'default_required' => true,
                ],
            ],
            'shipping' => [
                'shipping_first_name' => [
                    'label' => __( 'First name', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'shipping_last_name' => [
                    'label' => __( 'Last name', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'shipping_company' => [
                    'label' => __( 'Company name', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => false,
                ],
                'shipping_address_1' => [
                    'label' => __( 'Street address', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'shipping_address_2' => [
                    'label' => __( 'Apartment, suite, unit, etc.', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => false,
                ],
                'shipping_city' => [
                    'label' => __( 'Town / City', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'shipping_state' => [
                    'label' => __( 'State / County', 'checkoutguard' ),
                    'type' => 'select',
                    'default_required' => false,
                ],
                'shipping_postcode' => [
                    'label' => __( 'Postcode / ZIP', 'checkoutguard' ),
                    'type' => 'text',
                    'default_required' => true,
                ],
                'shipping_country' => [
                    'label' => __( 'Country / Region', 'checkoutguard' ),
                    'type' => 'select',
                    'default_required' => true,
                ],
            ],
            'order' => [
                'order_comments' => [
                    'label' => __( 'Order notes', 'checkoutguard' ),
                    'type' => 'textarea',
                    'default_required' => false,
                ],
            ],
        ];
    }

    /**
     * Load settings from database
     */
    private function load_settings() {
        $defaults = [
            'enabled' => false,
            'hidden_fields' => [],
            'optional_fields' => [],
            'custom_labels' => [], // Pro feature
        ];
        
        $this->settings = get_option( 'checkoutguard_field_manager_settings', $defaults );
        $this->settings = wp_parse_args( $this->settings, $defaults );
    }

    /**
     * Customize WooCommerce checkout fields
     */
    public function customize_checkout_fields( $fields ) {
        if ( ! $this->settings['enabled'] ) {
            return $fields;
        }

        // Handle custom labels (Pro feature)
        if ( ! empty( $this->settings['custom_labels'] ) ) {
            foreach ( $this->settings['custom_labels'] as $field_key => $custom_label ) {
                if ( empty( $custom_label ) ) {
                    continue;
                }
                
                list( $section, $field ) = $this->parse_field_key( $field_key );
                
                if ( isset( $fields[ $section ][ $field ]['label'] ) ) {
                    $fields[ $section ][ $field ]['label'] = sanitize_text_field( $custom_label );
                    // Add asterisk back if required
                    if ( ! empty( $fields[ $section ][ $field ]['required'] ) ) {
                        $fields[ $section ][ $field ]['label'] .= ' <abbr class="required" title="required">*</abbr>';
                    }
                }
            }
        }

        // Handle hidden fields
        foreach ( $this->settings['hidden_fields'] as $field_key ) {
            list( $section, $field ) = $this->parse_field_key( $field_key );
            
            if ( isset( $fields[ $section ][ $field ] ) ) {
                unset( $fields[ $section ][ $field ] );
            }
        }

        // Handle optional fields
        foreach ( $this->settings['optional_fields'] as $field_key ) {
            list( $section, $field ) = $this->parse_field_key( $field_key );
            
            if ( isset( $fields[ $section ][ $field ] ) ) {
                $fields[ $section ][ $field ]['required'] = false;
                
                // Remove asterisk and abbreviation tag from label if present
                if ( isset( $fields[ $section ][ $field ]['label'] ) ) {
                    $fields[ $section ][ $field ]['label'] = preg_replace(
                        '/<abbr[^>]*>.*?<\/abbr>/i',
                        '',
                        $fields[ $section ][ $field ]['label']
                    );
                    $fields[ $section ][ $field ]['label'] = str_replace( 
                        [ ' *', '*' ], 
                        '', 
                        trim( $fields[ $section ][ $field ]['label'] )
                    );
                }
            }
        }

        return $fields;
    }

    /**
     * Customize default address fields
     */
    public function customize_address_fields( $fields ) {
        if ( ! $this->settings['enabled'] ) {
            return $fields;
        }

        // Check for address_2 in hidden fields
        if ( in_array( 'billing_address_2', $this->settings['hidden_fields'] ) || 
             in_array( 'shipping_address_2', $this->settings['hidden_fields'] ) ) {
            if ( isset( $fields['address_2'] ) ) {
                unset( $fields['address_2'] );
            }
        }

        // Check for company in hidden fields
        if ( in_array( 'billing_company', $this->settings['hidden_fields'] ) || 
             in_array( 'shipping_company', $this->settings['hidden_fields'] ) ) {
            if ( isset( $fields['company'] ) ) {
                unset( $fields['company'] );
            }
        }
        
        // Check for optional fields and make them non-required
        $optional_base_fields = array();
        foreach ( $this->settings['optional_fields'] as $field_key ) {
            // Extract base field name (company, address_2, etc.)
            if ( strpos( $field_key, 'billing_' ) === 0 || strpos( $field_key, 'shipping_' ) === 0 ) {
                $base = substr( $field_key, strpos( $field_key, '_' ) + 1 );
                $optional_base_fields[] = $base;
            }
        }
        
        foreach ( $optional_base_fields as $base_field ) {
            if ( isset( $fields[ $base_field ] ) ) {
                $fields[ $base_field ]['required'] = false;
            }
        }

        return $fields;
    }
    
    /**
     * Customize billing fields specifically
     */
    public function customize_billing_fields( $fields ) {
        if ( ! $this->settings['enabled'] ) {
            return $fields;
        }
        
        return $this->apply_field_customizations( $fields, 'billing' );
    }
    
    /**
     * Customize shipping fields specifically
     */
    public function customize_shipping_fields( $fields ) {
        if ( ! $this->settings['enabled'] ) {
            return $fields;
        }
        
        return $this->apply_field_customizations( $fields, 'shipping' );
    }
    
    /**
     * Apply customizations to a specific section's fields
     */
    private function apply_field_customizations( $fields, $section ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }
        
        // Handle hidden fields
        foreach ( $this->settings['hidden_fields'] as $field_key ) {
            list( $field_section, $full_key ) = $this->parse_field_key( $field_key );
            
            if ( $field_section === $section ) {
                unset( $fields[ $full_key ] );
            }
        }
        
        // Handle optional fields - FORCE required to false
        foreach ( $this->settings['optional_fields'] as $field_key ) {
            list( $field_section, $full_key ) = $this->parse_field_key( $field_key );
            
            if ( $field_section === $section && isset( $fields[ $full_key ] ) ) {
                $fields[ $full_key ]['required'] = false;
                
                // Remove asterisk and abbreviation tag from label
                if ( isset( $fields[ $full_key ]['label'] ) ) {
                    $fields[ $full_key ]['label'] = preg_replace(
                        '/<abbr[^>]*>.*?<\/abbr>/i',
                        '',
                        $fields[ $full_key ]['label']
                    );
                    $fields[ $full_key ]['label'] = str_replace( 
                        [ ' *', '*' ], 
                        '', 
                        trim( $fields[ $full_key ]['label'] )
                    );
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Customize individual field args (catches fields rendered one by one)
     */
    public function customize_individual_field( $args, $key, $value ) {
        if ( ! $this->settings['enabled'] ) {
            return $args;
        }
        
        // Check if this field should be hidden (shouldn't be called, but just in case)
        if ( in_array( $key, $this->settings['hidden_fields'] ) ) {
            return false; // Return false to skip rendering
        }
        
        // Check if this field should be optional - FORCE it
        if ( in_array( $key, $this->settings['optional_fields'] ) ) {
            $args['required'] = false;
            
            // Remove asterisk and abbreviation tag from label
            if ( isset( $args['label'] ) ) {
                $args['label'] = preg_replace(
                    '/<abbr[^>]*>.*?<\/abbr>/i',
                    '',
                    $args['label']
                );
                $args['label'] = str_replace( 
                    [ ' *', '*' ], 
                    '', 
                    trim( $args['label'] )
                );
            }
            
            // Also remove 'required' CSS class if present
            if ( isset( $args['class'] ) && is_array( $args['class'] ) ) {
                $args['class'] = array_diff( $args['class'], [ 'validate-required' ] );
            }
        }
        
        return $args;
    }

    /**
     * Adjust Store API schema so hidden/optional fields behave correctly on block checkout.
     */
    public function customize_store_api_schema( $schema ) {
        if ( ! $this->settings['enabled'] || ! is_array( $schema ) || ! isset( $schema['routes'] ) ) {
            return $schema;
        }

        foreach ( $schema['routes'] as $route => &$route_schema ) {
            if ( ! is_array( $route_schema ) || ! isset( $route_schema['schema']['properties'] ) ) {
                continue;
            }

            $properties = &$route_schema['schema']['properties'];

            $this->maybe_adjust_address_schema( $properties, 'billing', 'billing_address' );
            $this->maybe_adjust_address_schema( $properties, 'shipping', 'shipping_address' );
        }

        return $schema;
    }

    /**
     * Remove hidden fields and relax required rules for optional fields in Store API address schemas.
     */
    private function maybe_adjust_address_schema( array &$properties, $section, $schema_key ) {
        if ( ! isset( $properties[ $schema_key ]['properties'] ) ) {
            return;
        }

        $address_props = &$properties[ $schema_key ]['properties'];
        $required      = isset( $properties[ $schema_key ]['required'] ) && is_array( $properties[ $schema_key ]['required'] )
            ? $properties[ $schema_key ]['required']
            : array();

        // Hidden fields: drop from properties and required
        foreach ( $this->settings['hidden_fields'] as $field_key ) {
            list( $field_section, $full_key ) = $this->parse_field_key( $field_key );
            if ( $field_section !== $section ) {
                continue;
            }

            $prop_name = $this->field_key_to_property( $full_key );
            unset( $address_props[ $prop_name ] );
            $required = array_diff( $required, array( $prop_name ) );
        }

        // Optional fields: keep but ensure not required
        foreach ( $this->settings['optional_fields'] as $field_key ) {
            list( $field_section, $full_key ) = $this->parse_field_key( $field_key );
            if ( $field_section !== $section ) {
                continue;
            }

            $prop_name = $this->field_key_to_property( $full_key );
            $required  = array_diff( $required, array( $prop_name ) );
            if ( isset( $address_props[ $prop_name ] ) ) {
                $address_props[ $prop_name ]['required'] = false;
            }
        }

        $properties[ $schema_key ]['required'] = array_values( $required );
    }

    /**
     * Convert a full field key (billing_first_name) to the Store API property name (first_name).
     */
    private function field_key_to_property( $full_key ) {
        if ( strpos( $full_key, 'billing_' ) === 0 ) {
            return substr( $full_key, 8 );
        }
        if ( strpos( $full_key, 'shipping_' ) === 0 ) {
            return substr( $full_key, 9 );
        }
        return $full_key;
    }

    /**
     * Parse field key into section and field name
     */
    private function parse_field_key( $field_key ) {
        $parts = explode( '_', $field_key, 2 );
        
        if ( count( $parts ) === 2 ) {
            return [ $parts[0], $field_key ];
        }
        
        return [ 'billing', $field_key ];
    }

    /**
     * Maybe save settings from admin page
     */
    public function maybe_save_settings() {
        if ( ! isset( $_POST['checkoutguard_field_nonce'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['checkoutguard_field_nonce'], 'checkoutguard_save_fields' ) ) {
            return;
        }

        $new_settings = [
            'enabled' => isset( $_POST['checkoutguard_field_manager_enabled'] ) && $_POST['checkoutguard_field_manager_enabled'] === '1',
            'hidden_fields' => isset( $_POST['hidden_fields'] ) && is_array( $_POST['hidden_fields'] ) 
                ? array_map( 'sanitize_text_field', $_POST['hidden_fields'] ) 
                : [],
            'optional_fields' => isset( $_POST['optional_fields'] ) && is_array( $_POST['optional_fields'] ) 
                ? array_map( 'sanitize_text_field', $_POST['optional_fields'] ) 
                : [],
            'custom_labels' => isset( $_POST['custom_labels'] ) && is_array( $_POST['custom_labels'] ) 
                ? array_map( 'sanitize_text_field', $_POST['custom_labels'] ) 
                : [],
        ];

        // Remove empty custom labels
        $new_settings['custom_labels'] = array_filter( $new_settings['custom_labels'] );

        update_option( 'checkoutguard_field_manager_settings', $new_settings );
        $this->settings = $new_settings;

        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Checkout field settings saved successfully!', 'checkoutguard' ) . '</p></div>';
        });
    }

    /**
     * Get all available fields
     */
    public function get_available_fields() {
        $fields = $this->default_fields;
        
        // Allow pro version to reorder fields
        $fields = apply_filters('checkoutguard_field_manager_fields', $fields);
        
        return $fields;
    }

    /**
     * Get current settings
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get statistics
     */
    public function get_statistics() {
        $total_fields = 0;
        $hidden_count = count( $this->settings['hidden_fields'] );
        $optional_count = count( $this->settings['optional_fields'] );
        
        foreach ( $this->default_fields as $section => $fields ) {
            $total_fields += count( $fields );
        }
        
        $active_fields = $total_fields - $hidden_count;
        
        return [
            'total_fields' => $total_fields,
            'active_fields' => $active_fields,
            'hidden_fields' => $hidden_count,
            'optional_fields' => $optional_count,
            'reduction_percentage' => $total_fields > 0 ? round( ( $hidden_count / $total_fields ) * 100, 1 ) : 0,
        ];
    }
}
