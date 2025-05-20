<?php
/*
Plugin Name: Générateur de Leads WooCommerce
Description: Permet aux particuliers de soumettre un projet qui devient un produit WooCommerce, avec des informations masquées jusqu'à l'achat.
Version: 1.7
Author: Ali Zaib
*/

if (!defined('ABSPATH')) exit;

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', 'wc_lead_generator_scripts');
function wc_lead_generator_scripts() {
    wp_enqueue_style('wc-lead-generator', plugins_url('wc-lead-generator.css', __FILE__));
    wp_enqueue_script('wc-lead-generator', plugins_url('wc-lead-generator.js', __FILE__), array('jquery'), '1.1', true);
    wp_enqueue_style('jua-font', 'https://fonts.googleapis.com/css2?family=Jua&display=swap', array(), null);
    
    // Ensure WooCommerce scripts are loaded
    if (is_product() || is_shop()) {
        wp_enqueue_script('wc-add-to-cart-variation');
        wp_enqueue_script('woocommerce');
    }
}

// Main shortcode for the lead submission form
add_shortcode('lead_submission_form', 'wc_lead_submission_form');
function wc_lead_submission_form() {
    // Check if form was submitted successfully
    if (isset($_GET['lead_submitted']) && $_GET['lead_submitted'] === 'success') {
        ob_start();
        ?>
        <div class="wc-lead-success">
            <p><?php _e('Votre demande a été soumise avec succès.', 'wc-lead-generator'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    if (isset($_POST['wc_lead_submit'])) {
        try {
            // Verify nonce
            if (!isset($_POST['lead_form_nonce']) || !wp_verify_nonce($_POST['lead_form_nonce'], 'submit_lead_form')) {
                throw new Exception(__('Vérification de sécurité échouée. Veuillez réessayer.', 'wc-lead-generator'));
            }

            // Validate required fields
            $required_fields = [
                'project_title' => __('Titre du projet', 'wc-lead-generator'),
                'project_description' => __('Description du projet', 'wc-lead-generator'),
                'nom' => __('Nom', 'wc-lead-generator'),
                'prenom' => __('Prénom', 'wc-lead-generator'),
                'email' => __('Email', 'wc-lead-generator'),
                'telephone' => __('Téléphone', 'wc-lead-generator'),
                'postal_code' => __('Code postal', 'wc-lead-generator'),
                'city' => __('Ville', 'wc-lead-generator'),
                'garden_type' => __('Type de jardin', 'wc-lead-generator'),
                'service_needed' => __('Service requis', 'wc-lead-generator'),
                'budget' => __('Budget', 'wc-lead-generator'),
                'garden_size' => __('Taille du jardin', 'wc-lead-generator')
            ];

            foreach ($required_fields as $field => $field_name) {
                if (empty($_POST[$field])) {
                    throw new Exception(sprintf(__('%s est un champ requis.', 'wc-lead-generator'), $field_name));
                }
            }

            // Validate service_needed (category ID)
            $service_needed = absint($_POST['service_needed']);
            $category = get_term($service_needed, 'product_cat');
            if (!$service_needed || is_wp_error($category) || !$category) {
                throw new Exception(__('Erreur : Catégorie de service invalide sélectionnée.', 'wc-lead-generator'));
            }
            $service_name = $category->name;

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
                throw new Exception(__('Erreur lors de la création du produit : ', 'wc-lead-generator') . $post_id->get_error_message());
            }

            // Set product as variable
            wp_set_object_terms($post_id, 'variable', 'product_type');

            // Set product properties
            update_post_meta($post_id, '_stock_status', 'instock');
            update_post_meta($post_id, '_visibility', 'visible');
            update_post_meta($post_id, '_virtual', 'yes');
            update_post_meta($post_id, '_sold_individually', 'yes');
            update_post_meta($post_id, '_manage_stock', 'no');
            update_post_meta($post_id, '_backorders', 'no');

            // Generate and set a custom SKU
            $sku = 'PDJ-N°' . str_pad($post_id, 8, '0', STR_PAD_LEFT);
            update_post_meta($post_id, '_sku', $sku);

            // Assign selected category
            wp_set_object_terms($post_id, $service_needed, 'product_cat');

            // Add to Leads category
            $leads_term = term_exists('Leads', 'product_cat');
            if ($leads_term !== 0 && $leads_term !== null) {
                wp_set_object_terms($post_id, (int)$leads_term['term_id'], 'product_cat', true);
            }

            // Create and set product attributes for variations
            $attribute_name = 'lead-type';
            $attribute_label = __('Type de Lead', 'wc-lead-generator');
            $attribute_values = ['standard' => __('Standard (Partagé entre 3 Pros)', 'wc-lead-generator'), 'exclusif' => __('Exclusif (Vous uniquement)', 'wc-lead-generator')];

            $product_attributes = [
                'pa_' . $attribute_name => [
                    'name' => 'pa_' . $attribute_name,
                    'value' => '',
                    'position' => 0,
                    'is_visible' => 1,
                    'is_variation' => 1,
                    'is_taxonomy' => 1
                ]
            ];
            update_post_meta($post_id, '_product_attributes', $product_attributes);

            // Register taxonomy and terms
            $taxonomy = 'pa_' . $attribute_name;
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, 'product', [
                    'labels' => ['name' => $attribute_label],
                    'hierarchical' => false,
                    'show_ui' => false,
                    'query_var' => true,
                    'rewrite' => false,
                ]);
            }

            foreach ($attribute_values as $slug => $name) {
                if (!term_exists($slug, $taxonomy)) {
                    wp_insert_term($name, $taxonomy, ['slug' => $slug]);
                }
            }
            wp_set_object_terms($post_id, array_keys($attribute_values), $taxonomy);

            // Set default attributes
            update_post_meta($post_id, '_default_attributes', ['pa_' . $attribute_name => 'standard']);

            // Create variations
            foreach ($attribute_values as $slug => $name) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($post_id);
                $variation->set_attributes(['pa_' . $attribute_name => $slug]);
                $base_price = 10;
                $price = $slug === 'exclusif' ? $base_price * 1.5 : $base_price;
                $variation->set_regular_price($price);
                $variation->set_sale_price($price);
                $variation->set_price($price);
                $variation->set_status('publish');
                $variation->set_virtual(true);
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
                $variation_id = $variation->save();

                // Ensure variation metadata
                update_post_meta($variation_id, '_regular_price', $price);
                update_post_meta($variation_id, '_price', $price);
                update_post_meta($variation_id, '_virtual', 'yes');
                update_post_meta($variation_id, '_stock_status', 'instock');
                update_post_meta($variation_id, 'attribute_pa_' . $attribute_name, $slug);
            }

            // Sync product and clear caches
            $product = wc_get_product($post_id);
            if ($product && $product->is_type('variable')) {
                WC_Product_Variable::sync($post_id);
                wc_delete_product_transients($post_id);
                delete_transient('wc_product_children_' . $post_id);
                $product->save();
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
                    $value = $field === 'service_needed' ? $service_name : sanitize_text_field($_POST[$field]);
                    update_post_meta($post_id, $meta_key, $value);
                }
            }

            // Handle file upload
            if (!empty($_FILES['garden_photo']['name'])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');

                $attachment_id = media_handle_upload('garden_photo', $post_id);
                if (!is_wp_error($attachment_id)) {
                    set_post_thumbnail($post_id, $attachment_id);
                    update_post_meta($post_id, 'lead_garden_photo', wp_get_attachment_url($attachment_id));
                }
            }

            // Redirect to show success message
            wp_redirect(add_query_arg('lead_submitted', 'success', wp_get_referer()));
            exit;

        } catch (Exception $e) {
            ob_start();
            ?>
            <div class="wc-lead-error">
                <p><?php echo esc_html($e->getMessage()); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }
    }

    ob_start();
    ?>
    <div class="wc-lead-generator-form">
        <form method="post" enctype="multipart/form-data" id="wc-lead-form">
            <?php wp_nonce_field('submit_lead_form', 'lead_form_nonce'); ?>
            
            <div class="wc-lead-step active" id="step1">
                <h3><?php _e('Que souhaitez-vous ?', 'wc-lead-generator'); ?></h3>
                
                <div class="form-group">
                    <label><?php _e('Quel type de jardin est-ce ?', 'wc-lead-generator'); ?></label>
                    <select name="garden_type" required>
                        <option value=""><?php _e('Sélectionner...', 'wc-lead-generator'); ?></option>
                        <option value="Jardin Privé"><?php _e('Jardin Privé', 'wc-lead-generator'); ?></option>
                        <option value="Jardin Public"><?php _e('Jardin Public', 'wc-lead-generator'); ?></option>
                        <option value="Jardin Commercial"><?php _e('Jardin Commercial', 'wc-lead-generator'); ?></option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Quels services avez-vous besoin ?', 'wc-lead-generator'); ?></label>
                    <select name="service_needed" required>
                        <option value=""><?php _e('Sélectionner...', 'wc-lead-generator'); ?></option>
                        <?php
                        $categories = get_terms([
                            'taxonomy' => 'product_cat',
                            'hide_empty' => false,
                            'orderby' => 'name',
                            'order' => 'ASC',
                        ]);
                        if (!is_wp_error($categories) && !empty($categories)) {
                            foreach ($categories as $category) {
                                echo '<option value="' . esc_attr($category->term_id) . '">' . esc_html($category->name) . '</option>';
                            }
                        } else {
                            echo '<option value="" disabled>' . __('Aucune catégorie disponible', 'wc-lead-generator') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Quel est votre budget ?', 'wc-lead-generator'); ?></label>
                    <input type="number" name="budget" placeholder="€" required min="500" max="5000" step="50">
                </div>
                
                <div class="form-group">
                    <label><?php _e('Quelle est la taille de votre jardin ?', 'wc-lead-generator'); ?></label>
                    <input type="number" name="garden_size" placeholder="m²" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Avez-vous des photos / vidéos à nous transmettre ? ( in plurial please )', 'wc-lead-generator'); ?></label>
                    <input type="file" name="garden_photo" accept="image/*">
                </div>
                
                <button type="button" class="wc-lead-next"><?php _e('Suivant', 'wc-lead-generator'); ?></button>
            </div>
            
            <div class="wc-lead-step" id="step2">
                <h3><?php _e('Informations de contact', 'wc-lead-generator'); ?> :</h3>
                
                <div class="form-group">
                    <label><?php _e('Prénom', 'wc-lead-generator'); ?> :</label>
                    <input type="text" name="prenom" placeholder="<?php _e('Prénom', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Nom', 'wc-lead-generator'); ?> :</label>
                    <input type="text" name="nom" placeholder="<?php _e('Nom', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Code postal', 'wc-lead-generator'); ?> :</label>
                    <input type="text" name="postal_code" placeholder="<?php _e('Code postal', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Ville', 'wc-lead-generator'); ?> :</label>
                    <input type="text" name="city" placeholder="<?php _e('Ville', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Email', 'wc-lead-generator'); ?> :</label>
                    <input type="email" name="email" placeholder="<?php _e('Email', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Téléphone', 'wc-lead-generator'); ?> :</label>
                    <input type="tel" name="telephone" placeholder="<?php _e('Téléphone', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Titre du projet', 'wc-lead-generator'); ?> :</label>
                    <input type="text" name="project_title" placeholder="<?php _e('Titre du projet', 'wc-lead-generator'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?php _e('Description du projet', 'wc-lead-generator'); ?> :</label>
                    <textarea name="project_description" placeholder="<?php _e('Décrivez votre projet', 'wc-lead-generator'); ?>" required></textarea>
                </div>
                
                <div class="form-group">
                    <button type="button" class="wc-lead-prev"><?php _e('Précédent', 'wc-lead-generator'); ?></button>
                    <input type="submit" name="wc_lead_submit" value="<?php _e('Soumettre votre demande', 'wc-lead-generator'); ?>">
                </div>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// Customize the shop loop
add_action('woocommerce_before_shop_loop_item', 'wc_lead_generator_custom_shop_display', 5);
function wc_lead_generator_custom_shop_display() {
    global $product;

    $city = get_post_meta($product->get_id(), 'lead_city', true);
    $postal_code = get_post_meta($product->get_id(), 'lead_postal_code', true);
    $garden_type = get_post_meta($product->get_id(), 'lead_garden_type', true);
    $service_needed = get_post_meta($product->get_id(), 'lead_service_needed', true);

    $location = !empty($postal_code) ? substr($postal_code, 0, 2) . ' - ' . strtoupper($city) : strtoupper($city);
    echo '<div class="lead-location">' . esc_html($location) . '</div>';

    $title = $service_needed . ' - ' . $postal_code . ' ' . strtoupper($city);
    echo '<div class="lead-title">' . esc_html($title) . '</div>';

    echo '<div class="lead-price">';
    woocommerce_template_loop_price();
    echo ' HT</div>';

    echo '<div class="lead-stock">';
    if (!$product->is_in_stock()) {
        echo '<span class="out-of-stock">' . __('Rupture de stock', 'wc-lead-generator') . '</span>';
    }
    echo '</div>';

    echo '<div class="lead-client-type">' . __('Type de Client : Particulier', 'wc-lead-generator') . '</div>';

    echo '<div class="lead-details">';
    echo '<div>' . __('Type de jardin : ', 'wc-lead-generator') . esc_html($garden_type) . '</div>';

    $sku = $product->get_sku();
    if ($sku) {
        echo '<div class="lead-sku">' . __('SKU : ', 'wc-lead-generator') . esc_html($sku) . '</div>';
    }
    echo '</div>';
}

remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);

