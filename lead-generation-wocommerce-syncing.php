<?php
/*
Plugin Name: WooCommerce Lead Generator
Description: Allows individuals to submit a project that becomes a WooCommerce product, with information hidden until purchase.
Version: 1.0
Author: Ali zaib
*/

if (!defined('ABSPATH')) exit;

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', 'wc_lead_generator_scripts');
function wc_lead_generator_scripts() {
    wp_enqueue_style('wc-lead-generator', plugins_url('wc-lead-generator.css', __FILE__));
    wp_enqueue_script('wc-lead-generator', plugins_url('wc-lead-generator.js', __FILE__), array('jquery'), '1.0', true);
    wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap', [], null);
    wp_enqueue_script('wc-add-to-cart-variation'); // Ensure WooCommerce variation script is loaded

    // Add inline CSS for single product page and shop page styling
      $custom_css = "
        .woocommerce ul.products li.product .lead-details {
            border: 1px solid #ddd;
            padding: 5px;
            margin: 5px 0;
            font-size: 12px;
        }
        .woocommerce ul.products li.product .lead-location {
            color: #e53e3e;
            font-size: 12px;
            font-weight: bold;
        }
        .woocommerce ul.products li.product .lead-title {
            font-size: 16px;
            font-weight: bold;
            margin: 5px 0;
        }
        .woocommerce ul.products li.product .lead-price {
            font-size: 14px;
            color: #000;
        }
        .woocommerce ul.products li.product .lead-stock {
            color: #e53e3e;
            font-size: 12px;
        }
        .woocommerce ul.products li.product .lead-client-type {
            font-size: 12px;
        }
        .woocommerce ul.products li.product .lead-sku {
            font-size: 12px;
            color: #666;
        }
        .single-product .product_title {
            font-size: 1.5em;
            font-weight: bold;
        }
        .single-product .customer-details {
            margin-top: 20px;
        }
        .single-product .customer-details h3 {
            font-size: 1.2em;
            margin-bottom: 10px;
        }
        .single-product .customer-details table {
            width: 100%;
            border-collapse: collapse;
            display: table !important; /* Ensure table is not hidden */
        }
        .single-product .customer-details th,
        .single-product .customer-details td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .single-product .customer-details th {
            background-color: #f9f9f9;
            width: 30%;
        }
        .single-product .woocommerce-product-details__short-description {
            margin-bottom: 20px;
        }
        .single-product .product_meta {
            font-size: 0.9em;
            margin-top: 20px;
        }
        .single-product .product_meta .sku_wrapper,
        .single-product .product_meta .tagged_as {
            display: block;
            margin-bottom: 5px;
        }
        .single-product .quantity {
            display: block !important;
            visibility: visible !important;
            margin: 10px 0 !important;
        }
        .woocommerce-variation-add-to-cart .quantity {
            display: block !important;
        }
    ";
    wp_add_inline_style('wc-lead-generator', $custom_css);
}

add_action('wp_footer', 'wc_lead_reinit_variation_form');
function wc_lead_reinit_variation_form() {
    if (is_product()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.variations_form').each(function() {
                    $(this).wc_variation_form();
                    $(this).trigger('check_variations');
                    // Force quantity visibility
                    $('.woocommerce-variation-add-to-cart .quantity').css('display', 'block');
                });
                // Debug: Log when variations are checked
                $(document).on('woocommerce_variation_has_changed', function() {
                    console.log('Variation changed, checking quantity visibility');
                    $('.woocommerce-variation-add-to-cart .quantity').css('display', 'block');
                });
            });
        </script>
        <?php
    }
}

