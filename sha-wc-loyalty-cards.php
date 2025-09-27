<?php
/*
 * Plugin Name:       Woocommerce Loyalty Cards
 * Description:       Allow users use loyalty card codes
 * Version:           0.1.0
 * Author:            Andrew Sh
 * Text Domain:       sha-wlc
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class SHA_WC_Loyalty_Cards {

    private static $_instance;

	protected $_plugin_version = '0.1.0';

	protected $_plugin_slug = 'wclc';

	protected $_prefix = 'wclc_';

	protected $_card_prefix = 'CARD-';

	protected $_allow_multiple = false;

	protected $_batch_size = 10;

    public static function get_instance() {

        if ( ! isset( self::$_instance ) ) {
            self::$_instance = new SHA_WC_Loyalty_Cards;
            self::$_instance->init();
        }

        return self::$_instance;
    }

    private function init() {
		$this->init_hooks();
    }

	// Initing all admin actions and filters
	private function init_hooks() {

        $plugin_slug = $this->_plugin_slug;

		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_variables' ) );
        add_action( 'enter_title_here', array( $this, 'change_placeholder' ) );
        add_action( 'admin_init', array( $this, 'add_meta_fields' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
        add_action( 'save_post_' . $plugin_slug, array( $this, 'on_save_post' ), 10, 2 );
        add_action( 'manage_' . $plugin_slug . '_posts_custom_column', array( $this, 'columns_content_in_admin_grid' ), 10, 4 );
        add_action( 'woocommerce_product_data_panels', array( $this, 'card_options_product_tab_content' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_custom_product_tab_data' ) );
        add_action( 'admin_post_wclc_export_cards', array( $this, 'export_handler' ) );

        add_action( 'wp_ajax_wclc_generate_cards', array( $this, 'generate_cards' ) );
        add_action( 'wp_ajax_wclc_upload_csv', array( $this, 'upload_csv' ) );
        add_action( 'wp_ajax_wclc_process_csv_file', array( $this, 'process_csv_file' ) );
        add_action( 'wp_ajax_wclc_delete_tmp_file', array( $this, 'delete_tmp_file' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

        add_filter( 'wp_insert_post_data', array( $this, 'prevent_duplicated_cards' ), 10, 2 );
        add_filter( 'manage_' . $plugin_slug . '_posts_columns', array( $this, 'add_colums_to_admin_grid' ) );
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_card_tab_to_product' ) );
        add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );
        add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'cards_proccessor' ), 10, 2 );
        add_filter( 'wp_insert_post_data', array( $this, 'set_card_prefix_before_save' ), 10, 2 );
    }

    // Register custom post type
	public function register_cpt() {

        $labels = array(
            'name'					=> __( 'Loyalty cards', 'sha-wclc' ),
            'all_items'				=> __( 'All Cards', 'sha-wclc' ),
            'singular_name' 		=> __( 'Loyalty card', 'sha-wclc' ),
            'add_new'				=> __( 'Add New Card', 'sha-wclc' ),
            'add_new_item'			=> __( 'Add New Card', 'sha-wclc' ),
            'edit'					=> __( 'Edit Card', 'sha-wclc' ),
            'edit_item'				=> __( 'Edit Card', 'sha-wclc' ),
            'new_item'				=> __( 'New Card', 'sha-wclc' ),
            'view'					=> __( 'View Card', 'sha-wclc' ),
            'view_item'				=> __( 'View Card', 'sha-wclc' ),
            'search_items'			=> __( 'Search Cards', 'sha-wclc' ),
            'not_found'				=> __( 'No Cards found', 'sha-wclc' ),
            'not_found_in_trash'	=> __( 'No Cards found in Trash', 'sha-wclc' ),
            'parent'				=> __( 'Parent Card', 'sha-wclc' ),
        );

        $labels = apply_filters( 'sha_wclc_cpt_labels', $labels );

        register_post_type( $this->_plugin_slug,
            array(
                'labels' 				=>	$labels,
                'public' 				=>	true,
                'publicly_queryable'	=>	false,
                'menu_position'			=>	29,
                'supports'				=>	array(
                    'title',
                ),
                'menu_icon'				=> 'dashicons-tickets-alt',
                'has_archive'			=> false
            )
        );
    }

    // Load textdomain
	public function load_textdomain() {

		load_plugin_textdomain( 'sha-wclc', false, basename( dirname( __FILE__, 1 ) ) . '/languages' );
	}

	// Initing all variables
	public function init_variables() {

        $options = get_option( $this->_prefix . 'settings', array() );
        $this->_card_prefix = $options['card_prefix'] ?? $this->_card_prefix;
        $this->_allow_multiple = (bool) ( $options['allow_multiple'] ?? $this->_allow_multiple );
        $this->_batch_size = (int) ( $options['batch_size'] ?? $this->_batch_size );

	}

    // Change placeholder text
	public function change_placeholder( $title ) {

		$screen = get_current_screen();

		if  ( is_admin() && ( $this->_plugin_slug == $screen->post_type ) ) {
			$title = __( 'Enter Loyalty Card number', 'sha-wclc' );
		}

		return $title;
	}

    // Show discount amount and user id metaboxes on cpt edit page
    public function add_meta_fields() {

        add_meta_box(
            'sha-wlc-card-metbox',
            __( 'Card data', 'sha-wclc' ),
            array( $this, 'card_metabox_html' ),
            $this->_plugin_slug,
            'normal',
            'high'
        );
    }

    public function register_settings() {

        $prefix = $this->_prefix;

        register_setting(
            $prefix . 'settings_group',
            $prefix . 'settings'
        );

        add_settings_section(
            $prefix . 'main_section',
            '',
            '__return_false',
            $prefix . 'settings'
        );

        // All settings fields
        $fields = array(
            array(
                'id'            => 'batch_size',
                'label'         => __( 'Batch Size', 'sha-wclc' ),
                'description'   => __( 'Amount of cards, generated/imported per iteration', 'sha-wclc' ), 
                'type'          => 'number',
                'renderer'      => array( $this, 'render_text_field' )
            ),
            array(
                'id'            => 'card_prefix',
                'label'         => __( 'Card Prefix', 'sha-wclc' ),
                'description'   => __( 'To prevent duplication with classic WooCommerce coupons, all loyalty cards shoud starts with prefix [card-*****], for example', 'sha-wclc' ), 
                'type'          => 'text',
                'renderer'      => array( $this, 'render_text_field' )
            ),
            array(
                'id'            => 'allow_multiple',
                'label'         => __( 'Allow multiple cards per user', 'sha-wclc' ),
                'description'   => __( 'Allow single user has multiple cards', 'sha-wclc' ), 
                'type'          => 'checkbox',
                'renderer'      => array( $this, 'render_checkbox_field' )
            )
        );

        foreach ( $fields as $field ) {
            add_settings_field(
                $field['id'],
                $field['label'],
                $field['renderer'],
                $prefix . 'settings',
                $prefix . 'main_section',
                $field
            );
        }
    }

    // Add user id field to new post type form
    public function card_metabox_html( $item_data ) {

        $prefix = $this->_prefix;
        $user_id = get_post_meta( $item_data->ID, $prefix . 'user_id', true );
        $discount = get_post_meta( $item_data->ID, $prefix . 'discount', true );

        $user_id = ! empty( $user_id ) ? (int) $user_id : 0;
        $discount = ! empty( $discount ) ? (int) $discount : '';
        $users_with_discounts = '';

        // Allow only 1 card per user
        if ( ! $this->_allow_multiple ) {
            $linked_users = get_posts(
                array(
                    'post_type'      => $this->_plugin_slug,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => $prefix . 'user_id',
                            'compare' => 'EXISTS',
                        )
                    )
                )
            );

            $linked_user_ids = array();

            foreach ( $linked_users as $post_id ) {
                $uid = get_post_meta( $post_id, $prefix . 'user_id', true );
                if ( ( (int) $uid > 0 ) && ( $user_id != $uid ) ) {
                    $linked_user_ids[] = (int) $uid;
                }
            }

            $users_with_discounts = implode( ',', $linked_user_ids );
        }

        $users_dropdown = wp_dropdown_users(
            array(
                'show_option_none'  => __( '— Choose User —', 'sha-wclc' ),
                'echo'              => false,
                'id'                => 'user-select',
                'name'              => $prefix . 'user_id',
                'exclude'           => $users_with_discounts,
                'selected'          => $user_id
            )
        );

        if ( empty( $users_dropdown ) ) {
            $users_dropdown = __( 'No available users', 'sha-wclc' );
        }

        wp_nonce_field( 'wclc_save_metabox', 'wclc_nonce' );

        echo $this->get_module_template(
            'admin/templates/metabox.phtml',
            array(
                'prefix'            => $prefix,
				'users_dropdown'    => $users_dropdown,
				'discount'          => $discount
			)
        );
    }

    // Save/update post handler
    public function on_save_post( $post_id, $post_data ) {

        // Nonce check
        if ( ! isset( $_POST['wclc_nonce'] ) ||
             ! wp_verify_nonce( $_POST['wclc_nonce'], 'wclc_save_metabox' ) ) {
            return;
        }

        // Users rights check
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Skipping on autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $prefix = $this->_prefix;

		// Update user id
		if ( isset( $_POST[ $prefix . 'user_id' ] ) ) {
            if ( ! $this->_allow_multiple ) {

                // Collect all post with current user_id
                $args = array(
                    'post_type'      => $this->_plugin_slug,
                    'post_status'    => 'any',
                    'meta_key'       => $prefix . 'user_id',
                    'meta_value'     => (int) $_POST[ $prefix . 'user_id' ],
                    'posts_per_page' => -1,
                    'fields'         => 'ids'
                );

                $posts = get_posts( $args );

                // Remove all meta with given user_id
                foreach ( $posts as $p_id ) {
                    delete_post_meta( $p_id, $prefix . 'user_id' );
                }
            }
            update_post_meta( $post_id, $prefix . 'user_id', (int) $_POST[ $prefix . 'user_id' ] );
		}
		
		// Update discount amount
		if ( isset( $_POST[ $this->_prefix . 'discount' ] ) ) {
			update_post_meta( $post_id, $prefix . 'discount', (int) $_POST[ $prefix . 'discount' ] );
		}
	}

    // Export handler
    public function export_handler() {

        // Capabilities check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Not allowed', 'sha-wclc' ) );
        }

        // Nonce check
        check_admin_referer( 'wclc_export_csv', 'wclc_export_nonce' );

        ob_clean();
        
        $output = fopen( 'php://output', 'w' );
        
        fputcsv( $output,
            array(
                'ID',
                'Card number',
                'User email',
                'Discount'
            )
        );
        
        // Get all existing cards
        $cards = get_posts(
            array(
                'post_type'			=> $this->_plugin_slug,
                'posts_per_page'	=> -1,
            )
        );

        $prefix = $this->_prefix;
        
        foreach ( $cards as $card ) {
            $user_id = (int) get_post_meta( $card->ID, $prefix . 'user_id', true );
            $user_discount = (int) get_post_meta( $card->ID, $prefix . 'discount', true );
            $user_email = '';
            
            if ( $user_id > 0 ) {
                $user_data = get_userdata( $user_id );
                $user_email = $user_data->user_email;
            }
            
            $row = array( 
                $card->ID,
                $card->post_title,
                $user_email,
                $user_discount
            );
            
            fputcsv( $output, $row );
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . sprintf( 'export_cards_%s.csv', date( 'd-m-Y_H-i' ) ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        exit;
    }

    // Cards generation AJAX handler
    public function generate_cards() {

        $prefix = $this->_prefix;

        // Nonce check
        check_admin_referer( $prefix . 'generate_cards', $prefix . 'nonce' );

        // Data validation
        $amount = (int) ( $_POST['amount'] ?? 0 );
        $card_length = (int) ( $_POST['card_length'] ?? 0 );
        $offset = (int) ( $_POST['offset'] ?? 0 );
        $generation_rule = isset( $_POST['generation_rule'] ) ? sanitize_text_field( $_POST['generation_rule'] ) : 'numbers';

        if ( empty( $amount ) || $amount <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid amount', 'sha-wclc' ) ) );
        }
        if ( $card_length < 6 ) {
            wp_send_json_error( array( 'message' => __( 'Card length must be at least 6 characters', 'sha-wclc' ) ) );
        }

        switch ( $generation_rule ) {
            case 'letters':
                $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            break;

            case 'mixed':
                $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            break;

            case 'numbers':
            default:
                $chars = '0123456789';
            break;
        }

        $created = 0;

        for ( $i = 0; $i < $this->_batch_size && ( $offset + $i ) < $amount; $i++ ) {
            $unique = false;
            $tries  = 0;
            $max_tries = 100;

            while ( ! $unique && $tries < $max_tries ) {
                $card_name = '';
                for ( $j = 0; $j < $card_length; $j++ ) {
                    $card_name .= $chars[ rand( 0, strlen( $chars ) - 1 ) ];
                }

                $card_name = $this->_card_prefix . $card_name;

                $existing = get_page_by_path( sanitize_title( $card_name ), OBJECT, $this->_plugin_slug );
                if ( ! $existing ) {
                    $unique = true;
                }
                $tries++;
            }

            if ( ! $unique ) {
                continue;
            }

            wp_insert_post( [
                'post_title'  => $card_name,
                'post_name'   => sanitize_title( $card_name ),
                'post_status' => 'publish',
                'post_type'   => $this->_plugin_slug,
            ] );

            $created++;
        }

        $next_offset = $offset + $this->_batch_size;

        wp_send_json_success(
            array(
                'created'       => $created,
                'next_offset'   => $next_offset,
                'total'         => $amount,
                'done'          => $next_offset >= $amount,
            )
        );
    }

    // Upload csv import file to server
    public function upload_csv() {

        $prefix = $this->_prefix;

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'sha-wclc' ) );
        }

        // Verify nonce
        check_admin_referer( $prefix . 'import_csv', $prefix . 'import_nonce' );

        // Ensure file is uploaded
        if ( empty( $_FILES[ $prefix . 'import_file' ]['tmp_name'] ) ) {
            wp_send_json_error( __( 'No file uploaded.', 'sha-wclc' ) );
        }

        // Allowed MIME types for CSV
        $allowed_mimes = array(
            'csv' => 'text/csv',
        );

        // Handle file upload using WordPress API
        $uploaded_file = wp_handle_upload(
            $_FILES[ $prefix . 'import_file'],
            array(
                'mimes'     => $allowed_mimes,
                'test_form' => false,
            )
        );

        // Check for upload errors
        if ( isset( $uploaded_file['error'] ) ) {
            wp_send_json_error(
                sprintf(
                    __( 'Upload failed: %s', 'sha-wclc' ),
                    $uploaded_file['error']
                )
            );
        }

        $tmp_file = $uploaded_file['file'];

        // Count CSV rows excluding header
        $total_rows = 0;
        if ( ( $handle = fopen( $tmp_file, 'r' ) ) ) {
            while ( fgetcsv( $handle ) ) {
                $total_rows++;
            }
            fclose( $handle );
            $total_rows--; // exclude header row
        }

        // Return JSON response with upload details
        wp_send_json_success(
            array(
                'tmp_file'   => $tmp_file,
                'total_rows' => $total_rows,
                'batch_size' => $this->_batch_size,
            )
        );
    }

    // Import batch of csv data
    public function process_csv_file() {

        $prefix = $this->_prefix;

        // Verify nonce
        check_admin_referer( $prefix . 'import_csv', $prefix . 'import_nonce' );

        // Sanitize and get POST data
        $tmp_file    = isset( $_POST['tmp_file'] ) ? sanitize_text_field( wp_unslash( $_POST['tmp_file'] ) ) : '';
        $batch_index = (int) ( $_POST['batch_index'] ?? 0 );
        $batch_size = $this->_batch_size;

        if ( ! file_exists( $tmp_file ) ) {
            wp_send_json_error( __( 'Temp file not found.', 'sha-wclc' ) );
        }

        $inserted = $updated = $skipped = 0;

        $start_line = $batch_index * $batch_size + 1; // Skip header
        $end_line   = $start_line + $batch_size - 1;

        // Open CSV file
        $handle = fopen( $tmp_file, 'r' );
        if ( ! $handle ) {
            wp_send_json_error( __( 'Cannot open file.', 'sha-wclc' ) );
        }

        $current_line = 0;
        $rows         = array();

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $current_line++;

            if ( $current_line === 1 ) {
                continue; // Skip header
            }

            if ( $current_line < $start_line ) {
                continue;
            }

            if ( $current_line > $end_line ) {
                break;
            }

            $rows[] = $data;
        }

        fclose( $handle );

        foreach ( $rows as $row ) {

            list( $id, $card_number, $user_email, $discount ) = $row;

            $id         = (int) $id;
            $card_title = sanitize_text_field( $card_number );
            $discount   = (int) $discount;

            // Get user ID by email
            $user_id = 0;
            if ( ! empty( $user_email ) ) {
                $user = get_user_by( 'email', sanitize_email( $user_email ) );
                if ( $user ) {
                    $user_id = (int) $user->ID;
                }
            }

            // Check if post with this title already exists
            $existing_title = get_page_by_title( $card_title, OBJECT, $this->_plugin_slug );

            // New post: skip if title is taken
            if ( $id === 0 && $existing_title ) {
                $skipped++;
                continue;
            }

            // Update existing post: skip if title is taken by another post
            if ( $id > 0 && $existing_title && (int) $existing_title->ID !== $id ) {
                $skipped++;
                continue;
            }

            // Check allow_multiple for user_id
            $user_to_set = $user_id;
            if ( ! $this->_allow_multiple && $user_id > 0 ) {
                $args = array(
                    'post_type'   => $this->_plugin_slug,
                    'post_status' => 'any',
                    'meta_query'  => array(
                        array(
                            'key'     => $prefix . 'user_id',
                            'value'   => $user_id,
                            'compare' => '='
                        )
                    ),
                    'numberposts' => 1,
                );

                $existing_user = get_posts( $args );

                if ( ! empty( $existing_user ) && ( $id === 0 || (int) $existing_user[0]->ID !== $id ) ) {
                    // User already assigned → do not set user_id
                    $user_to_set = 0;
                    delete_post_meta( $id, $prefix . 'user_id' );
                }
            }

            // Insert or update post
            if ( $id > 0 && get_post( $id ) ) {

                wp_update_post(
                    array(
                        'ID'         => $id,
                        'post_title' => $card_title,
                        'post_name'  => sanitize_title( $card_title ),
                    )
                );

                update_post_meta( $id, $prefix . 'discount', $discount );

                if ( $user_to_set > 0 ) {
                    update_post_meta( $id, $prefix . 'user_id', $user_to_set );
                }

                $updated++;

            } else {

                $new_id = wp_insert_post(
                    array(
                        'post_title'  => $card_title,
                        'post_name'   => sanitize_title( $card_title ),
                        'post_status' => 'publish',
                        'post_type'   => $this->_plugin_slug,
                    )
                );

                if ( $new_id && ! is_wp_error( $new_id ) ) {
                    update_post_meta( $new_id, $prefix . 'discount', $discount );

                    if ( $user_to_set > 0 ) {
                        update_post_meta( $new_id, $prefix . 'user_id', $user_to_set );
                    }

                    $inserted++;
                }
            }
        }

        // Check if we reached the end of file
        $total_lines = count( file( $tmp_file ) ) - 1; // Minus header
        $finished    = ( $end_line >= $total_lines );

        wp_send_json_success(
            array(
                'inserted' => $inserted,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'finished' => $finished,
            )
        );
    }

    // Delete csv file after proccessing
    public function delete_tmp_file() {

        $prefix = $this->_prefix;

        // Verify nonce
        check_admin_referer( $prefix . 'import_csv', $prefix . 'import_nonce' );

        // Sanitize input
        $tmp_file = isset( $_POST['tmp_file'] ) ? sanitize_text_field( wp_unslash( $_POST['tmp_file'] ) ) : '';

        if ( empty( $tmp_file ) ) {
            wp_send_json_error( __( 'Missing file path.', 'sha-wclc' ) );
        }

        // Check and delete file
        if ( file_exists( $tmp_file ) ) {
            unlink( $tmp_file );
        }

        // Send JSON response
        wp_send_json_success();
    }

    // Frontend cards proccessor
    public function cards_proccessor( $true, $data ) {

        $prefix = $this->_prefix;

        // Skip non-card coupons
        if ( ! $this->str_starts_with( $data, $this->_card_prefix ) ) {
            return $true;
        }

        // Search loyalty card
        $card = sanitize_title( $data );
        $card = get_page_by_path( $card, OBJECT, $this->_plugin_slug );
        if ( ! $card || ( $card->post_status !== 'publish' ) ) {
            return $true;
        }

        // Skip if user not match
        $post_id = (int) $card->ID;
        $user_id = (int) get_post_meta( $post_id, $prefix . 'user_id', true );
        if ( $user_id !== (int) get_current_user_id() ) {
            return $true;
        }

        // Skip if discount not set or zero
        $discount = (int) get_post_meta( $post_id, $prefix . 'discount', true );
        if ( $discount <= 0 ) {
            return $true;
        }

        // Include products, excluded from discounts
        $cart_items = WC()->cart->get_cart();
        $cart_items_ids = array();
        foreach ( $cart_items as $cart_item_key => $cart_item ) {
            $cart_items_ids[] = $cart_item['product_id'];
        }

        $excluded_product_ids = get_posts(
            array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post__in'       => $cart_items_ids,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'       => $prefix . 'exclude_from_discount',
                        'value'     => '1',
                        'compare'   => '='
                    )
                )
            )
        );

        $data = array(
            'code'                  => $data,
            'amount'                => $discount,
            'discount_type'         => 'percent',
            'individual_use'        => true,
            'excluded_product_ids'  => $excluded_product_ids,
            'virtual'               => true,
        );

        return $data;
    }

    // Prevent post creation with same titles
    public function prevent_duplicated_cards( $data, $postarr ) {

        if ( $data['post_type'] !== $this->_plugin_slug ) {
            return $data;
        }

        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
            return $data;
        }

        $card_prefix = $this->_card_prefix;
        $post_title = (string) $data['post_title'];
        $slug = sanitize_title( $post_title );

        if ( ! $this->str_starts_with( $post_title, $card_prefix ) ) {
            $slug = strtoupper( $card_prefix . $slug );
        }

        $existing = get_page_by_path( $slug, OBJECT, 'wclc' );
        $post_id = (int) ( $postarr['ID'] ?? 0 );

        if ( $existing && ( $data['post_status'] == 'publish' ) && ( $existing->ID != $post_id ) ) {
            wp_die(
                __('Error: This card already exists!', 'sha-wclc'),
                __('Error', 'sha-wclc'),
                array(
                    'link_text'     => __( '&laquo; Back', 'sha-wclc' ),
                    'link_url'      => admin_url( 'post-new.php?post_type=' . $this->_plugin_slug )
                )
            );
        }

        return $data;
    }

    // Set card prefix before save post
    public function set_card_prefix_before_save( $data, $postarr ) {

        if ( $data['post_type'] !== $this->_plugin_slug ) {
            return $data;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $data;
        }

        if ( $data['post_status'] == 'auto-draft' ) {
            return $data;
        }

        $card_prefix = $this->_card_prefix;
        $post_title = (string) $data['post_title'];
        $post_title = trim( $post_title );

        if ( ! $this->str_starts_with( $post_title, $card_prefix ) ) {
            $data['post_title'] = strtoupper( $card_prefix . $post_title );
            $data['post_name'] = sanitize_title( $data['post_title'] );
        }

        return $data;

    }

    // Change core messages for custom post type
    public function post_updated_messages( $messages ) {

        global $post;

        $messages[ $this->_plugin_slug ] = array(
            0  => '',
            1  => __( 'Loyalty card updated successfully.', 'sha-wclc' ),
            2  => __( 'Custom field updated.', 'sha-wclc' ),
            3  => __( 'Custom field deleted.', 'sha-wclc' ),
            4  => __( 'Loyalty card updated.', 'sha-wclc' ),
            5  => isset($_GET['revision']) ? sprintf( __('Loyalty card restored to revision from %s', 'sha-wclc'), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
            6  => __( 'Loyalty card published.', 'sha-wclc' ),
            7  => __( 'Loyalty card saved.', 'sha-wclc' ),
            8  => __( 'Loyalty card submitted.', 'sha-wclc' ),
            9  => __( 'Loyalty card scheduled for: ', 'sha-wclc' ) . date_i18n( __( 'M j, Y @ H:i', 'sha-wclc' ), strtotime( $post->post_date ) ),
            10 => __( 'Loyalty card draft updated.', 'sha-wclc' ),
        );

        return $messages;
    }

    // Add user and discount column to admin grid
    public function add_colums_to_admin_grid( $defaults ) {

        $prefix = $this->_prefix;
        unset( $defaults['date'] );

        $defaults['title'] = __( 'Loyalty Card', 'sha-wclc' );
        $defaults[ $prefix . 'user_id' ] = __( 'User ID', 'sha-wclc' );
        $defaults[ $prefix . 'discount' ] = __('Discount (%)', 'sha-wclc' );

        return $defaults;
    }

    // Add User and Discount columns in admin grid 
    public function columns_content_in_admin_grid( $column_name, $post_ID ) {
            
        $prefix = $this->_prefix;

        // Show user id value
        if ( $column_name == $prefix . 'user_id' ) {
            $user_id = (int) get_post_meta( $post_ID , $prefix . 'user_id', true );
            if ( $user_id > 0 ) {
                $user_data = get_user_by( 'id', $user_id );
                esc_html_e( $user_data->user_email );
            } else {
                echo '&mdash;';
            }
        }
        
        // Show discount value
        if ( $column_name == $prefix . 'discount' ) {
            $discount = (float) get_post_meta( $post_ID , $prefix . 'discount', true );
            if ( $discount > 0 ) {
                echo $discount;
            } else {
                echo '&mdash;';
            }
        }
    }

    // Add a loyalty card product tab.
    public function add_card_tab_to_product( $tabs ) {
        
        $css_classes = apply_filters( 'sha_wclc_product_tab_classes', array( 'wc_loyalty_cards', 'show_if_grouped', 'show_if_simple', 'show_if_variable' ) ); 
        $tabs['loyaltycard'] = array(
            'label'		=> __( 'Loyalty card', 'sha-wclc' ),
            'priority'	=> 100,
            'target'	=> 'wclc_options',
            'class'		=> $css_classes,
        );
        
        return $tabs;
    }

    // Output checkbox in loyalty card tab
    public function card_options_product_tab_content() {

        $product_id = get_the_ID();
        $prefix = $this->_prefix;

        echo $this->get_module_template(
            'admin/templates/panel.phtml',
            array(
                'checked'       => (int)get_post_meta( $product_id, $prefix . 'exclude_from_discount', true ),
                'prefix'        => $prefix,
                'product_id'    => $product_id
            )
        );
    }

    // Save checkbox in meta
    public function save_custom_product_tab_data( $post_id ) {

        $prefix = $this->_prefix;
        if ( isset( $_POST[ $prefix . 'exclude_from_discount' ] ) ) {
            update_post_meta( $post_id, $prefix . 'exclude_from_discount', (int) $_POST[ $prefix . 'exclude_from_discount' ] );
        }
    }

    // Enqueue admin styles and scripts
    public function enqueue_admin_styles( $hook ) {

        $plugin_slug = $this->_plugin_slug;
        $allowed_hooks = array(
            'wclc_page_generate_page',
            'wclc_page_import_page',
            'post-new.php',
            'edit.php'
        );

        if ( ! in_array( $hook, $allowed_hooks, true ) && isset( $_GET['post_type'] ) && ( $_GET['post_type'] !== $plugin_slug ) ) {
            return;
        }

        // Disable autosave
        if ( $plugin_slug == get_post_type() ) {
            wp_dequeue_script( 'autosave' );
        }

		wp_enqueue_script( $plugin_slug . '-admin', plugin_dir_url( __FILE__ ) . 'admin/js/scripts.js', array( 'jquery' ), $this->_plugin_version, true );
        wp_localize_script(
            $plugin_slug . '-admin',
            'wclc',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'prefix'            => $this->_prefix,
                'generation_nonce'  => wp_create_nonce( 'wclc_generate_cards' ),
                'import_nonce'      => wp_create_nonce( 'wclc_import_csv' ),
                'export_nonce'      => wp_create_nonce( 'wclc_exmport_csv' ),
            )
        );

		wp_enqueue_style( $plugin_slug . '-admin', plugin_dir_url( __FILE__ ) . 'admin/css/styles.css', '', $this->_plugin_version );
    }

    // Add options pages to admin menu
    public function add_admin_pages() {

        $submenu_pages = array(
            array(
                'page_title' => __( 'Import/Export Cards', 'sha-wclc' ),
                'menu_title' => __( 'Import/Export Cards', 'sha-wclc' ),
                'capability' => 'manage_options',
                'menu_slug'  => 'import_page',
                'callback'   => array( $this, 'import_page_html' ),
            ),
            array(
                'page_title' => __( 'Generate Cards', 'sha-wclc' ),
                'menu_title' => __( 'Generate Cards', 'sha-wclc' ),
                'capability' => 'manage_options',
                'menu_slug'  => 'generate_page',
                'callback'   => array( $this, 'generate_page_html' ),
            ),
            array(
                'page_title' => __( 'Settings', 'sha-wclc' ),
                'menu_title' => __( 'Settings', 'sha-wclc' ),
                'capability' => 'manage_options',
                'menu_slug'  => 'settings_page',
                'callback'   => array( $this, 'settings_page_html' ),
            ),
        );

        foreach ( $submenu_pages as $page ) {
            add_submenu_page(
                'edit.php?post_type=' . $this->_plugin_slug,
                $page['page_title'],
                $page['menu_title'],
                $page['capability'],
                $page['menu_slug'],
                $page['callback']
            );
        }
    }

    // Import/export page html
    public function import_page_html() {
        echo $this->get_module_template(
            'admin/templates/import.phtml',
            array(
                'prefix'    => $this->_prefix,
            )
        );
    }

    // Generate page html
    public function generate_page_html() {
        echo $this->get_module_template(
            'admin/templates/generate.phtml',
            array(
                'prefix'    => $this->_prefix,
            )
        );
    }

    // Settings page html
    public function settings_page_html() {
        echo $this->get_module_template(
            'admin/templates/settings.phtml',
            array(
                'prefix'    => $this->_prefix,
            )
        );
    }

    // Renderer text inputs
    public function render_text_field( $args ) {

        $prefix = $this->_prefix;
        $options = get_option( $prefix . 'settings', array() );
        $args['value'] = $options[ $args['id'] ] ?? '';
        $args['name'] = esc_attr( $prefix ) . 'settings[' . esc_attr( $args['id'] ) . ']';

        echo $this->get_module_template(
            'admin/templates/elements/form-field.phtml',
            $args
        );
    }

    // Renderer checkbox
    public function render_checkbox_field( $args ) {

        $prefix = $this->_prefix;
        $options = get_option( $prefix . 'settings', array() );
        $args['value'] = (int) ( $options[ $args['id'] ] ?? 0 );
        $args['name'] = esc_attr( $prefix ) . 'settings[' . esc_attr( $args['id'] ) . ']';

        echo $this->get_module_template(
            'admin/templates/elements/form-field.phtml',
            $args
        );

    }

    // Get template and output it's html
	private function get_module_template( $template, $args = array(), $global_template = 0 ) {

        if ( $global_template == 1 ) {
            $template_file = $template;
        } else {
            $template_file = sprintf(
                '%s%s',
                plugin_dir_path( __FILE__ ),
                $template
            );
        }

        if ( ! file_exists( $template_file ) ) {
            return '';
        }

        $args['module_slug'] = $this->_plugin_slug;
        extract( $args );

        ob_start();
        require( $template_file );
        return ob_get_clean();
	}

    // PHP < 8 analog of str_starts_with
    private function str_starts_with( $str, $needle ) {
        if ( substr( strtolower( $str ), 0, strlen( $needle ) ) !== strtolower( $needle ) ) {
            return false;
        }

        return true;
    }
}

// Init module instance
function init_wc_lc_module() {

    return SHA_WC_Loyalty_Cards::get_instance();
}

add_action( 'plugins_loaded', 'init_wc_lc_module', 100 );