// Add customer details tab
add_filter('woocommerce_product_tabs', 'wc_lead_add_customer_details_tab');
function wc_lead_add_customer_details_tab($tabs) {
    $tabs['customer_details'] = [
        'title' => __('Détails du Client', 'wc-lead-generator'),
        'priority' => 20,
        'callback' => 'wc_lead_customer_details_tab_content'
    ];
    return $tabs;
}

function wc_lead_customer_details_tab_content() {
    global $product;

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

    $current_user = wp_get_current_user();
    $purchased = wc_customer_bought_product($current_user->user_email, $current_user->ID, $product->get_id());

    echo '<div class="customer-details">';
    echo '<h3>' . __('Détails du Client', 'wc-lead-generator') . '</h3>';
    echo '<table>';
    
    echo '<tr><th>' . __('Type de Client', 'wc-lead-generator') . '</th><td>' . __('Particulier', 'wc-lead-generator') . '</td></tr>';
    echo '<tr><th>' . __('Type de Jardin', 'wc-lead-generator') . '</th><td>' . esc_html($garden_type ?: 'N/A') . '</td></tr>';
    echo '<tr><th>' . __('Type de Service Souhaité', 'wc-lead-generator') . '</th><td>' . esc_html($service_needed ?: 'N/A') . '</td></tr>';
    echo '<tr><th>' . __('Surface (en m²)', 'wc-lead-generator') . '</th><td>' . esc_html($garden_size ?: 'N/A') . '</td></tr>';
    echo '<tr><th>' . __('Localisation', 'wc-lead-generator') . '</th><td>' . esc_html($city ?: 'N/A') . ' (' . esc_html($postal_code ?: 'N/A') . ')</td></tr>';
    echo '<tr><th>' . __('Budget', 'wc-lead-generator') . '</th><td>' . esc_html($budget ?: 'N/A') . '€</td></tr>';

    if ($purchased) {
        echo '<tr><th>' . __('Prénom', 'wc-lead-generator') . '</th><td>' . esc_html($prenom ?: 'N/A') . '</td></tr>';
        echo '<tr><th>' . __('Nom', 'wc-lead-generator') . '</th><td>' . esc_html($nom ?: 'N/A') . '</td></tr>';
        echo '<tr><th>' . __('Email', 'wc-lead-generator') . '</th><td>' . esc_html($email ?: 'N/A') . '</td></tr>';
        echo '<tr><th>' . __('Téléphone', 'wc-lead-generator') . '</th><td>' . esc_html($telephone ?: 'N/A') . '</td></tr>';
    } else {
        echo '<tr><th colspan="2">' . __('Les informations de contact sont disponibles après l\'achat.', 'wc-lead-generator') . '</th></tr>';
    }

    echo '</table>';
    echo '</div>';
}