// Main shortcode for the lead submission form
add_shortcode('lead_submission_form', 'wc_lead_submission_form');
function wc_lead_submission_form() {
    if (isset($_POST['wc_lead_submit'])) {
        try {
            // Verify nonce for security
            if (!isset($_POST['lead_form_nonce']) || !wp_verify_nonce($_POST['lead_form_nonce'], 'submit_lead_form')) {
                throw new Exception(__('Security verification failed. Please try again.', 'wc-lead-generator'));
            }

            // Validate required fields
            $required_fields = [
                'project_title' => __('Project title', 'wc-lead-generator'),
                'project_description' => __('Project description', 'wc-lead-generator'),
                'nom' => __('Last name', 'wc-lead-generator'),
                'prenom' => __('First name', 'wc-lead-generator'),
                'email' => __('Email', 'wc-lead-generator'),
                'telephone' => __('Phone', 'wc-lead-generator'),
                'postal_code' => __('Postal code', 'wc-lead-generator'),
                'city' => __('City', 'wc-lead-generator'),
                'garden_type' => __('Garden type', 'wc-lead-generator'),
                'service_needed' => __('Service needed', 'wc-lead-generator'),
                'budget' => __('Budget', 'wc-lead-generator'),
                'garden_size' => __('Garden size', 'wc-lead-generator')
            ];

            foreach ($required_fields as $field => $field_name) {
                if (empty($_POST[$field])) {
                    throw new Exception(sprintf(__('%s is a required field.', 'wc-lead-generator'), $field_name));
                }
            }

            // Validate email
            if (!is_email($_POST['email'])) {
                throw new Exception(__('Please enter a valid email address.', 'wc-lead-generator'));
            }

            // Create the product
            $post_id = wp_insert_post([
                'post_title' => sanitize_text_field($_POST['project_title']),
                'post_content' => wp_kses_post($_POST['project_description']),
                'post_type' => 'product',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed'
            ]);

            if (is_wp_error($post_id)) {
                throw new Exception(__('Error creating product: ', 'wc-lead-generator') . $post_id->get_error_message());
            }

            // Set product as variable
            wp_set_object_terms($post_id, 'variable', 'product_type');

            // Set product properties
            update_post_meta($post_id, '_stock_status', 'instock');
            update_post_meta($post_id, '_visibility', 'visible');
            update_post_meta($post_id, '_virtual', 'yes');
            update_post_meta($post_id, '_sold_individually', 'yes');

            // Generate and set a custom SKU
            $sku = 'PDJ-N°' . str_pad($post_id, 8, '0', STR_PAD_LEFT);
            update_post_meta($post_id, '_sku', $sku);

            // Define attributes for variations (using a custom attribute, not taxonomy-based)
            $attribute_data = [
                'service-type' => [
                    'name' => 'Service Type',
                    'value' => 'Creation | Maintenance | Pruning | Watering System',
                    'position' => 0,
                    'is_visible' => 1,
                    'is_variation' => 1,
                    'is_taxonomy' => 0
                ]
            ];
            update_post_meta($post_id, '_product_attributes', $attribute_data);

            // Create variations based on the attribute
            $attribute_values = ['Creation', 'Maintenance', 'Pruning', 'Watering System'];
            foreach ($attribute_values as $index => $value) {
                $variation_data = [
                    'post_title' => 'Variation #' . $value . ' of ' . sanitize_text_field($_POST['project_title']),
                    'post_name' => 'product-' . $post_id . '-variation-' . strtolower($value),
                    'post_status' => 'publish',
                    'post_parent' => $post_id,
                    'post_type' => 'product_variation',
                    'comment_status' => 'closed'
                ];

                $variation_id = wp_insert_post($variation_data);
                if (is_wp_error($variation_id)) {
                    error_log('Variation creation error: ' . $variation_id->get_error_message());
                    continue;
                }

                // Set variation attributes
                update_post_meta($variation_id, 'attribute_service-type', $value);

                // Set variation price and stock status
                $price = ($index + 1) * 10; // Example: 10, 20, 30, 40 for each variation
                update_post_meta($variation_id, '_price', $price);
                update_post_meta($variation_id, '_regular_price', $price);
                update_post_meta($variation_id, '_stock_status', 'instock');
                update_post_meta($variation_id, '_manage_stock', 'no');
            }

            // Store lead information
            $meta_fields = [
                'nom' => 'lead_nom',
                'prenom' => 'lead_prenom',
                'email' => 'lead_email',
                'telephone' => 'lead_phone',
                'postal_code' => 'lead_postal_code',
                'city' => 'lead_city',
                'garden_type' => 'lead_garden_type',
                'service_needed' => 'lead_service_needed',
                'budget' => 'lead_budget',
                'garden_size' => 'lead_garden_size'
            ];

            foreach ($meta_fields as $field => $meta_key) {
                if (isset($_POST[$field])) {
                    $value = sanitize_text_field($_POST[$field]);
                    update_post_meta($post_id, $meta_key, $value);
                    error_log("Saving meta for product $post_id: $meta_key = $value");
                }
            }

            // Handle file upload and set as product image
            if (!empty($_FILES['garden_photo']['name'])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');

                $attachment_id = media_handle_upload('garden_photo', $post_id);
                if (!is_wp_error($attachment_id)) {
                    set_post_thumbnail($post_id, $attachment_id);
                    update_post_meta($post_id, 'lead_garden_photo', wp_get_attachment_url($attachment_id));
                } else {
                    error_log('File upload error: ' . $attachment_id->get_error_message());
                }
            }

            // Add to Leads category if it exists
            $leads_term = term_exists('Leads', 'product_cat');
            if ($leads_term !== 0 && $leads_term !== null) {
                wp_set_object_terms($post_id, (int)$leads_term['term_id'], 'product_cat');
            }

            // Success message
            echo "<div class='wc-lead-success'><p>" . __('Thank you, your project has been successfully submitted.', 'wc-lead-generator') . "</p></div>";

        } catch (Exception $e) {
            echo "<div class='wc-lead-error'><p>" . esc_html($e->get_message()) . "</p></div>";
            return;
        }
    }

    ob_start();
    ?>
    <div class="wc-lead-generator-form">
        <div class="wc-lead-header">
            <h1>Find a Landscaper near you!</h1>
            <p>Once your request is received, one of our experts will contact you by phone and adjust it with you!</p>
        </div>

        <div class="wc-lead-service-types">
            <h2>REGULAR REQUEST TYPE:</h2>
            <ul>
                <li>Landscaping creation (Payment in X installments without charge)</li>
                <li>One-off interview</li>
                <li>Pruning & Felling</li>
                <li>Deployment of an automatic watering system</li>
                <li>Monthly Maintenance (With immediate tax deduction)</li>
            </ul>
        </div>

        <form method="post" enctype="multipart/form-data" id="wc-lead-form">
            <?php wp_nonce_field('submit_lead_form', 'lead_form_nonce'); ?>
                   
            <div class="wc-lead-step active" id="step1">
                <h3>What would you like?</h3>
                
                <div class="form-group">
                    <label>What type of garden is it?</label>
                    <select name="garden_type" required>
                        <option value="">Select...</option>
                        <option value="Private Garden">Private Garden</option>
                        <option value="Public Garden">Public Garden</option>
                        <option value="Commercial Garden">Commercial Garden</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>What services do you need?</label>
                    <select name="service_needed" required>
                        <option value="">Select...</option>
                        <option value="Creation">Creation</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Pruning">Pruning & Felling</option>
                        <option value="Watering System">Automatic Watering System</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>What budgets do you have?</label>
                    <input type="text" name="budget" placeholder="€" required>
                </div>
                
                <div class="form-group">
                    <label>What is the size of your garden layout?</label>
                    <input type="number" name="garden_size" placeholder="m²" required>
                </div>
                
                <div class="form-group">
                    <label>Do you have a photo?</label>
                    <input type="file" name="garden_photo" accept="image/*">
                </div>
                
                <button type="button" class="wc-lead-next">Next</button>
            </div>
            
     
            <div class="wc-lead-step" id="step2">
                <h3>Contact Information:</h3>
                <div class="form-group">
                    <label>First Name:</label>
                    <input type="text" name="prenom" placeholder="First Name" required>
                </div>
                
                <div class="form-group">
                    <label>Last Name:</label>
                    <input type="text" name="nom" placeholder="Last Name" required>
                </div>
                
                <div class="form-group">
                    <label>Postal Code:</label>
                    <input type="text" name="postal_code" placeholder="Postal Code" required>
                </div>
                
                <div class="form-group">
                    <label>City:</label>
                    <input type="text" name="city" placeholder="City" required>
                </div>
                
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" placeholder="Email" required>
                </div>
                
                <div class="form-group">
                    <label>Phone:</label>
                    <input type="tel" name="telephone" placeholder="Phone" required>
                </div>
                
                <div class="form-group">
                    <label>Project Title:</label>
                    <input type="text" name="project_title" placeholder="Project Title" required>
                </div>
                
                <div class="form-group">
                    <label>Project Description:</label>
                    <textarea name="project_description" placeholder="Describe your project" required></textarea>
                </div>
                
                <div class="form-group">
                    <button type="button" class="wc-lead-prev">Previous</button>
                    <input type="submit" name="wc_lead_submit" value="Submit your request">
                </div>
            </div>
        </form>
        
        <div class="wc-lead-footer">
            <h3>More About info:</h3>
            <p>I'm sure it is help</p>
            <p>To meet up your needs</p>
            <p>Contact us: contact@monobojardin.fr - 04 65 84 77 80</p>
            <p>With the immediate advance tax credit of 50% via UISSAR. (For maintenance contracts)</p>
            <p>MY GARDEN SUBSCRIPTION©2025 – All rights reserved – photos and videos are not contractual.</p>
            <p>Site created by the communications agency</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Customize the shop loop to display custom product details
add_action('woocommerce_before_shop_loop_item', 'wc_lead_generator_custom_shop_display', 5);
function wc_lead_generator_custom_shop_display() {
    global $product;

    // Get custom meta fields
    $city = get_post_meta($product->get_id(), 'lead_city', true);
    $postal_code = get_post_meta($product->get_id(), 'lead_postal_code', true);
    $garden_type = get_post_meta($product->get_id(), 'lead_garden_type', true);
    $service_needed = get_post_meta($product->get_id(), 'lead_service_needed', true);

    // Location (e.g., "63 - PUY-DE-DÔME")
    $location = !empty($postal_code) ? substr($postal_code, 0, 2) . ' - ' . strtoupper($city) : strtoupper($city);
    echo '<div class="lead-location">' . esc_html($location) . '</div>';

    // Title (e.g., "Entretien haies - 63000 CLERMONT-FERRAND")
    $title = $service_needed . ' - ' . $postal_code . ' ' . strtoupper($city);
    echo '<div class="lead-title">' . esc_html($title) . '</div>';

    // Price range (already handled by WooCommerce for variable products)
    echo '<div class="lead-price">';
    woocommerce_template_loop_price();
    echo ' HT</div>';

    // Stock status
    echo '<div class="lead-stock">';
    if (!$product->is_in_stock()) {
        echo '<span class="out-of-stock">Rupture de stock</span>';
    }
    echo '</div>';

    // Client type (hardcoded as "Particulier" for now)
    echo '<div class="lead-client-type">Type de Client: Particulier</div>';

    // Garden type (e.g., "Type de jardin: Jardin en copropriété")
    echo '<div class="lead-details">';
    echo '<div>Type de jardin: ' . esc_html($garden_type) . '</div>';

    // SKU
    $sku = $product->get_sku();
    if ($sku) {
        echo '<div class="lead-sku">SKU: ' . esc_html($sku) . '</div>';
    }
    echo '</div>';
}

// Remove default title and price to avoid duplication
remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);