// Customize product title
add_filter('the_title', 'wc_lead_custom_product_title', 10, 2);
function wc_lead_custom_product_title($title, $id) {
    if (is_single() && get_post_type($id) === 'product') {
        $city = get_post_meta($id, 'lead_city', true);
        $postal_code = get_post_meta($id, 'lead_postal_code', true);
        $service_needed = get_post_meta($id, 'lead_service_needed', true);
        
        if (!empty($city) && !empty($postal_code) && !empty($service_needed)) {
            $title = $service_needed . ' - ' . $postal_code . ' ' . strtoupper($city);
        }
    }
    return $title;
}

// Display product meta
add_action('woocommerce_single_product_summary', 'wc_lead_display_product_meta', 40);
function wc_lead_display_product_meta() {
    global $product;

    echo '<div class="product_meta">';
    if ($sku = $product->get_sku()) {
        echo '<span class="sku_wrapper">' . __('SKU :', 'woocommerce') . ' <span class="sku">' . esc_html($sku) . '</span></span>';
    }

    $tags = get_the_terms($product->get_id(), 'product_tag');
    if ($tags && !is_wp_error($tags)) {
        $tag_names = wp_list_pluck($tags, 'name');
        echo '<span class="tagged_as">' . __('Étiquettes :', 'woocommerce') . ' ' . esc_html(implode(', ', $tag_names)) . '</span>';
    }
    echo '</div>';
}

// Add admin meta box
add_action('add_meta_boxes', 'wc_lead_add_admin_meta_box');
function wc_lead_add_admin_meta_box() {
    add_meta_box(
        'wc_lead_admin_details',
        __('Détails du Lead', 'wc-lead-generator'),
        'wc_lead_admin_details_callback',
        'product',
        'normal',
        'default'
    );
}

function wc_lead_admin_details_callback($post) {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $email = get_post_meta($post->ID, 'lead_email', true);
    $phone = get_post_meta($post->ID, 'lead_phone', true);
    $nom = get_post_meta($post->ID, 'lead_nom', true);
    $prenom = get_post_meta($post->ID, 'lead_prenom', true);

    echo '<div class="wc-lead-admin-details">';
    echo '<h3>' . __('Informations de Contact du Lead', 'wc-lead-generator') . '</h3>';
    echo '<p><strong>' . __('Nom :', 'wc-lead-generator') . '</strong> ' . esc_html($nom ?: 'N/A') . '</p>';
    echo '<p><strong>' . __('Prénom :', 'wc-lead-generator') . '</strong> ' . esc_html($prenom ?: 'N/A') . '</p>';
    echo '<p><strong>' . __('Email :', 'wc-lead-generator') . '</strong> ' . esc_html($email ?: 'N/A') . '</p>';
    echo '<p><strong>' . __('Téléphone :', 'wc-lead-generator') . '</strong> ' . esc_html($phone ?: 'N/A') . '</p>';
    echo '</div>';
}