// Add custom tab for customer details on single product page
add_filter('woocommerce_product_tabs', 'wc_lead_add_customer_details_tab');
function wc_lead_add_customer_details_tab($tabs) {
    $tabs['customer_details'] = [
        'title' => __('Customer Details', 'wc-lead-generator'),
        'priority' => 20,
        'callback' => 'wc_lead_customer_details_tab_content'
    ];
    return $tabs;
}

function wc_lead_customer_details_tab_content() {
    global $product;

    // Debug: Confirm this function is called
    error_log("Customer Details tab content called for product ID: " . $product->get_id());

    // Get form data
    $prenom = get_post_meta($product->get_id(), 'lead_prenom', true);
    $nom = get_post_meta($product->get_id(), 'lead_nom', true);
    $email = get_post_meta($product->get_id(), 'lead_email', true);
    $telephone = get_post_meta($product->get_id(), 'lead_phone', true);
    $postal_code = get_post_meta($product->get_id(), 'lead_postal_code', true);
    $city = get_post_meta($product->get_id(), 'lead_city', true);
    $garden_type = get_post_meta($product->get_id(), 'lead_garden_type', true);
    $service_needed = get_post_meta($product->get_id(), 'lead_service_needed', true);
    $budget = get_post_meta($product->get_id(), 'lead_budget', true);
    $garden_size = get_post_meta($product->get_id(), 'lead_garden_size', true);

    // Debug: Log all meta values to check if they are retrieved
    $all_meta = get_post_meta($product->get_id());
    error_log("All meta for product " . $product->get_id() . " on single product page: " . print_r($all_meta, true));

    // Check if the user has purchased the product
    $current_user = wp_get_current_user();
    $purchased = wc_customer_bought_product($current_user->user_email, $current_user->ID, $product->get_id());

    echo '<div class="customer-details">';
    echo '<h3>' . __('Customer Details', 'wc-lead-generator') . '</h3>';
    echo '<table>';
    
    // Always visible fields
    echo '<tr><th>' . __('Customer Type', 'wc-lead-generator') . '</th><td>Individual</td></tr>';
    echo '<tr><th>' . __('Type of Garden', 'wc-lead-generator') . '</th><td>' . esc_html($garden_type ?: 'N/A') . '</td></tr>';
    echo '<tr><th>' . __('Type of Service Desired', 'wc-lead-generator') . '</th><td>' . esc_html($service_needed ?: 'N/A') . '</td></tr>';
    echo '<tr><th>' . __('Surface Area (in m²)', 'wc-lead-generator') . '</th><td>' . esc_html($garden_size ?: 'N/A') . '</td></tr>';
    echo '<tr><th>' . __('Tree Height for Felling/Pruning', 'wc-lead-generator') . '</th><td>N/A</td></tr>';
    echo '<tr><th>' . __('Easy Access?', 'wc-lead-generator') . '</th><td>Yes</td></tr>';
    echo '<tr><th>' . __('Location', 'wc-lead-generator') . '</th><td>' . esc_html($city ?: 'N/A') . ' (' . esc_html($postal_code ?: 'N/A') . ')</td></tr>';
    echo '<tr><th>' . __('Budget', 'wc-lead-generator') . '</th><td>' . esc_html($budget ?: 'N/A') . '€</td></tr>';
    echo '<tr><th>' . __('Lead Type', 'wc-lead-generator') . '</th><td>Standard (Shared between 3 Pros), Exclusive (YOU only)</td></tr>';
    echo '<tr><th>' . __('Department', 'wc-lead-generator') . '</th><td>' . esc_html(substr($postal_code, 0, 2) ?: 'N/A') . ' - ' . strtoupper($city ?: 'N/A') . '</td></tr>';

    // Sensitive fields (visible only after purchase)
    if ($purchased) {
        echo '<tr><th>' . __('First Name', 'wc-lead-generator') . '</th><td>' . esc_html($prenom ?: 'N/A') . '</td></tr>';
        echo '<tr><th>' . __('Last Name', 'wc-lead-generator') . '</th><td>' . esc_html($nom ?: 'N/A') . '</td></tr>';
        echo '<tr><th>' . __('Email', 'wc-lead-generator') . '</th><td>' . esc_html($email ?: 'N/A') . '</td></tr>';
        echo '<tr><th>' . __('Phone', 'wc-lead-generator') . '</th><td>' . esc_html($telephone ?: 'N/A') . '</td></tr>';
    } else {
        echo '<tr><th colspan="2">' . __('Contact information available after purchase.', 'wc-lead-generator') . '</th></tr>';
    }

    echo '</table>';
    echo '</div>';
}