// Send contact info after purchase
add_action('woocommerce_order_status_completed', 'wc_lead_send_contact_info_to_buyer');
function wc_lead_send_contact_info_to_buyer($order_id) {
    $order = wc_get_order($order_id);
    $user_email = $order->get_billing_email();
    $items = $order->get_items();

    $message = __("Merci pour votre achat. Voici les informations du lead :\n\n", 'wc-lead-generator');

    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        $message .= __("Projet : ", 'wc-lead-generator') . get_the_title($product_id) . "\n";
        $message .= __("Nom : ", 'wc-lead-generator') . get_post_meta($product_id, 'lead_nom', true) . "\n";
        $message .= __("Prénom : ", 'wc-lead-generator') . get_post_meta($product_id, 'lead_prenom', true) . "\n";
        $message .= __("Email : ", 'wc-lead-generator') . get_post_meta($product_id, 'lead_email', true) . "\n";
        $message .= __("Téléphone : ", 'wc-lead-generator') . get_post_meta($product_id, 'lead_phone', true) . "\n\n";
    }

    wp_mail($user_email, __('Votre lead acheté', 'wc-lead-generator'), $message);
}

// Fix variation display issues
add_action('wp_footer', 'wc_lead_reinit_variation_form');
function wc_lead_reinit_variation_form() {
    if (is_product()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var $form = $('.variations_form');
                if ($form.length && typeof $.fn.wc_variation_form === 'function') {
                    $form.wc_variation_form();
                    $form.trigger('check_variations');
                    $form.find('.variations select').each(function() {
                        if ($(this).val() === '') {
                            $(this).val($(this).find('option:not([value=""])').first().val()).change();
                        }
                    });
                    $('.woocommerce-variation-add-to-cart, .quantity').css({
                        'display': 'block',
                        'visibility': 'visible',
                        'opacity': 1
                    });
                } else {
                    console.error('WooCommerce variation form script not loaded or form not found!');
                }
            });
        </script>
        <?php
    }
}

// Ensure product data is saved properly
add_action('woocommerce_process_product_meta_variable', 'wc_lead_save_variable_product', 10, 1);
function wc_lead_save_variable_product($post_id) {
    $product = wc_get_product($post_id);
    if ($product && $product->is_type('variable')) {
        $product->save();
        wc_delete_product_transients($post_id);
        WC_Cache_Helper::get_transient_version('product', true);
    }
}
?>