// Customize the single product page title
add_filter('the_title', 'wc_lead_custom_product_title', 10, 2);
function wc_lead_custom_product_title($title, $id) {
    if (is_single() && get_post_type($id) === 'product') {
        $city = get_post_meta($id, 'lead_city', true);
        $postal_code = get_post_meta($id, 'lead_postal_code', true);
        $service_needed = get_post_meta($id, 'lead_service_needed', true);
        $title = $service_needed . ' - ' . $postal_code . ' ' . strtoupper($city);
    }
    return $title;
}

// Ensure SKU and tags are displayed on the single product page
add_action('woocommerce_single_product_summary', 'wc_lead_display_product_meta', 40);
function wc_lead_display_product_meta() {
    global $product;

    echo '<div class="product_meta">';
    if ($sku = $product->get_sku()) {
        echo '<span class="sku_wrapper">' . __('SKU:', 'woocommerce') . ' <span class="sku">' . esc_html($sku) . '</span></span>';
    }

    $tags = get_the_terms($product->get_id(), 'product_tag');
    if ($tags && !is_wp_error($tags)) {
        $tag_names = wp_list_pluck($tags, 'name');
        echo '<span class="tagged_as">' . __('Tags:', 'woocommerce') . ' ' . esc_html(implode(', ', $tag_names)) . '</span>';
    }
    echo '</div>';
}

// Add custom meta box to product edit page for admin
add_action('add_meta_boxes', 'wc_lead_add_admin_meta_box');
function wc_lead_add_admin_meta_box() {
    add_meta_box(
        'wc_lead_admin_details',
        __('Lead Details', 'wc-lead-generator'),
        'wc_lead_admin_details_callback',
        'product',
        'normal',
        'default'
    );
}

function wc_lead_admin_details_callback($post) {
    // Check user capability to ensure only admins can view
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    // Get the lead email
    $email = get_post_meta($post->ID, 'lead_email', true);

    // Output the email
    echo '<div class="wc-lead-admin-details">';
    echo '<h3>' . __('Lead Contact Information', 'wc-lead-generator') . '</h3>';
    echo '<p><strong>' . __('Email:', 'wc-lead-generator') . '</strong> ' . esc_html($email ?: 'N/A') . '</p>';
    echo '</div>';
}

// Send contact info to buyer after purchase
add_action('woocommerce_order_status_completed', 'wc_lead_send_contact_info_to_buyer');
function wc_lead_send_contact_info_to_buyer($order_id) {
    $order = wc_get_order($order_id);
    $user_email = $order->get_billing_email();
    $items = $order->get_items();

    $message = "Merci pour votre achat. Voici les informations du lead :\n\n";

    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        $message .= "Projet : " . get_the_title($product_id) . "\n";
        $message .= "Nom : " . get_post_meta($product_id, 'lead_nom', true) . "\n";
        $message .= "Prénom : " . get_post_meta($product_id, 'lead_prenom', true) . "\n";
        $message .= "Email : " . get_post_meta($product_id, 'lead_email', true) . "\n";
        $message .= "Téléphone : " . get_post_meta($product_id, 'lead_phone', true) . "\n\n";
    }

    wp_mail($user_email, 'Votre lead acheté', $message);
}